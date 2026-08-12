<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';

if (!function_exists('ensure_csrf_token')) {
    require_once __DIR__ . '/../includes/fungsi.php';
}

require_role(ROLE_MANAJER);

$pdo = get_db_connection();

// Ambil kategori untuk filter
$stmt_cat = $pdo->query("SELECT id, name FROM categories ORDER BY name ASC");
$categories = $stmt_cat->fetchAll();

$category_filter = (int)($_GET['category_id'] ?? 0);
$search = trim($_GET['search'] ?? '');

// Query barang per batch
$query = "SELECT p.id as product_id, p.name, p.barcode, c.name as category_name, 
                 pb.id as batch_id, pb.batch_code, pb.stock_remaining, pb.expiry_date
          FROM products p
          JOIN product_batches pb ON p.id = pb.product_id
          LEFT JOIN categories c ON p.category_id = c.id
          WHERE p.is_active = 1";

$params = [];
if ($category_filter > 0) {
    $query .= " AND p.category_id = :cat_id";
    $params[':cat_id'] = $category_filter;
}
if ($search !== '') {
    $query .= " AND (p.name LIKE :search OR p.barcode LIKE :search OR pb.batch_code LIKE :search)";
    $params[':search'] = "%$search%";
}

$query .= " ORDER BY p.name ASC, pb.received_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$items = $stmt->fetchAll();

ensure_csrf_token();
?>

<div class="container-fluid px-4">
    <h1 class="mt-4">Update Stok Cepat</h1>
    <ol class="breadcrumb mb-4">
        <li class="breadcrumb-item"><a href="index.php?page=dashboard">Dashboard</a></li>
        <li class="breadcrumb-item active">Update Stok Cepat</li>
    </ol>

    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="index.php" class="row g-3 mb-4">
                <input type="hidden" name="page" value="stok_cepat">
                <div class="col-md-3">
                    <label class="form-label">Filter Kategori</label>
                    <select name="category_id" class="form-select" onchange="this.form.submit()">
                        <option value="0">Semua Kategori</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= $category_filter == $cat['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Cari Nama/Barcode/Batch</label>
                    <div class="input-group">
                        <input type="text" name="search" class="form-control" value="<?= htmlspecialchars($search) ?>" placeholder="Masukkan kata kunci...">
                        <button class="btn btn-outline-secondary" type="submit">Cari</button>
                    </div>
                </div>
                <div class="col-md-5 d-flex align-items-end justify-content-end">
                    <a href="index.php?page=stok_cepat" class="btn btn-secondary me-2">Reset</a>
                </div>
            </form>

            <form action="actions/update_stok_massal.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                
                <div class="table-responsive">
                    <table class="table table-bordered table-hover align-middle">
                        <thead class="table-light text-center">
                            <tr>
                                <th width="50">No</th>
                                <th>Nama Barang</th>
                                <th>Barcode / Batch</th>
                                <th>Kategori</th>
                                <th width="120">Stok Saat Ini</th>
                                <th width="150" class="table-primary">Stok Baru</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($items)): ?>
                                <tr>
                                    <td colspan="6" class="text-center">Data tidak ditemukan.</td>
                                </tr>
                            <?php else: ?>
                                <?php $no = 1; foreach ($items as $item): ?>
                                    <tr>
                                        <td class="text-center"><?= $no++ ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars($item['name']) ?></strong><br>
                                            <small class="text-muted">Exp: <?= $item['expiry_date'] ?: '-' ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary"><?= htmlspecialchars($item['barcode'] ?: 'No Barcode') ?></span><br>
                                            <code class="small"><?= htmlspecialchars($item['batch_code']) ?></code>
                                        </td>
                                        <td class="text-center small"><?= htmlspecialchars($item['category_name'] ?: '-') ?></td>
                                        <td class="text-center fw-bold"><?= $item['stock_remaining'] ?></td>
                                        <td class="table-primary">
                                            <input type="number" 
                                                   name="stok[<?= $item['batch_id'] ?>]" 
                                                   class="form-control text-center fw-bold" 
                                                   value="<?= $item['stock_remaining'] ?>" 
                                                   step="any" 
                                                   min="0">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (!empty($items)): ?>
                <div class="mt-4 sticky-bottom bg-white p-3 border-top shadow-sm text-end">
                    <p class="text-muted small mb-2">* Perubahan stok akan dicatat sebagai penyesuaian manual.</p>
                    <button type="submit" class="btn btn-primary btn-lg px-5" onclick="return confirm('Apakah Anda yakin ingin memperbarui semua stok ini?')">
                        <i class="fas fa-save me-2"></i> SIMPAN SEMUA PERUBAHAN
                    </button>
                </div>
                <?php endif; ?>
            </form>
        </div>
    </div>
</div>
