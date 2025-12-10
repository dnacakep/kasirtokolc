<?php

if (!function_exists('ensure_csrf_token')) {
    require_once __DIR__ . '/../config/auth.php';
    require_once __DIR__ . '/../includes/fungsi.php';
}

require_once __DIR__ . '/../includes/approval_helpers.php';

ensure_csrf_token();

$pdo = get_db_connection();
ensure_stock_request_schema($pdo);

?>
<style>
    .autocomplete-wrapper {
        position: relative;
    }
    .autocomplete-results {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: #fff;
        border: 1px solid #cbd5e1;
        border-radius: 0 0 6px 6px;
        max-height: 240px;
        overflow-y: auto;
        z-index: 50;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    }
    .autocomplete-results[hidden] {
        display: none;
    }
    .autocomplete-item {
        padding: 0.6rem 0.75rem;
        cursor: pointer;
        border-bottom: 1px solid #f1f5f9;
        font-size: 0.95rem;
    }
    .autocomplete-item:last-child {
        border-bottom: none;
    }
    .autocomplete-item:hover {
        background-color: #f8fafc;
        color: #2563eb;
    }
</style>
<?php


$currentUser = current_user();
$canDecideStockRequests = $currentUser && in_array($currentUser['role'], [ROLE_MANAJER, ROLE_ADMIN], true);

$pendingStockRequests = fetch_stock_adjustment_requests($pdo, 'pending', 50);
$historyStockRequests = array_filter(
    fetch_stock_adjustment_requests($pdo, null, 150),
    static function ($row) {
        return $row['status'] !== 'pending';
    }
);

$batchSearch = trim($_GET['batch_search'] ?? '');
$batchProduct = $_GET['batch_product'] ?? '';

$batchConditions = [];
$batchParams = [];

if ($batchSearch !== '') {
    $batchConditions[] = "(p.name LIKE :batch_keyword OR p.barcode LIKE :batch_keyword OR b.batch_code LIKE :batch_keyword)";
    $batchParams[':batch_keyword'] = '%' . $batchSearch . '%';
}

if ($batchProduct !== '' && $batchProduct !== 'all') {
    $batchConditions[] = "b.product_id = :batch_product_id";
    $batchParams[':batch_product_id'] = (int) $batchProduct;
}

$batchQuery = "
    SELECT b.*, p.name AS product_name, s.name AS supplier_name
    FROM product_batches b
    INNER JOIN products p ON p.id = b.product_id
    LEFT JOIN suppliers s ON s.id = b.supplier_id
";

if ($batchConditions) {
    $batchQuery .= ' WHERE ' . implode(' AND ', $batchConditions);
}

$batchQuery .= " ORDER BY b.received_at DESC";

if (!$batchConditions) {
    $batchQuery .= " LIMIT 50";
}

$stmtBatches = $pdo->prepare($batchQuery);
$stmtBatches->execute($batchParams);
$batches = $stmtBatches->fetchAll();

$products = $pdo->query("SELECT id, name, barcode FROM products ORDER BY name ASC")->fetchAll();
$suppliers = $pdo->query("SELECT id, name FROM suppliers ORDER BY name ASC")->fetchAll();
$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name ASC")->fetchAll();

$productCatalog = array_map(function ($product) {
    return [
        'id' => (int) $product['id'],
        'name' => $product['name'],
        'barcode' => $product['barcode'],
    ];
}, $products);

$prefillProductId = isset($_GET['product_id']) ? (int) $_GET['product_id'] : null;
if ($prefillProductId <= 0) {
    $prefillProductId = null;
}

$prefillBarcode = trim($_GET['barcode'] ?? '');
if ($prefillBarcode === '') {
    $prefillBarcode = null;
}

