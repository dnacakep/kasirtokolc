<?php

if (!function_exists('ensure_csrf_token')) {
    require_once __DIR__ . '/../config/auth.php';
    require_once __DIR__ . '/../includes/fungsi.php';
}

ensure_csrf_token();

$pdo = get_db_connection();

$searchTerm = trim($_GET['search'] ?? '');
$categoryFilter = $_GET['category'] ?? '';
$statusFilter = $_GET['status'] ?? '';

$conditions = [];
$params = [];

if ($searchTerm !== '') {
    $conditions[] = "(p.name LIKE :keyword OR p.barcode LIKE :keyword)";
    $params[':keyword'] = '%' . $searchTerm . '%';
}

if ($categoryFilter !== '' && $categoryFilter !== 'all') {
    $conditions[] = "p.category_id = :category_id";
    $params[':category_id'] = (int) $categoryFilter;
}

if ($statusFilter === 'active') {
    $conditions[] = "p.is_active = 1";
} elseif ($statusFilter === 'inactive') {
    $conditions[] = "p.is_active = 0";
}

$query = "
    SELECT p.*, c.name AS category_name, COALESCE(SUM(b.stock_remaining), 0) AS stock_total
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN product_batches b ON b.product_id = p.id
";

if ($conditions) {
    $query .= ' WHERE ' . implode(' AND ', $conditions);
}

$query .= "
    GROUP BY p.id, c.name
    ORDER BY p.name ASC
";

$stmtProducts = $pdo->prepare($query);
$stmtProducts->execute($params);
$products = $stmtProducts->fetchAll();

$categories = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();
$flashMessage = consume_flash_message();

?>

