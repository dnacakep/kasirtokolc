<?php

if (!function_exists('ensure_csrf_token')) {
    require_once __DIR__ . '/../config/auth.php';
    require_once __DIR__ . '/../includes/fungsi.php';
}

ensure_csrf_token();

$pdo = get_db_connection();
$products = $pdo->query("
    SELECT p.id, p.name, p.barcode, p.points_reward,
           COALESCE(
               (SELECT b.sell_price FROM product_batches b WHERE b.product_id = p.id AND b.stock_remaining > 0 ORDER BY b.received_at ASC LIMIT 1),
               0
           ) AS current_price,
           (SELECT MIN(b.expiry_date) FROM product_batches b WHERE b.product_id = p.id AND b.stock_remaining > 0 AND b.expiry_date IS NOT NULL) AS earliest_expiry,
           COALESCE(
               (SELECT SUM(b.stock_remaining) FROM product_batches b WHERE b.product_id = p.id AND b.stock_remaining > 0 AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE())),
               0
           ) AS stock_available
    FROM products p
    WHERE p.is_active = 1
    ORDER BY p.name ASC
")->fetchAll();



$productCatalog = array_map(function ($product) {
    return [
        'id' => (int) $product['id'],
        'name' => $product['name'],
        'barcode' => $product['barcode'],
        'price' => isset($product['current_price']) ? (float) $product['current_price'] : 0.0,
        'points_reward' => isset($product['points_reward']) ? (int) $product['points_reward'] : 0,
        'earliest_expiry' => $product['earliest_expiry'] ?? null,
        'stock_available' => isset($product['stock_available']) ? (int) $product['stock_available'] : 0,
    ];
}, $products);

$lastSale = consume_last_sale_summary();

?>

<?php if ($lastSale):
    // Fetch additional data for WA link & direct print actions
    $memberPhone = null;
    $saleItemsForWa = [];
    $store = get_store_settings();
    $storeName = $store['store_name'] ?? APP_NAME;

    $saleDetailsStmt = $pdo->prepare("SELECT member_id FROM sales WHERE id = :sale_id");
    $saleDetailsStmt->execute([':sale_id' => $lastSale['sale_id']]);
    $saleDetails = $saleDetailsStmt->fetch();

    $waMessage = "";
    if ($saleDetails && $saleDetails['member_id']) {
        $memberStmt = $pdo->prepare("SELECT phone FROM members WHERE id = :id");
        $memberStmt->execute([':id' => $saleDetails['member_id']]);
        $rawPhone = $memberStmt->fetchColumn();
        if ($rawPhone) {
            $memberPhone = preg_replace('/[^0-9]/', '', $rawPhone);
            if (substr($memberPhone, 0, 1) === '0') {
                $memberPhone = '62' . substr($memberPhone, 1);
            }
        }

        $itemsStmt = $pdo->prepare("SELECT p.name, si.quantity, si.price, si.total FROM sale_items si JOIN products p ON p.id = si.product_id WHERE si.sale_id = :sale_id");
        $itemsStmt->execute([':sale_id' => $lastSale['sale_id']]);
        $saleItemsForWa = $itemsStmt->fetchAll();

        $waMessage = "Terima kasih telah berbelanja di " . $storeName . "!\n\n";
        $waMessage .= "Invoice: " . $lastSale['invoice_code'] . "\n";
        $waMessage .= "Total: " . format_rupiah((float) $lastSale['grand_total']) . "\n\n";
        $waMessage .= "Detail Pembelian:\n";
        foreach($saleItemsForWa as $item) {
            $waMessage .= "- " . $item['name'] . " (" . $item['quantity'] . " x " . format_rupiah($item['price']) . ") = " . format_rupiah($item['total']) . "\n";
        }
        $waMessage .= "\nSemoga harimu menyenangkan!";
    }