$nextBatchCode = null;
try {
    $autoIncrementStmt = $pdo->query("
        SELECT AUTO_INCREMENT
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'product_batches'
        LIMIT 1
    ");
    $nextId = (int) ($autoIncrementStmt->fetchColumn() ?: 0);
    if ($nextId > 0) {
        $nextBatchCode = sprintf('BATCH-%06d', $nextId);
    }
} catch (Throwable $e) {
    $nextBatchCode = 'BATCH-' . date('ymdHis');
}

if (!$nextBatchCode) {
    $nextBatchCode = 'BATCH-' . date('ymdHis');
}

?>

<div class="grid-2">
    <section class="card">
        <h2>Tambah Batch Stok</h2>
        <form id="stock-form" method="post" action="<?= BASE_URL ?>/actions/tambah_stok.php">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <div class="form-group">
                <label for="product-search-input">Cari Produk (Nama atau Barcode)</label>
                <div class="autocomplete-wrapper">
                    <div class="barcode-input-wrapper">
                        <input type="text" id="product-search-input" placeholder="Ketik nama produk atau scan barcode" autocomplete="off">
                        <button class="button secondary scan-button" type="button" data-scan-target="product-search-input" aria-label="Scan barcode menggunakan kamera">
                            <span aria-hidden="true">&#128247;</span>
                        </button>
                    </div>
                    <input type="hidden" id="selected_product_id" name="product_id" required>
                    <div id="product-search-results" class="autocomplete-results"></div>
                </div>
                <p class="barcode-feedback" id="product-search-feedback" hidden>Produk tidak ditemukan.</p>
            </div>
            <div class="form-group">
                <label for="supplier_id">Pemasok</label>
                <select id="supplier_id" name="supplier_id">
                    <option value="">-- Pilih Pemasok (opsional) --</option>
                    <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?= $supplier['id'] ?>"><?= sanitize($supplier['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="batch_code">Kode Batch</label>
                <input type="text" id="batch_code" name="batch_code" value="<?= sanitize($nextBatchCode) ?>" required>
            </div>
            <div class="grid-2">
                <div class="form-group">
                    <label for="stock_in">Jumlah Masuk</label>
                    <input type="number" id="stock_in" name="stock_in" min="1" required>
                </div>
                <div class="form-group">
                    <label for="purchase_price">Harga Beli</label>
                    <input type="number" id="purchase_price" name="purchase_price" min="0" step="0.01" required>
                </div>
            </div>
            <div class="grid-2">
                <div class="form-group">
                    <label for="sell_price">Harga Jual</label>
                    <input type="number" id="sell_price" name="sell_price" min="0" step="0.01" required>
                    <p class="muted" id="sell_price_hint" data-default-hint="Harga rekomendasi otomatis: untung maksimal 10% atau Rp 500 dan dibulatkan ke kelipatan Rp 100."></p>
                </div>
                <div class="form-group">
                    <label for="expiry_date">Tanggal Kadaluarsa</label>
                    <input type="text" id="expiry_date" name="expiry_date" placeholder="DDMMYY" maxlength="6" inputmode="numeric" pattern="[0-9]*">
                </div>
            </div>
            <div class="form-group">
                <label for="received_at">Tanggal Masuk</label>
                <input type="datetime-local" id="received_at" name="received_at" value="<?= date('Y-m-d\TH:i') ?>">
            </div>
            <button class="button" type="submit">Simpan Batch</button>
        </form>
    </section>

    <section class="card">
        <h2>Penyesuaian Stok</h2>
        <form method="post" action="<?= BASE_URL ?>/actions/kurangi_stok.php">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" id="record_expense" name="record_expense" value="0">
            <div class="form-group">
                <label for="product_adjust_search">Produk</label>
                <div class="autocomplete-wrapper">
                    <input type="text" id="product_adjust_search" placeholder="Ketik nama produk untuk mencari..." autocomplete="off">
                    <input type="hidden" id="product_adjust" name="product_id" required>
                    <div id="product_adjust_results" class="autocomplete-results" hidden></div>
                </div>
            </div>
            <div class="form-group">
                <label for="quantity">Jumlah Penyesuaian</label>
                <input type="number" id="quantity" name="quantity" min="1" required>
            </div>
            <div class="form-group">
                <label for="reason">Alasan</label>
                <textarea id="reason" name="reason" rows="3" required></textarea>
            </div>
            <button class="button" type="submit">Simpan Penyesuaian</button>
            <p class="muted" style="margin-top:0.5rem;">Sistem akan menanyakan apakah penyesuaian ini ingin dicatat sebagai pengeluaran.</p>
        </form>
    </section>
</div>

<section class="card" style="margin-top:1.5rem;">
    <h2>Pengajuan Penyesuaian Stok Menunggu</h2>
    <?php if (!$pendingStockRequests): ?>
        <p class="muted">Tidak ada pengajuan penyesuaian yang menunggu persetujuan.</p>
    <?php else: ?>
        <table class="table">
            <thead>
            <tr>
                <th>Produk</th>
                <th>Jumlah</th>
                <th>Alasan</th>
                <th>Catatan Stok</th>
                <th>Diajukan Oleh</th>
                <th>Diajukan Pada</th>
                <?php if ($canDecideStockRequests): ?>
                    <th>Aksi</th>
                <?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($pendingStockRequests as $request): ?>
                <?php $metadata = decode_request_metadata($request['metadata'] ?? null); ?>
                <tr>
                    <td><?= sanitize($request['product_name']) ?></td>
                    <td><?= (int) $request['requested_quantity'] ?></td>
                    <td><?= sanitize($request['reason']) ?></td>
                    <td>
                        <?php if ($metadata): ?>
                            <span class="muted">
                                Stok saat diajukan: <?= isset($metadata['current_stock']) ? (int) $metadata['current_stock'] : '-' ?>
                                <?php if (!empty($metadata['record_expense'])): ?>
                                    &middot; Catat pengeluaran
                                <?php endif; ?>
                            </span>
                        <?php else: ?>
                            <span class="muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td><?= sanitize($request['created_by_name'] ?? '-') ?></td>
                    <td><?= format_date($request['created_at'], true) ?></td>
                    <?php if ($canDecideStockRequests): ?>
                        <td>
                            <div style="display:flex; flex-direction:column; gap:0.5rem;">
                                <form method="post" action="<?= BASE_URL ?>/actions/decide_stock_adjustment.php" style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center;">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                                    <input type="hidden" name="decision" value="approve">
                                    <button class="button small" type="submit">Setujui & Terapkan</button>
                                </form>
                                <form method="post" action="<?= BASE_URL ?>/actions/decide_stock_adjustment.php">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                                    <input type="hidden" name="decision" value="reject">
                                    <label class="muted" for="stock-reject-notes-<?= (int) $request['id'] ?>" style="display:block; margin-bottom:0.25rem;">Catatan (opsional)</label>
                                    <textarea id="stock-reject-notes-<?= (int) $request['id'] ?>" name="notes" rows="2" placeholder="Alasan penolakan"></textarea>
                                    <button class="button secondary small" type="submit" style="margin-top:0.25rem;">Tolak</button>
                                </form>
                            </div>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$canDecideStockRequests): ?>
            <p class="muted" style="margin-top:0.75rem;">Hubungi manajer untuk memproses penyesuaian ini.</p>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="card" style="margin-top:1.5rem;">
    <div class="flex justify-between items-center" style="margin-bottom: 1rem; flex-wrap:wrap; gap:0.75rem;">
        <h2 style="margin:0;">Batch Stok Terbaru</h2>
        <div class="print-actions" data-all-stock-print-container style="display:flex; flex-wrap:wrap; gap:0.75rem; align-items:flex-end;">
            <div class="form-group" style="margin:0;">
                <label for="stock-print-category" style="display:block; font-size:0.85rem; margin-bottom:0.25rem;">Kategori Cetak</label>
                <select id="stock-print-category" data-stock-category-select style="min-width:180px;">
                    <option value="all">Semua Kategori</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= (int) $category['id'] ?>"><?= sanitize($category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex; flex-wrap:wrap; gap:0.5rem;">
                <button type="button" class="button secondary" data-all-stock-print="pc">Cetak di PC</button>
                <button type="button" class="button secondary" data-all-stock-print="app">Cetak via App</button>
                <a class="button secondary" data-stock-print-link href="<?= BASE_URL ?>/actions/print_semua_barang.php" target="_blank" rel="noopener">Buka Halaman Cetak</a>
            </div>
        </div>
    </div>
    <form class="filter-form" method="get" action="<?= BASE_URL ?>/index.php" style="margin-bottom:1rem;">
        <input type="hidden" name="page" value="stok">
        <div class="grid-3">
            <div class="form-group">
                <label for="batch_search">Cari Produk / Batch</label>
                <input type="text" id="batch_search" name="batch_search" value="<?= sanitize($batchSearch) ?>" placeholder="contoh: indomie">
            </div>
            <div class="form-group">
                <label for="batch_product">Produk</label>
                <select id="batch_product" name="batch_product">
                    <option value="all">Semua Produk</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= $product['id'] ?>" <?= ($batchProduct !== '' && (int) $batchProduct === (int) $product['id']) ? 'selected' : '' ?>>
                            <?= sanitize($product['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>&nbsp;</label>
                <div style="display:flex; gap:0.75rem;">
                    <button class="button" type="submit">Terapkan</button>
                    <a class="button secondary" href="<?= BASE_URL ?>/index.php?page=stok">Reset</a>
                </div>
            </div>
        </div>
    </form>
    <table class="table">
        <thead>
        <tr>
            <th>Produk</th>
            <th>Batch</th>
            <th>Pemasok</th>
            <th>Stok Awal</th>
            <th>Sisa</th>
            <th>Harga Beli</th>
            <th>Harga Jual</th>
            <th>Kadaluarsa</th>
            <th>Masuk</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($batches as $batch): ?>
            <tr>
                <td><?= sanitize($batch['product_name']) ?></td>
                <td><?= sanitize($batch['batch_code']) ?></td>
                <td><?= sanitize($batch['supplier_name'] ?? '-') ?></td>
                <td><?= (int) $batch['stock_in'] ?></td>
                <td><?= (int) $batch['stock_remaining'] ?></td>
                <td><?= format_rupiah($batch['purchase_price']) ?></td>
                <td><?= format_rupiah($batch['sell_price']) ?></td>
                <td><?= $batch['expiry_date'] ? format_date($batch['expiry_date']) : '-' ?></td>
                <td><?= format_date($batch['received_at'], true) ?></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
</section>

<section class="card" style="margin-top:1.5rem;">
    <h2>Riwayat Pengajuan Penyesuaian</h2>
    <?php if (!$historyStockRequests): ?>
        <p class="muted">Belum ada riwayat penyesuaian yang diputuskan.</p>
    <?php else: ?>
        <table class="table">
            <thead>
            <tr>
                <th>Produk</th>
                <th>Jumlah</th>
                <th>Alasan</th>
                <th>Status</th>
                <th>Diputuskan Oleh</th>
                <th>Catatan</th>
                <th>Diajukan Pada</th>
                <th>Diputuskan Pada</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($historyStockRequests as $history): ?>
                <tr>
                    <td><?= sanitize($history['product_name']) ?></td>
                    <td><?= (int) $history['requested_quantity'] ?></td>
                    <td><?= sanitize($history['reason']) ?></td>
                    <td><?= ucfirst($history['status']) ?></td>
                    <td><?= sanitize($history['decision_by_name'] ?? '-') ?></td>
                    <td><?= sanitize($history['decision_notes'] ?? '-') ?></td>
                    <td><?= format_date($history['created_at'], true) ?></td>
                    <td><?= $history['decision_at'] ? format_date($history['decision_at'], true) : '-' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<?php
$adjustmentsQuery = "
    SELECT sa.*, p.name AS product_name, u.username AS adjusted_by_username
    FROM stock_adjustments sa
    INNER JOIN products p ON p.id = sa.product_id
    LEFT JOIN users u ON u.id = sa.created_by
    ORDER BY sa.created_at DESC
    LIMIT 50
";
$stmtAdjustments = $pdo->prepare($adjustmentsQuery);
$stmtAdjustments->execute();
$adjustments = $stmtAdjustments->fetchAll();
?>

<section class="card" style="margin-top:1.5rem;">
    <h2>Riwayat Penyesuaian Stok</h2>
    <table class="table">
        <thead>
        <tr>
            <th>Produk</th>
            <th>Tipe Penyesuaian</th>
            <th>Jumlah</th>
            <th>Alasan</th>
            <th>Disesuaikan Oleh</th>
            <th>Tanggal</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($adjustments as $adjustment): ?>
            <tr>
                <td><?= sanitize($adjustment['product_name']) ?></td>
                <td><?= sanitize($adjustment['adjustment_type']) ?></td>
                <td><?= (int) $adjustment['quantity'] ?></td>
                <td><?= sanitize($adjustment['reason']) ?></td>
                <td><?= sanitize($adjustment['adjusted_by_username'] ?? 'N/A') ?></td>
                <td><?= format_date($adjustment['created_at'], true) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<script>
    (function () {
        const adjustmentForm = document.querySelector('form[action$="kurangi_stok.php"]');
        if (!adjustmentForm) {
            return;
        }
        const recordExpenseInput = adjustmentForm.querySelector('#record_expense');

        adjustmentForm.addEventListener('submit', (event) => {
            if (adjustmentForm.dataset.skipExpensePrompt === '1') {
                adjustmentForm.dataset.skipExpensePrompt = '0';
                return;
            }
            event.preventDefault();

            const productSelect = adjustmentForm.querySelector('#product_adjust');
            const reasonField = adjustmentForm.querySelector('#reason');

            const selectedOption = productSelect && productSelect.selectedIndex >= 0
                ? productSelect.options[productSelect.selectedIndex]
                : null;
            const productName = selectedOption && selectedOption.value !== ''
                ? selectedOption.text.trim()
                : 'produk ini';

            const reasonText = reasonField && reasonField.value.trim() !== ''
                ? reasonField.value.trim()
                : 'penyesuaian stok';

            const confirmExpense = window.confirm(
                `Catatan penyesuaian untuk ${productName} dengan alasan "${reasonText}".\n\nApakah ingin mencatat pengeluaran atas penyesuaian ini?`
            );

            if (recordExpenseInput) {
                recordExpenseInput.value = confirmExpense ? '1' : '0';
            }

            adjustmentForm.dataset.skipExpensePrompt = '1';
            adjustmentForm.submit();
        });
    })();
</script>

<script id="stock-product-catalog" type="application/json"><?= json_encode($productCatalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>

<script>
    (function() {
        const container = document.querySelector('[data-all-stock-print-container]');
        if (!container) {
            return;
        }

        const basePrintUrl = '<?= BASE_URL ?>/actions/print_semua_barang.php';
        const categorySelect = container.querySelector('[data-stock-category-select]');
        const manualLink = container.querySelector('[data-stock-print-link]');

        const buildPrintUrl = () => {
            if (categorySelect && categorySelect.value && categorySelect.value !== 'all') {
                return `${basePrintUrl}?category_id=${encodeURIComponent(categorySelect.value)}`;
            }
            return basePrintUrl;
        };

        const refreshManualLink = () => {
            if (manualLink) {
                manualLink.href = buildPrintUrl();
            }
        };

        if (categorySelect) {
            categorySelect.addEventListener('change', refreshManualLink);
            refreshManualLink();
        } else {
            refreshManualLink();
        }

        const handlePrint = (mode) => {
            const existing = document.getElementById('all-stock-print-frame');
            if (existing) {
                existing.remove();
            }

            const iframe = document.createElement('iframe');
            iframe.id = 'all-stock-print-frame';
            iframe.style.display = 'none';
            iframe.src = buildPrintUrl();
            document.body.appendChild(iframe);

            iframe.onload = () => {
                try {
                    setTimeout(() => {
                        if (mode === 'pc') {
                            iframe.contentWindow.print();
                        } else if (mode === 'app') {
                            if (typeof iframe.contentWindow.printViaApp === 'function') {
                                const printableText = iframe.contentWindow.printViaApp();
                                if (printableText) {
                                    const success = typeof window.sendToKasirPrinter === 'function'
                                        ? window.sendToKasirPrinter(printableText)
                                        : (() => {
                                            const encoded = encodeURIComponent(printableText);
                                            window.location.href = `kasirprinter://print?text=${encoded}`;
                                            return true;
                                        })();
                                    if (!success) {
                                        alert('Gagal mengirim data ke aplikasi kasir.');
                                    }
                                } else {
                                    alert('Tidak ada data yang bisa dicetak via aplikasi.');
                                }
                            } else {
                                alert('Fungsi printViaApp tidak tersedia pada template cetak.');
                            }
                        }
                    }, 250);
                } catch (error) {
                    console.error(error);
                    alert('Terjadi kesalahan saat memproses pencetakan.');
                } finally {
                    setTimeout(() => iframe.remove(), 2000);
                }
            };

            iframe.onerror = () => {
                alert('Gagal memuat halaman cetak.');
            };
        };

        container.addEventListener('click', (event) => {
            const button = event.target.closest('[data-all-stock-print]');
            if (!button) {
                return;
            }
            event.preventDefault();
            handlePrint(button.dataset.allStockPrint);
        });
    })();
</script>

<script>
    (function() {
        const catalogElement = document.getElementById('stock-product-catalog');
        if (!catalogElement) {
            return;
        }

        const catalog = JSON.parse(catalogElement.textContent || '[]');
        const initialPrefill = {
            productId: <?= json_encode($prefillProductId) ?>,
            barcode: <?= json_encode($prefillBarcode) ?>,
        };
        const SELL_PRICE_PERCENT_LIMIT = 0.10;
        const SELL_PRICE_ABSOLUTE_LIMIT = 500;
        const SELL_PRICE_ROUNDING = 100;
        const rupiahFormatter = typeof Intl !== 'undefined' ? new Intl.NumberFormat('id-ID') : null;
        const barcodeMap = new Map();
        const normalize = (value) => (value || '').trim().toUpperCase();

        catalog.forEach(product => {
            if (product.barcode) {
                barcodeMap.set(normalize(product.barcode), product);
            }
        });

        const productSearchInput = document.getElementById('product-search-input');
        const selectedProductIdInput = document.getElementById('selected_product_id');
        const productSearchResults = document.getElementById('product-search-results');
        const productSearchFeedback = document.getElementById('product-search-feedback');
        const supplierSelect = document.getElementById('supplier_id');
        const batchCodeInput = document.getElementById('batch_code');
        const purchasePriceInput = document.getElementById('purchase_price');
        const sellPriceInput = document.getElementById('sell_price');
        const sellPriceHint = document.getElementById('sell_price_hint');

        let selectedProduct = null;
        const setupSellPriceSuggestion = () => {
            if (!purchasePriceInput || !sellPriceInput) {
                return;
            }

            const baseHint = sellPriceHint?.dataset.defaultHint || '';
            let lastAutoPrice = '';
            let manualOverride = false;

            const formatRupiah = (value) => {
                if (!Number.isFinite(value)) {
                    return '';
                }
                return rupiahFormatter ? rupiahFormatter.format(Math.round(value)) : String(Math.round(value));
            };

            const roundUp = (value) => {
                if (!Number.isFinite(value) || value <= 0) {
                    return null;
                }
                return Math.ceil(value / SELL_PRICE_ROUNDING) * SELL_PRICE_ROUNDING;
            };

            const calculateSuggestion = (purchaseValue) => {
                if (!Number.isFinite(purchaseValue) || purchaseValue <= 0) {
                    return null;
                }
                const percentageMargin = purchaseValue * SELL_PRICE_PERCENT_LIMIT;
                const allowedMargin = Math.min(percentageMargin, SELL_PRICE_ABSOLUTE_LIMIT);
                return roundUp(purchaseValue + allowedMargin);
            };

            const updateHint = (suggestion) => {
                if (!sellPriceHint) {
                    return;
                }
                if (!suggestion) {
                    sellPriceHint.textContent = baseHint;
                    return;
                }
                const prefix = baseHint ? `${baseHint} ` : '';
                sellPriceHint.textContent = `${prefix}Rekomendasi saat ini: Rp ${formatRupiah(suggestion)}.`;
            };

            const applySuggestion = () => {
                const purchaseValue = purchasePriceInput.valueAsNumber;
                const suggestion = calculateSuggestion(purchaseValue);
                updateHint(suggestion);

                if (!suggestion) {
                    if (!manualOverride) {
                        sellPriceInput.value = '';
                        lastAutoPrice = '';
                    }
                    return;
                }

                if (manualOverride && sellPriceInput.value !== '' && sellPriceInput.value !== lastAutoPrice) {
                    return;
                }

                sellPriceInput.value = String(suggestion);
                lastAutoPrice = sellPriceInput.value;
                manualOverride = false;
            };

            purchasePriceInput.addEventListener('input', applySuggestion);
            purchasePriceInput.addEventListener('change', applySuggestion);

            sellPriceInput.addEventListener('input', () => {
                if (sellPriceInput.value === '' || sellPriceInput.value === lastAutoPrice) {
                    manualOverride = false;
                } else {
                    manualOverride = true;
                }
            });

            applySuggestion();
        };
        setupSellPriceSuggestion();

        const hideFeedback = () => {
            if (productSearchFeedback) {
                productSearchFeedback.hidden = true;
            }
            if (productSearchInput) {
                productSearchInput.classList.remove('has-error');
            }
        };

        const showFeedback = (message) => {
            if (productSearchFeedback) {
                productSearchFeedback.textContent = message;
                productSearchFeedback.hidden = false;
            }
            if (productSearchInput) {
                productSearchInput.classList.add('has-error');
            }
        };

        const clearSelection = () => {
            selectedProduct = null;
            selectedProductIdInput.value = '';
            productSearchInput.value = '';
            productSearchResults.innerHTML = '';
            productSearchResults.hidden = true;
            hideFeedback();
            productSearchInput.focus();
        };

        const selectProduct = (product) => {
            selectedProduct = product;
            selectedProductIdInput.value = product.id;
            productSearchInput.value = `${product.name} (${product.barcode || 'Tanpa Barcode'})`;
            productSearchResults.innerHTML = '';
            productSearchResults.hidden = true;
            hideFeedback();

            // Focus next field
            if (supplierSelect && supplierSelect.value === '') {
                supplierSelect.focus();
            } else if (batchCodeInput) {
                batchCodeInput.focus();
            }
        };

        const prefillFromParams = () => {
            if (!productSearchInput) {
                return;
            }

            if (initialPrefill.productId) {
                const productFromId = catalog.find(item => item.id === Number(initialPrefill.productId));
                if (productFromId) {
                    selectProduct(productFromId);
                    return;
                }
            }

            if (initialPrefill.barcode) {
                handleBarcode(initialPrefill.barcode);
            }
        };

        const handleBarcode = (value) => {
            const normalized = normalize(value);
            if (!normalized) {
                hideFeedback();
                return;
            }

            const product = barcodeMap.get(normalized);
            if (!product) {
                const message = `Barcode ${value.trim()} tidak ditemukan.`;
                showFeedback(message);
                
                if (confirm(message + '\n\nApakah Anda ingin menambahkan barang baru?')) {
                    window.location.href = '<?= BASE_URL ?>/index.php?page=barang&action=add&barcode=' + encodeURIComponent(value.trim());
                } else {
                    clearSelection();
                }
                return;
            }

            hideFeedback();
            selectProduct(product);
        };

        const expiryInput = document.getElementById('expiry_date');
        if (expiryInput) {
            expiryInput.addEventListener('input', () => {
                expiryInput.value = expiryInput.value.replace(/\D+/g, '').slice(0, 6);
            });
        }

        const isValidDate = (year, month, day) => {
            const date = new Date(year, month - 1, day);
            return (
                date.getFullYear() === year &&
                date.getMonth() === month - 1 &&
                date.getDate() === day
            );
        };

        const form = document.getElementById('stock-form');
        if (form) {
            form.addEventListener('submit', (event) => {
                if (!expiryInput) {
                    return;
                }

                const rawValue = expiryInput.value.trim();
                if (rawValue === '') {
                    expiryInput.value = '';
                    expiryInput.setCustomValidity('');
                    return;
                }

                const digits = rawValue.replace(/\D+/g, '');
                if (digits.length === 6) {
                    const day = parseInt(digits.slice(0, 2), 10);
                    const month = parseInt(digits.slice(2, 4), 10);
                    const yearTwoDigit = parseInt(digits.slice(4, 6), 10);
                    const year = 2000 + yearTwoDigit;

                    if (!isValidDate(year, month, day)) {
                        expiryInput.setCustomValidity('Format kadaluarsa tidak valid. Gunakan ddmmyy.');
                        event.preventDefault();
                        expiryInput.reportValidity();
                        return;
                    }

                    expiryInput.value = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                    expiryInput.setCustomValidity('');
                    return;
                }

                if (/^\d{4}-\d{2}-\d{2}$/.test(rawValue)) {
                    expiryInput.value = rawValue;
                    expiryInput.setCustomValidity('');
                    return;
                }

                expiryInput.setCustomValidity('Format kadaluarsa tidak valid. Gunakan ddmmyy.');
                event.preventDefault();
                expiryInput.reportValidity();
            });
        }


        if (productSearchInput) {
            setTimeout(() => productSearchInput.focus(), 300);

            productSearchInput.addEventListener('input', (event) => {
                const query = normalize(event.target.value);
                productSearchResults.innerHTML = '';
                productSearchResults.hidden = true;
                hideFeedback();
                selectedProductIdInput.value = ''; // Clear selected product ID on input

                if (query.length < 2) { // Only search if query is at least 2 characters
                    return;
                }

                const filteredProducts = catalog.filter(product =>
                    normalize(product.name).includes(query) ||
                    (product.barcode && normalize(product.barcode).includes(query))
                );

                if (filteredProducts.length > 0) {
                    filteredProducts.forEach(product => {
                        const div = document.createElement('div');
                        div.classList.add('autocomplete-item');
                        div.textContent = `${product.name} (${product.barcode || 'Tanpa Barcode'})`;
                        div.addEventListener('click', () => selectProduct(product));
                        productSearchResults.appendChild(div);
                    });
                    productSearchResults.hidden = false;
                } else {
                    showFeedback('Produk tidak ditemukan.');
                }
            });

            productSearchInput.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    // If there's only one result, select it
                    if (productSearchResults.children.length === 1) {
                        productSearchResults.children[0].click();
                    } else if (selectedProduct) {
                        // If a product is already selected, move focus
                        if (supplierSelect && supplierSelect.value === '') {
                            supplierSelect.focus();
                        } else if (batchCodeInput) {
                            batchCodeInput.focus();
                        }
                    } else {
                        // If no product selected and no single result, try to handle as barcode
                        handleBarcode(productSearchInput.value);
                    }
                }
                if (event.key === 'Escape') {
                    clearSelection();
                }
            });

            if (initialPrefill.productId || initialPrefill.barcode) {
                setTimeout(prefillFromParams, 400);
            }
        }

        document.addEventListener('keydown', (event) => {
            if (event.key === 'F8' && productSearchInput) {
                event.preventDefault();
                productSearchInput.focus();
            }
        });

        document.addEventListener('barcode-scanned', (event) => {
            // Assuming the barcode scanner will directly input into productSearchInput
            // or trigger this event with the value.
            // If the scanner directly inputs, the 'input' event listener will handle it.
            // If it triggers this event, we handle it here.
            if (event.detail?.targetId === 'product-search-input') {
                handleBarcode(event.detail.value);
            }
        });
    })();