<section class="card">
    <h2>Daftar Barang</h2>
    <p class="muted">Kelola data barang dengan filter, impor CSV, dan edit cepat melalui tampilan kartu.</p>
    <div style="margin-bottom:1rem; display:flex; flex-wrap:wrap; gap:0.75rem;">
        <a class="button secondary" href="<?= BASE_URL ?>/index.php?page=barang">Tambah Barang</a>
        <a class="button secondary" href="<?= BASE_URL ?>/index.php?page=stok">Manajemen Stok</a>
    </div>

    <form class="filter-form" method="get" action="<?= BASE_URL ?>/index.php" style="margin-bottom:1rem;">
        <input type="hidden" name="page" value="barang_list">
        <div class="grid-3">
            <div class="form-group">
                <label for="filter_search">Cari Nama / Barcode</label>
                <input type="text" id="filter_search" name="search" value="<?= sanitize($searchTerm) ?>" placeholder="contoh: indomie">
            </div>
            <div class="form-group">
                <label for="filter_category">Kategori</label>
                <select id="filter_category" name="category">
                    <option value="all">Semua Kategori</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= $category['id'] ?>" <?= ($categoryFilter !== '' && (int) $categoryFilter === (int) $category['id']) ? 'selected' : '' ?>>
                            <?= sanitize($category['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="filter_status">Status</label>
                <select id="filter_status" name="status">
                    <option value="">Semua Status</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Aktif</option>
                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Nonaktif</option>
                </select>
            </div>
        </div>
        <div class="form-actions" style="display:flex; gap:0.75rem; flex-wrap:wrap;">
            <button class="button" type="submit">Terapkan Filter</button>
            <a class="button secondary" href="<?= BASE_URL ?>/index.php?page=barang_list">Reset</a>
        </div>
    </form>

    <div class="import-panel">
        <h3>Import CSV</h3>
        <p class="muted">
            Upload file CSV dengan kolom: <code>barcode</code>, <code>nama</code>, <code>kategori</code>, <code>satuan</code>, <code>stok_minimum</code>, <code>poin</code>, <code>deskripsi</code>.
            Baris dengan barcode yang sudah ada akan diperbarui.
        </p>
        <form method="post" action="<?= BASE_URL ?>/actions/import_barang.php" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <div class="form-group">
                <label for="barang_csv">File CSV</label>
                <input type="file" id="barang_csv" name="barang_csv" accept=".csv,text/csv" required>
            </div>
            <div class="form-group">
                <label for="delimiter">Delimiter</label>
                <select id="delimiter" name="delimiter">
                    <option value=",">Koma (,)</option>
                    <option value=";">Titik Koma (;)</option>
                </select>
            </div>
            <button class="button secondary" type="submit">Import CSV</button>
            <a class="button secondary" href="<?= BASE_URL ?>/backup/contoh_import_barang.csv" download>Contoh CSV</a>
        </form>
    </div>

    <?php if ($flashMessage): ?>
        <div class="alert <?= $flashMessage['type'] === 'error' ? 'alert-error' : 'alert-success' ?>">
            <?= sanitize($flashMessage['text']) ?>
        </div>
    <?php endif; ?>

    <?php if (!$products): ?>
        <p class="muted" style="margin-top:1rem;">Belum ada barang yang cocok dengan filter.</p>
    <?php else: ?>
        <div class="product-card-grid">
            <?php foreach ($products as $product): ?>
                <?php
                $initialSource = (string) ($product['name'] ?? '');
                if ($initialSource !== '') {
                    if (function_exists('mb_substr')) {
                        $firstChar = mb_substr($initialSource, 0, 1, 'UTF-8');
                    } else {
                        $firstChar = substr($initialSource, 0, 1);
                    }
                    if ($firstChar === false || $firstChar === '') {
                        $initial = '#';
                    } else {
                        if (function_exists('mb_strtoupper')) {
                            $initial = mb_strtoupper($firstChar, 'UTF-8');
                        } else {
                            $initial = strtoupper($firstChar);
                        }
                    }
                } else {
                    $initial = '#';
                }
                ?>
                <form class="product-card" method="post" action="<?= BASE_URL ?>/actions/update_barang_card.php" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
                    <div class="product-card__media">
                        <?php if (!empty($product['image_path'])): ?>
                            <img src="<?= BASE_URL . '/' . $product['image_path'] ?>" alt="<?= sanitize($product['name']) ?>">
                        <?php else: ?>
                            <div class="product-card__placeholder" aria-hidden="true">
                                <?= sanitize($initial) ?>
                            </div>
                        <?php endif; ?>
                        <label class="button button-small secondary product-card__upload">
                            <input type="file" name="image" accept="image/jpeg,image/png,image/webp" capture="environment">
                            Ganti Foto
                        </label>
                        <p class="muted product-card__hint">Maksimum 10 MB. Kosongkan jika tidak ingin mengubah.</p>
                        <?php if (!empty($product['image_path'])): ?>
                            <label class="checkbox remove-image-checkbox">
                                <input type="checkbox" name="remove_image" value="1">
                                <span>Hapus foto saat ini</span>
                            </label>
                        <?php endif; ?>
                    </div>
                    <div class="product-card__body">
                        <div class="form-group">
                            <label>Barcode</label>
                            <input type="text" name="barcode" value="<?= sanitize($product['barcode'] ?? '') ?>" pattern="[0-9]*" inputmode="numeric">
                        </div>
                        <div class="form-group">
                            <label>Nama Barang</label>
                            <input type="text" name="name" value="<?= sanitize($product['name'] ?? '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Kategori</label>
                            <select name="category_id">
                                <option value="">-</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= $category['id'] ?>" <?= ($product['category_id'] ?? null) == $category['id'] ? 'selected' : '' ?>>
                                        <?= sanitize($category['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Satuan</label>
                            <input type="text" name="unit" value="<?= sanitize($product['unit'] ?? '') ?>">
                        </div>
                        <div class="grid-2">
                            <div class="form-group">
                                <label>Stok Minimum</label>
                                <input type="number" name="stock_minimum" min="0" step="1" value="<?= (int) ($product['stock_minimum'] ?? 0) ?>">
                            </div>
                            <div class="form-group">
                                <label>Poin</label>
                                <input type="number" name="points_reward" min="0" step="1" value="<?= (int) ($product['points_reward'] ?? 0) ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Deskripsi</label>
                            <textarea name="description" rows="2"><?= sanitize($product['description'] ?? '') ?></textarea>
                        </div>
                        <div class="product-card__status">
                            <label class="checkbox">
                                <input type="checkbox" name="is_active" value="1" <?= ($product['is_active'] ?? 0) == 1 ? 'checked' : '' ?>>
                                <span>Barang aktif</span>
                            </label>
                            <span class="product-card__stock">Stok total: <strong><?= number_format((float) $product['stock_total'], 0, ',', '.') ?></strong></span>
                        </div>
                    </div>
                    <div class="product-card__footer">
                        <button type="submit" class="button">Simpan</button>
                        <button type="reset" class="button secondary">Reset</button>
                    </div>
                </form>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<style>
.product-card-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 0.75rem;
    margin-top: 1rem;
}

.product-card {
    border: 1px solid var(--border-subtle);
    border-radius: 0.85rem;
    padding: 0.75rem;
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
    background: #fff;
}

.product-card__media {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
    align-items: flex-start;
}

.product-card__media img {
    width: 100%;
    aspect-ratio: 1 / 1;
    object-fit: cover;
    border-radius: 0.65rem;
    border: 1px solid var(--border-subtle);
    background: #fafafa;
}

.product-card__placeholder {
    width: 100%;
    aspect-ratio: 1 / 1;
    display: grid;
    place-items: center;
    border-radius: 0.65rem;
    border: 1px dashed var(--border-subtle);
    background: #f6f6f9;
    font-size: 2rem;
    font-weight: 600;
    color: #7a7a7a;
}

.product-card__upload {
    position: relative;
    overflow: hidden;
    cursor: pointer;
    padding-inline: 0.65rem;
}

.product-card__upload input[type="file"] {
    position: absolute;
    inset: 0;
    opacity: 0;
    cursor: pointer;
}

.product-card__hint {
    font-size: 0.78rem;
}

.product-card__body .form-group {
    margin-bottom: 0.5rem;
}

.product-card__status {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 0.4rem;
    flex-wrap: wrap;
}

.product-card__stock strong {
    font-size: 0.95rem;
}

.product-card__footer {
    display: flex;
    gap: 0.4rem;
    justify-content: flex-end;
}

.product-card .button {
    padding-inline: 0.65rem;
}

.alert {
    margin-top: 0.75rem;
    padding: 0.65rem 0.9rem;
    border-radius: 0.65rem;
    transition: opacity 0.3s ease;
}

.alert--hiding {
    opacity: 0;
    pointer-events: none;
}

.alert-success {
    background: #e6f6ec;
    color: #1a6a3b;
    border: 1px solid #c2ebd0;
}

.alert-error {
    background: #fdecea;
    color: #ab1f16;
    border: 1px solid #f5c6c2;
}

.remove-image-checkbox {
    margin-top: 0.2rem;
}

@media (max-width: 720px) {
    .product-card-grid {
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    }
}
</style>
