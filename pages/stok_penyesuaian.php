<?php

if (!function_exists('ensure_csrf_token')) {
    require_once __DIR__ . '/../config/auth.php';
    require_once __DIR__ . '/../includes/fungsi.php';
}

require_once __DIR__ . '/../includes/approval_helpers.php';

ensure_csrf_token();

$pdo = get_db_connection();
ensure_stock_request_schema($pdo);

// SELF-HEALING: Ensure 'expired' enum exists
try {
    $pdo->exec("
        ALTER TABLE stock_adjustments 
        MODIFY COLUMN adjustment_type 
        ENUM('initial', 'purchase', 'sale', 'return', 'adjust', 'transfer', 'convert_in', 'convert_out', 'expired') 
        NOT NULL
    ");
} catch (Throwable $e) {
    // Squelch schema update errors (likely already exists)
}

$products = $pdo->query("SELECT id, name, barcode FROM products ORDER BY name ASC")->fetchAll();
$productCatalog = array_map(function ($product) {
    return [
        'id' => (int) $product['id'],
        'name' => $product['name'],
        'barcode' => $product['barcode'],
    ];
}, $products);

// Fetch expired batches
$expiredBatches = $pdo->query("
    SELECT b.*, p.name AS product_name 
    FROM product_batches b
    INNER JOIN products p ON p.id = b.product_id
    WHERE b.expiry_date IS NOT NULL 
      AND b.expiry_date < CURDATE() 
      AND b.stock_remaining > 0
    ORDER BY b.expiry_date ASC
")->fetchAll();

?>
<style>
    .autocomplete-wrapper { position: relative; }
    .autocomplete-results {
        position: absolute; top: 100%; left: 0; right: 0;
        background: #fff; border: 1px solid #cbd5e1; border-radius: 0 0 6px 6px;
        max-height: 240px; overflow-y: auto; z-index: 50;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }
    .autocomplete-results[hidden] { display: none; }
    .autocomplete-item {
        padding: 0.6rem 0.75rem; cursor: pointer; border-bottom: 1px solid #f1f5f9; font-size: 0.95rem;
    }
    .autocomplete-item:last-child { border-bottom: none; }
    .autocomplete-item:hover { background-color: #f8fafc; color: #2563eb; }
</style>

<?php if ($expiredBatches): ?>
    <section class="card" style="margin-bottom: 1.5rem; border: 1px solid #fca5a5;">
        <h2 style="color: #b91c1c;">Peringatan Barang Kadaluarsa</h2>
        <div class="alert error" style="margin-bottom: 1rem;">
            Ditemukan <strong><?= count($expiredBatches) ?> batch</strong> barang yang telah melewati tanggal kadaluarsa dan masih memiliki stok.
        </div>
        <table class="table">
            <thead>
            <tr>
                <th>Produk</th>
                <th>Kode Batch</th>
                <th>Tgl Kadaluarsa</th>
                <th>Sisa Stok</th>
                <th>Harga Beli</th>
                <th>Aksi</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($expiredBatches as $batch): ?>
                <tr>
                    <td><?= sanitize($batch['product_name']) ?></td>
                    <td><?= sanitize($batch['batch_code']) ?></td>
                    <td style="color: #dc2626; font-weight: bold;"><?= format_date($batch['expiry_date']) ?></td>
                    <td><?= (int) $batch['stock_remaining'] ?></td>
                    <td><?= format_rupiah($batch['purchase_price']) ?></td>
                    <td>
                        <form method="post" action="<?= BASE_URL ?>/actions/hapus_stok_kadaluarsa.php" onsubmit="return confirm('Apakah Anda yakin ingin memusnahkan semua stok pada batch ini? Tindakan ini akan mencatat pengeluaran dan tidak dapat dibatalkan.');">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="batch_id" value="<?= $batch['id'] ?>">
                            <button type="submit" class="button danger small">Musnahkan & Catat Rugi</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php endif; ?>

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

            const productInput = adjustmentForm.querySelector('#product_adjust_search');
            const reasonField = adjustmentForm.querySelector('#reason');

            const productName = productInput && productInput.value.trim() !== ''
                ? productInput.value.trim()
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