</script>

<script>
    (function() {
        // Logic for Stock Adjustment Product Search
        const catalogElement = document.getElementById('stock-product-catalog');
        if (!catalogElement) return;
        const catalog = JSON.parse(catalogElement.textContent || '[]');
        
        const searchInput = document.getElementById('product_adjust_search');
        const hiddenInput = document.getElementById('product_adjust');
        const resultsContainer = document.getElementById('product_adjust_results');
        
        if (!searchInput || !hiddenInput || !resultsContainer) return;

        const normalize = (value) => (value || '').trim().toUpperCase();

        searchInput.addEventListener('input', (e) => {
            const query = normalize(e.target.value);
            resultsContainer.innerHTML = '';
            resultsContainer.hidden = true;
            hiddenInput.value = ''; // Reset selection

            if (query.length < 1) return;

            const filtered = catalog.filter(p => normalize(p.name).includes(query) || (p.barcode && normalize(p.barcode).includes(query)));
            
            if (filtered.length > 0) {
                filtered.forEach(p => {
                    const div = document.createElement('div');
                    div.className = 'autocomplete-item';
                    div.textContent = p.name + (p.barcode ? ` (${p.barcode})` : '');
                    div.addEventListener('click', () => {
                        searchInput.value = p.name;
                        hiddenInput.value = p.id;
                        resultsContainer.innerHTML = '';
                        resultsContainer.hidden = true;
                    });
                    resultsContainer.appendChild(div);
                });
                resultsContainer.hidden = false;
            }
        });
        
        // Hide results when clicking outside
        document.addEventListener('click', (e) => {
            if (!searchInput.contains(e.target) && !resultsContainer.contains(e.target)) {
                resultsContainer.hidden = true;
            }
        });
        
        // Keyboard navigation
        searchInput.addEventListener('keydown', (e) => {
             if (e.key === 'Enter') {
                 e.preventDefault();
                 if (resultsContainer.children.length === 1) {
                     resultsContainer.children[0].click();
                 } else if (resultsContainer.children.length > 0) {
                      resultsContainer.children[0].click();
                 }
             }
        });
    })();
</script>
