<?php

if (!function_exists('ensure_csrf_token')) {
    require_once __DIR__ . '/../config/auth.php';
    require_once __DIR__ . '/../includes/fungsi.php';
}

ensure_csrf_token();

$pdo = get_db_connection();

$products = $pdo->query("
    SELECT id, name, is_active
    FROM products
    WHERE is_active = 1
    ORDER BY name ASC
")->fetchAll();

$categories = $pdo->query("
    SELECT id, name
    FROM categories
    ORDER BY name ASC
")->fetchAll();

$promotions = $pdo->query("
    SELECT pr.*, p.name AS product_name
    FROM promotions pr
    LEFT JOIN products p ON p.id = pr.product_id
    ORDER BY pr.start_date DESC
")->fetchAll();

?>

<section class="card">
    <h2>Promo & Diskon</h2>
    <table class="table">
        <thead>
        <tr>
            <th>Produk</th>
            <th>Promo</th>
            <th>Tipe</th>
            <th>Nilai</th>
            <th>Periode</th>
            <th>Min Qty</th>
            <th>Status</th>
            <th>Aksi</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($promotions as $promo): ?>
            <tr>
                <td><?= sanitize($promo['product_name']) ?></td>
                <td><?= sanitize($promo['promo_name']) ?></td>
                <td><?= sanitize($promo['promo_type']) ?></td>
                <td><?= format_rupiah($promo['discount_value']) ?></td>
                <td><?= format_date($promo['start_date']) ?> - <?= format_date($promo['end_date']) ?></td>
                <td><?= (int) $promo['min_qty'] ?></td>
                <td>
                    <?php $promoActive = (int) ($promo['is_active'] ?? 0); ?>
                    <span class="badge <?= $promoActive ? 'success' : 'warning' ?>">
                        <?= $promoActive ? 'Aktif' : 'Nonaktif' ?>
                    </span>
                </td>
                <td>
                    <form method="post" action="<?= BASE_URL ?>/actions/simpan_promo.php">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="id" value="<?= $promo['id'] ?>">
                        <input type="hidden" name="mode" value="toggle">
                        <button class="button secondary" type="submit"><?= $promoActive ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h3 style="margin-top:1.5rem;">Tambah Promo Baru</h3>
    <form method="post" action="<?= BASE_URL ?>/actions/simpan_promo.php">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <div class="grid-2">
            <div id="product_selector" class="form-group">
                <label for="product_id">Produk</label>
                <select id="product_id" name="product_id">
                    <option value="">-- Pilih Produk --</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= $product['id'] ?>"><?= sanitize($product['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="category_selector" class="form-group" style="display: none;">
                <label for="category_id">Kategori</label>
                <select id="category_id" name="category_id">
                    <option value="">-- Pilih Kategori --</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= $category['id'] ?>"><?= sanitize($category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="promo_name">Nama Promo</label>
                <input type="text" id="promo_name" name="promo_name" required>
            </div>
        </div>
        <div class="grid-2">
            <div class="form-group">
                <label for="promo_type">Tipe</label>
                <select id="promo_type" name="promo_type">
                    <option value="item">Per Barang</option>
                    <option value="category">Per Kategori</option>
                    <option value="order">Per Transaksi</option>
                </select>
            </div>
            <div class="form-group">
                <label for="discount_value">Nilai Diskon</label>
                <input type="number" id="discount_value" name="discount_value" min="0" step="0.01" required>
            </div>
        </div>
        <div class="grid-2">
            <div class="form-group">
                <label for="start_date">Mulai</label>
                <input type="date" id="start_date" name="start_date" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="form-group">
                <label for="end_date">Selesai</label>
                <input type="date" id="end_date" name="end_date" value="<?= date('Y-m-d', strtotime('+7 day')) ?>" required>
            </div>
        </div>
        <div class="form-group">
            <label for="min_qty">Minimal Qty</label>
            <input type="number" id="min_qty" name="min_qty" min="1" value="1">
        </div>
        <button class="button" type="submit">Simpan Promo</button>
    </form>
</section>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const promoType = document.getElementById('promo_type');
        const productSelector = document.getElementById('product_selector');
        const categorySelector = document.getElementById('category_selector');
        const productId = document.getElementById('product_id');
        const categoryId = document.getElementById('category_id');
        const minQtyInput = document.getElementById('min_qty');

        function toggleSelectors() {
            if (promoType.value === 'item') {
                productSelector.style.display = 'block';
                categorySelector.style.display = 'none';
                productId.required = true;
                categoryId.required = false;
                minQtyInput.disabled = false;
            } else if (promoType.value === 'category') {
                productSelector.style.display = 'none';
                categorySelector.style.display = 'block';
                productId.required = false;
                categoryId.required = true;
                minQtyInput.disabled = false;
            } else { // order
                productSelector.style.display = 'block';
                categorySelector.style.display = 'none';
                productId.required = false;
                categoryId.required = false;
                minQtyInput.disabled = true;
            }
        }

        promoType.addEventListener('change', toggleSelectors);
        toggleSelectors(); // Initial call
    });
</script>
