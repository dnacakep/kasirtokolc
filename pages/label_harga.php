<?php

if (!function_exists('ensure_csrf_token')) {
    require_once __DIR__ . '/../config/auth.php';
    require_once __DIR__ . '/../includes/fungsi.php';
}

ensure_csrf_token();

$pdo = get_db_connection();
$pendingLabels = $pdo->query("
    SELECT b.id, p.name, p.barcode, b.sell_price, b.batch_code
    FROM product_batches b
    INNER JOIN products p ON p.id = b.product_id
    WHERE b.label_printed = 0
    ORDER BY p.name ASC
")->fetchAll();

$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name ASC")->fetchAll();

?>

<section class="card">
    <h2>Label Harga Pending</h2>

    <?php if (!$pendingLabels): ?>
        <p class="muted">Semua label sudah dicetak.</p>
    <?php else: ?>
        <form id="label-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <table class="table">
                <thead>
                <tr>
                    <th><input type="checkbox" id="select-all-labels"></th>
                    <th>Produk</th>
                    <th>Barcode</th>
                    <th>Harga</th>
                    <th>Batch</th>
                    <th>Jumlah Label</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($pendingLabels as $label): ?>
                    <tr>
                        <td>
                            <input type="checkbox" name="batch_ids[]" value="<?= $label['id'] ?>">
                        </td>
                        <td><?= sanitize($label['name']) ?></td>
                        <td><?= sanitize($label['barcode']) ?></td>
                        <td><?= format_rupiah($label['sell_price']) ?></td>
                        <td><?= sanitize($label['batch_code']) ?></td>
                        <td>
                            <input type="number" name="quantities[<?= $label['id'] ?>]" value="1" min="1" max="40" style="width:80px;">
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="print-actions" style="margin-top:1rem; display:flex; flex-wrap:wrap; gap:0.5rem;">
                <button class="button" type="submit" formaction="<?= BASE_URL ?>/actions/print_labels.php">
                    Cetak Label Terpilih
                </button>
                <button class="button secondary" type="submit" formaction="<?= BASE_URL ?>/actions/label_cetak.php">
                    Tandai Sudah Dicetak
                </button>
            </div>
        </form>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Cetak Label Massal per Kategori</h2>
    <form id="bulk-label-form" method="post" action="<?= BASE_URL ?>/actions/print_labels_by_category.php" style="display:flex; flex-direction:column; gap:1rem;">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        
        <div class="form-group">
            <label>Kategori</label>
            <div id="category-checkbox-container">
                <div class="checkbox-group">
                    <input type="checkbox" id="select-all-categories">
                    <label for="select-all-categories">Pilih Semua</label>
                </div>
                <?php foreach ($categories as $category): ?>
                    <div class="checkbox-group">
                        <input type="checkbox" name="category_ids[]" value="<?= (int) $category['id'] ?>" id="category-<?= (int) $category['id'] ?>">
                        <label for="category-<?= (int) $category['id'] ?>"><?= sanitize($category['name']) ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="form-group" style="max-width:180px;">
            <label for="manual_quantity">Jumlah Label per Produk</label>
            <input type="number" id="manual_quantity" name="quantity_per_product" value="1" min="1" max="40" required>
        </div>
        <p class="muted" style="margin:0;">Fitur ini akan mencetak label untuk semua produk dalam kategori yang dipilih, berdasarkan batch terbaru.</p>
        <div>
            <button class="button" type="submit">Cetak Label per Kategori</button>
        </div>
    </form>
</section>

<script>
    (function() {
        const selectAll = document.getElementById('select-all-labels');
        if (!selectAll) return;
        const form = document.getElementById('label-form');
        const checkboxes = form.querySelectorAll('input[type="checkbox"][name="batch_ids[]"]');

        selectAll.addEventListener('change', () => {
            checkboxes.forEach(cb => cb.checked = selectAll.checked);
        });
    })();

    (function() {
        const selectAllCategories = document.getElementById('select-all-categories');
        if (!selectAllCategories) return;
        const bulkForm = document.getElementById('bulk-label-form');
        const categoryCheckboxes = bulkForm.querySelectorAll('input[type="checkbox"][name="category_ids[]"]');

        selectAllCategories.addEventListener('change', () => {
            categoryCheckboxes.forEach(cb => cb.checked = selectAllCategories.checked);
        });
    })();
</script>