?>
    <section class="card success-summary">
        <div class="success-summary__content">
            <p class="success-summary__text">
                Transaksi terakhir tersimpan:
                <strong><?= sanitize($lastSale['invoice_code']) ?></strong>
                <span>· <?= format_rupiah((float) $lastSale['grand_total']) ?></span>
                <span>· <?= strtoupper($lastSale['payment_method']) ?></span>
            </p>
            <div class="print-actions">
                <button type="button" class="button" data-print-action="print_app" data-sale-id="<?= (int) $lastSale['sale_id'] ?>">
                    Cetak via App
                </button>
                <button type="button" class="button" data-print-action="print_pc" data-sale-id="<?= (int) $lastSale['sale_id'] ?>">
                    Cetak di PC
                </button>
                <?php if ($memberPhone && !empty($waMessage)): ?>
                <a class="button secondary" target="_blank" href="https://wa.me/<?= $memberPhone ?>?text=<?= urlencode($waMessage) ?>">
                    Kirim via WA
                </a>
                <?php endif; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<section class="pos-workspace">
    <div class="pos-workspace-heading">
        <h2>Transaksi Baru</h2>
        <p class="muted">Fokuskan kolom pencarian di kiri untuk scan cepat, lalu atur pembayaran di panel kanan.</p>
    </div>
    <form id="transaction-form" class="pos-transaction-form" method="post" action="<?= BASE_URL ?>/actions/transaksi_simpan.php">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <div class="pos-display pos-display--fullwidth">
            <div class="pos-display-total">
                <div class="pos-display-label">TOTAL</div>
                <div class="pos-display-amount" id="total-display">Rp0</div>
            </div>
            <div class="pos-display-details">
                <div class="pos-detail-group">
                    <label for="cash_paid">DIBAYAR</label>
                    <input type="number" id="cash_paid" name="cash_paid" min="0" step="0.01">
                </div>
                <div class="pos-detail-group pos-change-group">
                    <label>KEMBALIAN</label>
                    <div class="pos-change-amount" id="change-display">Rp0</div>
                </div>
                <div class="pos-detail-group" id="debt-fee-group" hidden>
                    <label>Admin Hutang (10%)</label>
                    <div class="pos-change-amount" id="debt-fee-display">Rp0</div>
                </div>
                <div class="pos-detail-group" id="debt-total-group" hidden>
                    <label>Total Hutang</label>
                    <div class="pos-change-amount" id="debt-total-display">Rp0</div>
                </div>
                <div class="pos-detail-group pos-action-inline">
                    <label>&nbsp;</label>
                    <button class="button button-primary button-full" type="submit">Simpan Transaksi</button>
                </div>
            </div>
        </div>
        <div class="pos-tablet-grid">
            <div class="pos-tablet-left">
                <div class="pos-left-scroll">
                    <div class="form-group barcode-entry">
                        <label for="barcode-input">Scan Barcode / Cari Barang</label>
                        <div class="barcode-input-wrapper">
                            <input type="text" id="barcode-input" placeholder="Scan atau ketik nama/barcode barang" autocomplete="off" list="barcode-suggestions">
                            <button class="button secondary scan-button" type="button" data-scan-target="barcode-input" aria-label="Scan barcode menggunakan kamera">&#128247;</button>
                            <button class="button secondary" type="button" id="barcode-clear">Bersihkan</button>
                        </div>
                        <small class="muted">Kolom ini selalu aktif untuk scan berikutnya. Tekan Escape jika ingin mengosongkan.</small>
                        <p class="barcode-feedback" id="barcode-feedback" hidden>Barang tidak ditemukan.</p>
                        <div id="custom-suggestions" class="autocomplete-suggestions"></div>
                    </div>
                    <div class="pos-cart-table">
                        <table class="table table-stack table-compact" id="items-table">
                            <thead>
                            <tr>
                                <th>Barang</th>
                                <th>Qty</th>
                                <th>Harga</th>
                                <th>Diskon</th>
                                <th>Subtotal</th>
                                <th></th>
                            </tr>
                            </thead>
                            <tbody>
                            <tr class="empty-row">
                                <td colspan="6" class="muted" style="text-align:center;">Belum ada barang. Gunakan kolom barcode/pencarian untuk menambah.</td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="pos-tablet-right">
                <div class="pos-right-controls">
                    <div class="form-group">
                        <label for="member-search-input">Member</label>
                        <div id="member-search-container">
                            <input type="text" id="member-search-input" placeholder="Ketik nama atau kode member..." autocomplete="off">
                            <div id="member-suggestions" class="autocomplete-suggestions"></div>
                        </div>
                        <div id="selected-member-display" hidden>
                            <p style="margin:0;font-size:0.9em;">
                                <strong id="member-name-display"></strong>
                                <span id="member-points-display" class="muted"></span>
                            </p>
                            <button type="button" id="remove-member-btn" class="button secondary small" style="margin-top:4px;">Ganti Member</button>
                        </div>
                        <input type="hidden" id="member_id" name="member_id" value="">
                    </div>
                    <div class="form-group">
                        <label for="payment_method">Metode Pembayaran</label>
                        <select id="payment_method" name="payment_method" required>
                            <option value="cash">Tunai</option>
                            <option value="debit">Debit</option>
                            <option value="qris">QRIS</option>
                            <option value="hutang">Hutang (Member)</option>
                        </select>
                        <p class="muted" id="debt-hint" hidden>Hutang hanya untuk member aktif. Anda boleh memasukkan pembayaran sebagian; sisa akan otomatis dihitung sebagai hutang + admin 10%.</p>
                    </div>
                    <div class="form-group">
                        <label for="points_used">Poin Digunakan</label>
                        <input type="number" id="points_used" name="points_used" min="0" value="0">
                        <p class="muted" id="points-summary" hidden></p>
                    </div>
                    <div class="form-group">
                        <label for="notes">Catatan</label>
                        <textarea id="notes" name="notes" rows="3" placeholder="Catatan transaksi"></textarea>
                    </div>
                </div>
            </div>
        </div>

        <template id="item-row-template">
            <tr class="sale-item-row">
                <td class="product-cell" data-label="Barang">
                    <div class="product-info">
                        <strong class="product-name">-</strong>
                        <div class="product-barcode muted"></div>
                    </div>
                    <input type="hidden" name="product_id[]" class="product-id-input" required>
                </td>
                <td data-label="Qty"><input type="number" name="quantity[]" class="quantity-input" min="1" value="1" required></td>
                <td data-label="Harga"><input type="number" name="price[]" class="price-input" min="0" step="0.01" required></td>
                <td data-label="Diskon"><input type="number" name="discount[]" class="discount-input" min="0" step="0.01" value="0"></td>
                <td class="subtotal-cell" data-label="Subtotal">0</td>
                <td data-label="Aksi"><button class="button secondary remove-row" type="button">Hapus</button></td>
            </tr>
        </template>

        <div class="mobile-pos-interface" id="mobile-pos-interface">
            <div class="mobile-pos-summary">
                <div class="mobile-pos-summary__item">
                    <span>Total</span>
                    <strong id="mobile-total-display">Rp0</strong>
                </div>
                <div class="mobile-pos-summary__item">
                    <span id="mobile-change-label">Kembali</span>
                    <strong id="mobile-change-display">Rp0</strong>
                </div>
            </div>
            <div class="mobile-pos-actions">
                <button class="mobile-action-button scan-button" type="button" data-scan-target="barcode-input" aria-label="Scan barcode menggunakan kamera">
                    &#128247;
                    <span>Scan</span>
                </button>
                <button class="mobile-action-button mobile-action-primary" type="submit">
                    &#128190;
                    <span>Simpan</span>
                </button>
                <button class="mobile-action-button mobile-action-secondary" type="button" id="mobile-cash-button">
                    &#128181;
                    <span>Dibayar</span>
                </button>
            </div>
        </div>

    </form>
</section>


<script id="product-catalog" type="application/json"><?= json_encode($productCatalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script>
    const productCatalogElement = document.getElementById('product-catalog');
    const productCatalog = productCatalogElement ? JSON.parse(productCatalogElement.textContent) : [];
    const productMap = new Map();

    const normalizeBarcode = (value) => (value ?? '').toString().trim().toUpperCase();

    productCatalog.forEach(product => {
        product.stock_available = Number(product.stock_available ?? 0);
        if (product.barcode) {
            productMap.set(normalizeBarcode(product.barcode), product);
        }
        // Tambahkan ID produk ke map untuk pencarian cepat
        productMap.set(product.id.toString(), product);
        // Juga tambahkan nama produk ke map untuk pencarian cepat
        productMap.set(product.name.toLowerCase(), product);
    });

    const itemsTable = document.querySelector('#items-table tbody');
    const totalDisplay = document.querySelector('#total-display');
    const barcodeInput = document.getElementById('barcode-input');
    const barcodeFeedback = document.getElementById('barcode-feedback');
    const barcodeClear = document.getElementById('barcode-clear');
    const customSuggestions = document.getElementById('custom-suggestions');
    const itemRowTemplate = document.getElementById('item-row-template');
    const emptyRowTemplate = itemsTable.querySelector('.empty-row') ? itemsTable.querySelector('.empty-row').cloneNode(true) : null;
    const paymentMethodSelect = document.getElementById('payment_method');
    const cashPaidInput = document.getElementById('cash_paid');
    const changeDisplay = document.getElementById('change-display');
    const pointsSummary = document.getElementById('points-summary');
    const pointsUsedInput = document.getElementById('points_used');
    const debtHint = document.getElementById('debt-hint');
    const debtFeeGroup = document.getElementById('debt-fee-group');
    const debtFeeDisplay = document.getElementById('debt-fee-display');
    const debtTotalGroup = document.getElementById('debt-total-group');
    const debtTotalDisplay = document.getElementById('debt-total-display');
    const mobileTotalDisplay = document.getElementById('mobile-total-display');
    const mobileChangeDisplay = document.getElementById('mobile-change-display');
    const mobileChangeLabel = document.getElementById('mobile-change-label');
    const mobileCashButton = document.getElementById('mobile-cash-button');

    const currencyFormatter = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR' });
    let lastGrandTotal = 0;
    let lastDebtOutstanding = 0;

    const clearEmptyState = () => {
        const emptyRow = itemsTable.querySelector('.empty-row');
        if (emptyRow) {
            emptyRow.remove();
        }
    };

    const ensureEmptyState = () => {
        if (itemsTable.querySelector('.sale-item-row')) {
            return;
        }
        if (!itemsTable.querySelector('.empty-row') && emptyRowTemplate) {
            itemsTable.appendChild(emptyRowTemplate.cloneNode(true));
        }
    };

    const syncChangeDisplay = (text, label = 'Kembali') => {
        if (changeDisplay) {
            changeDisplay.textContent = text;
        }
        if (mobileChangeDisplay) {
            mobileChangeDisplay.textContent = text;
        }
        if (mobileChangeLabel) {
            mobileChangeLabel.textContent = label;
        }
    };

    const updateChange = (grandTotal = lastGrandTotal) => {
        if (!changeDisplay) {
            // Tetap sinkron untuk tampilan mobile meski elemen utama tidak ada
            if (mobileChangeDisplay && mobileChangeLabel) {
                syncChangeDisplay(currencyFormatter.format(0));
            }
            return;
        }
        const method = paymentMethodSelect ? paymentMethodSelect.value : 'cash';
        if (method === 'hutang') {
            const text = lastDebtOutstanding > 0
                ? `Sisa ${currencyFormatter.format(lastDebtOutstanding)}`
                : 'Lunas';
            syncChangeDisplay(text, 'Sisa Hutang');
            return;
        }
        if (method !== 'cash') {
            syncChangeDisplay('Tidak diperlukan', 'Kembali');
            return;
        }

        if (!cashPaidInput || cashPaidInput.value === '') {
            syncChangeDisplay(currencyFormatter.format(0), 'Kembali');
            return;
        }

        const cashValue = parseFloat(cashPaidInput.value);
        const cashPaid = Number.isFinite(cashValue) ? cashValue : 0;
        const change = cashPaid - grandTotal;

        if (!Number.isFinite(change)) {
            syncChangeDisplay(currencyFormatter.format(0), 'Kembali');
            return;
        }

        if (change >= 0) {
            syncChangeDisplay(currencyFormatter.format(change), 'Kembali');
        } else {
            syncChangeDisplay(`Kurang ${currencyFormatter.format(Math.abs(change))}`, 'Kurang');
        }
    };

    const updateTotal = () => {
        let subtotal = 0;
        itemsTable.querySelectorAll('.sale-item-row').forEach(row => {
            subtotal += parseFloat(row.dataset.subtotal || '0');
        });

        let pointsUsed = 0;
        if (pointsUsedInput && pointsUsedInput.value !== '') {
            const parsed = parseFloat(pointsUsedInput.value);
            if (Number.isFinite(parsed) && parsed > 0) {
                pointsUsed = parsed;
            }
        }

        let cashPaid = 0;
        if (cashPaidInput && cashPaidInput.value !== '') {
            const parsedCash = parseFloat(cashPaidInput.value);
            if (Number.isFinite(parsedCash) && parsedCash > 0) {
                cashPaid = parsedCash;
            }
        }

        const method = paymentMethodSelect ? paymentMethodSelect.value : 'cash';
        const netTotal = Math.max(0, subtotal - pointsUsed);
        let adminFee = 0;
        let grandTotal = netTotal;
        let summaryParts = [];
        lastDebtOutstanding = 0;

        if (method === 'hutang') {
            const normalizedCashPaid = Math.min(Math.max(cashPaid, 0), netTotal);
            const outstandingPrincipal = Math.max(0, netTotal - normalizedCashPaid);
            adminFee = Math.max(0, Math.round(outstandingPrincipal * 0.10 * 100) / 100);
            const outstandingTotal = outstandingPrincipal + adminFee;
            grandTotal = netTotal + adminFee;
            lastDebtOutstanding = outstandingTotal;

            if (debtFeeGroup && debtFeeDisplay) {
                debtFeeGroup.hidden = outstandingPrincipal <= 0;
                if (!debtFeeGroup.hidden) {
                    debtFeeDisplay.textContent = currencyFormatter.format(adminFee);
                }
            }

            if (debtTotalGroup && debtTotalDisplay) {
                debtTotalGroup.hidden = outstandingPrincipal <= 0;
                if (!debtTotalGroup.hidden) {
                    debtTotalDisplay.textContent = currencyFormatter.format(outstandingTotal);
                }
            }

            summaryParts.push(`Subtotal ${currencyFormatter.format(subtotal)}`);
            if (pointsUsed > 0) {
                summaryParts.push(`- Poin ${currencyFormatter.format(pointsUsed)}`);
            }
            if (normalizedCashPaid > 0) {
                summaryParts.push(`- Dibayar ${currencyFormatter.format(normalizedCashPaid)}`);
            }
            if (adminFee > 0) {
                summaryParts.push(`+ Admin 10% ${currencyFormatter.format(adminFee)}`);
            }
            summaryParts.push(`= ${currencyFormatter.format(grandTotal)}`);
        } else {
            if (debtFeeGroup) {
                debtFeeGroup.hidden = true;
            }
            if (debtTotalGroup) {
                debtTotalGroup.hidden = true;
            }

            if (method === 'cash') {
                summaryParts.push(`Subtotal ${currencyFormatter.format(subtotal)}`);
                if (pointsUsed > 0) {
                    summaryParts.push(`- Poin ${currencyFormatter.format(pointsUsed)}`);
                }
                summaryParts.push(`= ${currencyFormatter.format(grandTotal)}`);
            } else if (pointsUsed > 0) {
                summaryParts.push(`Subtotal ${currencyFormatter.format(subtotal)} - Poin ${currencyFormatter.format(pointsUsed)} = ${currencyFormatter.format(grandTotal)}`);
            }
        }

        const formattedGrandTotal = currencyFormatter.format(grandTotal);
        totalDisplay.textContent = formattedGrandTotal;
        if (mobileTotalDisplay) {
            mobileTotalDisplay.textContent = formattedGrandTotal;
        }

        if (pointsSummary) {
            if (summaryParts.length > 0 && subtotal > 0) {
                pointsSummary.hidden = false;
                pointsSummary.textContent = summaryParts.join(' ');
            } else {
                pointsSummary.hidden = true;
                pointsSummary.textContent = '';
            }
        }

        lastGrandTotal = grandTotal;
        updateChange(grandTotal);
    };

    const handlePaymentMethodChange = () => {
        const method = paymentMethodSelect ? paymentMethodSelect.value : 'cash';

        if (cashPaidInput) {
            const allowCashInput = method === 'cash' || method === 'hutang';
            cashPaidInput.disabled = !allowCashInput;
            if (!allowCashInput) {
                cashPaidInput.value = '';
            }
            if (mobileCashButton) {
                mobileCashButton.disabled = !allowCashInput;
            }
        }

        if (debtHint) {
            debtHint.hidden = method !== 'hutang';
        }

        updateTotal();
    };

    const updateRow = (row) => {
        const quantity = parseFloat(row.querySelector('.quantity-input').value) || 0;
        const price = parseFloat(row.querySelector('.price-input').value) || 0;
        const discount = parseFloat(row.querySelector('.discount-input').value) || 0;
        const subtotal = Math.max(0, (quantity * price) - discount);
        row.dataset.subtotal = subtotal.toString();
        row.querySelector('.subtotal-cell').textContent = currencyFormatter.format(subtotal);
        updateTotal();
    };

    const bindRowEvents = (row) => {
        row.dataset.subtotal = '0';

        const quantityInput = row.querySelector('.quantity-input');
        if (quantityInput) {
            quantityInput.addEventListener('input', () => {
                clampRowQuantityToStock(row);
                updateRow(row);
            });
        }

        row.querySelectorAll('.price-input, .discount-input').forEach(input => {
            input.addEventListener('input', () => updateRow(row));
        });

        row.querySelector('.remove-row').addEventListener('click', () => {
            row.remove();
            updateTotal();
            ensureEmptyState();
        });
    };

    const applyProductToRow = (row, product) => {
        const productId = String(product.id);
        row.dataset.productId = productId;

        const productIdInput = row.querySelector('.product-id-input');
        if (productIdInput) {
            productIdInput.value = productId;
        }

        const nameElement = row.querySelector('.product-name');
        if (nameElement) {
            nameElement.textContent = product.name || 'Barang tanpa nama';
        }

        const barcodeElement = row.querySelector('.product-barcode');
        if (barcodeElement) {
            const barcodeLabel = product.barcode ? product.barcode : 'Tanpa barcode';
            barcodeElement.textContent = barcodeLabel;
        }

        const priceInput = row.querySelector('.price-input');
        if (priceInput && (!priceInput.value || priceInput.value === '0') && product.price) {
            priceInput.value = product.price;
        }
    };

    const createRow = (product = null) => {
        if (!itemRowTemplate) {
            return null;
        }
        const fragment = itemRowTemplate.content.cloneNode(true);
        const row = fragment.querySelector('.sale-item-row');
        if (!row) {
            return null;
        }
        clearEmptyState();
        bindRowEvents(row);
        itemsTable.appendChild(row);
        if (product) {
            applyProductToRow(row, product);
        }
        updateRow(row);
        return row;
    };

    const hideBarcodeFeedback = () => {
        if (barcodeFeedback) {
            barcodeFeedback.hidden = true;
        }
        if (barcodeInput) {
            barcodeInput.classList.remove('has-error');
        }
    };

    const showBarcodeFeedback = (message) => {
        if (barcodeFeedback) {
            barcodeFeedback.textContent = message;
            barcodeFeedback.hidden = false;
        }
        if (barcodeInput) {
            barcodeInput.classList.add('has-error');
        }
    };

    const flashRow = (row) => {
        row.classList.remove('table-row-flash');
        void row.offsetWidth;
        row.classList.add('table-row-flash');
        setTimeout(() => row.classList.remove('table-row-flash'), 600);
    };

    const findRowByProductId = (productId) => {
        if (!itemsTable || !productId) {
            return null;
        }
        return Array.from(itemsTable.querySelectorAll('.sale-item-row'))
            .find(row => row.dataset.productId === String(productId));
    };

    const getRowQuantity = (row) => {
        if (!row) {
            return 0;
        }
        const quantityInput = row.querySelector('.quantity-input');
        return quantityInput ? (parseFloat(quantityInput.value) || 0) : 0;
    };

    const clampRowQuantityToStock = (row) => {
        if (!row) {
            return;
        }
        const productId = row.dataset.productId;
        if (!productId || !productMap.has(productId)) {
            return;
        }
        const product = productMap.get(productId);
        const quantityInput = row.querySelector('.quantity-input');
        if (!quantityInput) {
            return;
        }

        const maxStock = Number(product.stock_available ?? 0);
        let quantity = parseFloat(quantityInput.value) || 0;

        if (quantity < 1) {
            quantity = 1;
        }

        if (Number.isFinite(maxStock) && maxStock > 0 && quantity > maxStock) {
            quantity = maxStock;
            showBarcodeFeedback(`Stok "${product.name}" tersisa ${maxStock}. Jumlah disesuaikan.`);
        } else if (!Number.isFinite(maxStock) || maxStock === 0 || quantity <= maxStock) {
            hideBarcodeFeedback();
        }

        quantityInput.value = quantity;
    };

    const getRemainingStockForProduct = (product) => {
        if (!product) {
            return 0;
        }
        const maxStock = Number(product.stock_available ?? 0);
        if (!Number.isFinite(maxStock)) {
            return 0;
        }
        const existingRow = findRowByProductId(product.id);
        const reservedQty = getRowQuantity(existingRow);
        return Math.max(0, maxStock - reservedQty);
    };

    // --- Autocomplete Logic --- //
    let currentSuggestions = [];
    let selectedSuggestionIndex = -1;
    let isInteractingWithSuggestions = false;

    const hideSuggestions = () => {
        if (customSuggestions) {
            customSuggestions.innerHTML = '';
            customSuggestions.style.display = 'none';
            selectedSuggestionIndex = -1;
        }
    };

    const showSuggestions = (matches) => {
        if (!customSuggestions) return;

        customSuggestions.innerHTML = '';
        if (matches.length === 0) {
            hideSuggestions();
            return;
        }

        const ul = document.createElement('ul');
        matches.forEach((product, index) => {
            const li = document.createElement('li');
            const stockNote = product.stock_available > 0 ? '' : ' (stok habis)';
            li.textContent = product.name + (product.barcode ? ` (${product.barcode})` : '') + stockNote;
            if (product.stock_available <= 0) {
                li.classList.add('muted');
            }
            li.dataset.productId = product.id;
            // Click listener will be handled by event delegation on customSuggestions parent
            ul.appendChild(li);
        });
        customSuggestions.appendChild(ul);
        customSuggestions.style.display = 'block';
        currentSuggestions = matches;
        selectedSuggestionIndex = -1;
    };

    const selectSuggestion = (product) => {
        handleScannedBarcode(product.id.toString()); // Treat selection as if product ID was entered
        hideSuggestions();
        barcodeInput.value = '';
        barcodeInput.focus();
    };

    const filterProducts = (query) => {
        const lowerQuery = query.toLowerCase();
        return productCatalog.filter(product =>
            product.name.toLowerCase().includes(lowerQuery) ||
            (product.barcode && product.barcode.toLowerCase().includes(lowerQuery))
        );
    };

    if (barcodeInput) {
        barcodeInput.addEventListener('input', () => {
            const value = barcodeInput.value.trim();
            if (value.length >= 2) {
                const matches = filterProducts(value);
                showSuggestions(matches);
            } else {
                hideSuggestions();
            }
            hideBarcodeFeedback();
        });

        barcodeInput.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                if (selectedSuggestionIndex > -1 && currentSuggestions[selectedSuggestionIndex]) {
                    selectSuggestion(currentSuggestions[selectedSuggestionIndex]);
                } else {
                    handleScannedBarcode(barcodeInput.value);
                }
            } else if (event.key === 'Escape') {
                barcodeInput.value = '';
                hideBarcodeFeedback();
                hideSuggestions();
            } else if (event.key === 'ArrowDown') {
                event.preventDefault();
                if (currentSuggestions.length > 0) {
                    selectedSuggestionIndex = (selectedSuggestionIndex + 1) % currentSuggestions.length;
                    updateSelectedSuggestion();
                }
            }
            else if (event.key === 'ArrowUp') {
                event.preventDefault();
                if (currentSuggestions.length > 0) {
                    selectedSuggestionIndex = (selectedSuggestionIndex - 1 + currentSuggestions.length) % currentSuggestions.length;
                    updateSelectedSuggestion();
                }
            }
        });

        barcodeInput.addEventListener('blur', (event) => {
            if (isInteractingWithSuggestions) {
                // Keep the input focus alive while user is selecting a suggestion
                requestAnimationFrame(() => barcodeInput.focus());
                return;
            }
            // Check if the focus is moving to an element within the suggestions container
            if (customSuggestions && !customSuggestions.contains(event.relatedTarget)) {
                hideSuggestions();
            }
        });

        if (customSuggestions) {
            const resetSuggestionInteraction = () => {
                isInteractingWithSuggestions = false;
            };

            customSuggestions.addEventListener('pointerdown', (event) => {
                if (event.target.closest('li[data-product-id]')) {
                    isInteractingWithSuggestions = true;
                    event.preventDefault();
                }
            });
            customSuggestions.addEventListener('pointerup', resetSuggestionInteraction);
            customSuggestions.addEventListener('pointercancel', resetSuggestionInteraction);
            customSuggestions.addEventListener('mouseleave', resetSuggestionInteraction);

            customSuggestions.addEventListener('click', (event) => {
                const clickedLi = event.target.closest('li[data-product-id]');
                if (clickedLi) {
                    const productId = clickedLi.dataset.productId;
                    const product = currentSuggestions.find(p => String(p.id) === productId);
                    if (product) {
                        selectSuggestion(product);
                    }
                }
            });
        }
    }

    const updateSelectedSuggestion = () => {
        const suggestionItems = customSuggestions.querySelectorAll('li');
        suggestionItems.forEach((item, index) => {
            if (index === selectedSuggestionIndex) {
                item.classList.add('selected');
                // Scroll into view if necessary
                item.scrollIntoView({ block: 'nearest' });
            } else {
                item.classList.remove('selected');
            }
        });
    };

    // Original handleScannedBarcode logic, modified to use product ID for resolution
    const handleScannedBarcode = (value) => {
        const trimmedValue = (value ?? '').toString().trim();
        let product = null;

        // Try to resolve by product ID first (from suggestion selection)
        if (productMap.has(trimmedValue)) {
            product = productMap.get(trimmedValue);
        } else if (productMap.has(normalizeBarcode(trimmedValue))) { // Then by barcode
            product = productMap.get(normalizeBarcode(trimmedValue));
        } else { // Finally by name (for direct entry without selection)
            const lowerValue = trimmedValue.toLowerCase();
            const exactMatches = productCatalog.filter(p => p.name && p.name.toLowerCase() === lowerValue);
            if (exactMatches.length === 1) {
                product = exactMatches[0];
            } else {
                showBarcodeFeedback(`Barang "${trimmedValue}" tidak ditemukan.`);
                return;
            }
        }

        if (!product) {
            showBarcodeFeedback(`Barang "${trimmedValue}" tidak ditemukan.`);
            return;
        }

        // Cek Kadaluarsa
        if (product.earliest_expiry) {
            const expiryDate = new Date(product.earliest_expiry);
            const today = new Date();
            today.setHours(0, 0, 0, 0); // Set to start of day
            if (expiryDate < today) {
                showBarcodeFeedback(`Barang "${product.name}" sudah kadaluarsa pada ${product.earliest_expiry}.`);
                return;
            }
        }

        if (!Number.isFinite(product.stock_available) || product.stock_available <= 0) {
            showBarcodeFeedback(`Stok "${product.name}" habis, tidak bisa ditambahkan ke transaksi.`);
            return;
        }

        const remainingStock = getRemainingStockForProduct(product);
        if (remainingStock <= 0) {
            showBarcodeFeedback(`Stok "${product.name}" tersisa 0 untuk transaksi ini. Kurangi atau hapus barang sebelum menambah lagi.`);
            if (barcodeInput) {
                barcodeInput.value = '';
                barcodeInput.focus();
            }
            return;
        }

        hideBarcodeFeedback();

        const existingRow = findRowByProductId(product.id);

        let targetRow = existingRow;
        if (!targetRow) {
            targetRow = createRow(product);
            clampRowQuantityToStock(targetRow);
        } else if (targetRow) {
            const quantityInput = targetRow.querySelector('.quantity-input');
            const priceInput = targetRow.querySelector('.price-input');
            const nextQuantity = (parseFloat(quantityInput.value) || 0) + 1;
            const maxStock = Number(product.stock_available ?? 0);
            if (Number.isFinite(maxStock) && maxStock > 0 && nextQuantity > maxStock) {
                showBarcodeFeedback(`Stok "${product.name}" tersisa ${maxStock}. Tidak bisa menambahkan lebih banyak.`);
                if (barcodeInput) {
                    barcodeInput.value = '';
                    barcodeInput.focus();
                }
                return;
            }
            quantityInput.value = nextQuantity;
            if ((!priceInput.value || priceInput.value === '0') && product.price) {
                priceInput.value = product.price;
            }
            updateRow(targetRow);
            clampRowQuantityToStock(targetRow);
        }

        flashRow(targetRow);
        if (barcodeInput) {
            barcodeInput.value = '';
            barcodeInput.focus();
        }
    };

    ensureEmptyState();
    handlePaymentMethodChange();

    if (cashPaidInput) {
        cashPaidInput.addEventListener('input', () => updateTotal());
    }

    if (mobileCashButton && cashPaidInput) {
        mobileCashButton.addEventListener('click', () => {
            if (cashPaidInput.disabled) {
                return;
            }
            cashPaidInput.focus();
            if (typeof cashPaidInput.select === 'function') {
                cashPaidInput.select();
            }
            cashPaidInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    }

    if (paymentMethodSelect) {
        paymentMethodSelect.addEventListener('change', handlePaymentMethodChange);
    }

    if (pointsUsedInput) {
        pointsUsedInput.addEventListener('input', () => updateTotal());
    }

    if (barcodeClear) {
        barcodeClear.addEventListener('click', () => {
            if (!barcodeInput) {
                return;
            }
            barcodeInput.value = '';
            hideBarcodeFeedback();
            hideSuggestions();
            barcodeInput.focus();
        });
    }

    document.addEventListener('keydown', (event) => {
        if (event.key === 'F9' && barcodeInput) {
            event.preventDefault();
            barcodeInput.focus();
        }
    });

    document.addEventListener('barcode-scanned', (event) => {
        if (event.detail?.targetId === 'barcode-input') {
            handleScannedBarcode(event.detail.value);
        }
    });

    // --- Member Search Logic --- //
    const memberSearchInput = document.getElementById('member-search-input');
    const memberSuggestions = document.getElementById('member-suggestions');
    const memberIdInput = document.getElementById('member_id');
    const memberSearchContainer = document.getElementById('member-search-container');
    const selectedMemberDisplay = document.getElementById('selected-member-display');
    const memberNameDisplay = document.getElementById('member-name-display');
    const memberPointsDisplay = document.getElementById('member-points-display');
    const removeMemberBtn = document.getElementById('remove-member-btn');

    let memberSuggestionsCache = [];
    let selectedMemberSuggestionIndex = -1;

    const hideMemberSuggestions = () => {
        memberSuggestions.innerHTML = '';
        memberSuggestions.style.display = 'none';
        selectedMemberSuggestionIndex = -1;
    };

    const selectMember = (member) => {
        memberIdInput.value = member.id;
        memberNameDisplay.textContent = member.text;
        memberPointsDisplay.textContent = `(${member.points} poin)`;
        if (pointsUsedInput) {
            pointsUsedInput.max = member.points;
            pointsUsedInput.disabled = false;
            if (pointsUsedInput.value !== '') {
                const existingPoints = parseFloat(pointsUsedInput.value);
                if (Number.isFinite(existingPoints)) {
                    pointsUsedInput.value = Math.min(existingPoints, member.points);
                }
            }
        }

        memberSearchContainer.hidden = true;
        selectedMemberDisplay.hidden = false;
        memberSearchInput.value = '';
        hideMemberSuggestions();
        updateTotal();
    };

    if (removeMemberBtn) {
        removeMemberBtn.addEventListener('click', () => {
            memberIdInput.value = '';
            if (pointsUsedInput) {
                pointsUsedInput.value = 0;
                pointsUsedInput.max = 0;
                pointsUsedInput.disabled = true;
            }

            memberSearchContainer.hidden = false;
            selectedMemberDisplay.hidden = true;
            memberSearchInput.focus();
            updateTotal();
        });
    }

    memberSearchInput.addEventListener('input', async () => {
        const query = memberSearchInput.value.trim();
        if (query.length < 1) {
            hideMemberSuggestions();
            return;
        }

        try {
            const response = await fetch(`<?= BASE_URL ?>/actions/search_member.php?q=${encodeURIComponent(query)}`);
            if (!response.ok) throw new Error('Network response was not ok');
            
            const members = await response.json();
            memberSuggestionsCache = members;
            
            if (members.length === 0) {
                hideMemberSuggestions();
                return;
            }

            const ul = document.createElement('ul');
            members.forEach(member => {
                const li = document.createElement('li');
                li.textContent = member.text;
                li.addEventListener('click', () => selectMember(member));
                ul.appendChild(li);
            });

            memberSuggestions.innerHTML = '';
            memberSuggestions.appendChild(ul);
            memberSuggestions.style.display = 'block';
            selectedMemberSuggestionIndex = -1;

        } catch (error) {
            console.error("Failed to fetch members:", error);
            hideMemberSuggestions();
        }
    });

    memberSearchInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            if (selectedMemberSuggestionIndex > -1 && memberSuggestionsCache[selectedMemberSuggestionIndex]) {
                selectMember(memberSuggestionsCache[selectedMemberSuggestionIndex]);
            }
        } else if (event.key === 'Escape') {
            hideMemberSuggestions();
        } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const items = memberSuggestions.querySelectorAll('li');
            if (items.length === 0) return;

            if (event.key === 'ArrowDown') {
                selectedMemberSuggestionIndex = (selectedMemberSuggestionIndex + 1) % items.length;
            } else {
                selectedMemberSuggestionIndex = (selectedMemberSuggestionIndex - 1 + items.length) % items.length;
            }

            items.forEach((item, index) => {
                item.classList.toggle('selected', index === selectedMemberSuggestionIndex);
                if (index === selectedMemberSuggestionIndex) {
                    item.scrollIntoView({ block: 'nearest' });
                }
            });
        }
    });

    memberSearchInput.addEventListener('blur', () => {
        setTimeout(hideMemberSuggestions, 150); // Delay to allow click
    });

    // Initially disable points input
    if (pointsUsedInput) {
        pointsUsedInput.disabled = true;
    }

    // --- Background Printing Logic --- //
    const handleBackgroundPrint = (action, saleId) => {
        let existingFrame = document.getElementById('print-frame');
        if (existingFrame) {
            existingFrame.remove();
        }

        const iframe = document.createElement('iframe');
        iframe.id = 'print-frame';
        iframe.style.display = 'none';
        iframe.src = `<?= BASE_URL ?>/actions/print_struk.php?sale_id=${saleId}`;
        
        document.body.appendChild(iframe);

        iframe.onload = () => {
            try {
                setTimeout(() => {
                    if (action === 'print_pc') {
                        iframe.contentWindow.print();
                    } else if (action === 'print_app') {
                        if (typeof iframe.contentWindow.printViaApp === 'function') {
                            const receiptText = iframe.contentWindow.printViaApp();
                            if (receiptText) {
                                const sender = typeof window.sendToKasirPrinter === 'function'
                                    ? window.sendToKasirPrinter(receiptText)
                                    : (() => {
                                        const encodedText = encodeURIComponent(receiptText);
                                        window.location.href = `kasirprinter://print?text=${encodedText}`;
                                        return true;
                                    })();
                                if (!sender) {
                                    alert('Gagal mengirim data struk ke aplikasi kasir.');
                                }
                            } else {
                                alert('Gagal membuat teks struk untuk dicetak via aplikasi.');
                            }
                        } else {
                            alert('ERROR: Fungsi cetak via aplikasi (printViaApp) tidak ditemukan.');
                        }
                    }
                }, 300);
            } catch (e) {
                console.error('Gagal mengakses konten cetak:', e);
                alert(`Terjadi kesalahan saat mengakses konten cetak. Pesan: ${e.message}`);
            } finally {
                setTimeout(() => {
                    iframe.remove();
                }, 2000);
            }
        };

        iframe.onerror = () => {
            alert('ERROR: Gagal memuat frame untuk mencetak. Periksa koneksi atau pengaturan server.');
        };
    };

    const summarySection = document.querySelector('.success-summary');
    if (summarySection) {
        summarySection.addEventListener('click', (event) => {
            const target = event.target.closest('[data-print-action]');
            if (target) {
                event.preventDefault();
                const action = target.dataset.printAction;
                const saleId = target.dataset.saleId;
                if (action && saleId) {
                    handleBackgroundPrint(action, saleId);
                }
            }
        });
    }
</script>
