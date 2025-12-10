<?php

if (!function_exists('ensure_csrf_token')) {
    require_once __DIR__ . '/../config/auth.php';
    require_once __DIR__ . '/../includes/fungsi.php';
}

ensure_csrf_token();

$pdo = get_db_connection();
$categories = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
$editCategory = null;

if ($editId) {
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $editId]);
    $editCategory = $stmt->fetch();
}

?>

<section class="card">
    <h2><?= $editCategory ? 'Edit Kategori' : 'Tambah Kategori' ?></h2>
    <form method="post" action="<?= BASE_URL ?>/actions/kategori_simpan.php">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <?php if ($editCategory): ?>
            <input type="hidden" name="id" value="<?= $editCategory['id'] ?>">
        <?php endif; ?>
        <div class="form-group">
            <label for="name">Nama Kategori</label>
            <input type="text" id="name" name="name" required value="<?= sanitize($editCategory['name'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="description">Deskripsi</label>
            <textarea id="description" name="description" rows="3"><?= sanitize($editCategory['description'] ?? '') ?></textarea>
        </div>
        <button class="button" type="submit"><?= $editCategory ? 'Perbarui' : 'Simpan' ?></button>
    </form>
</section>

<section class="card" style="margin-top:1.5rem;">
    <h2>Kategori Barang</h2>
    <table class="table">
        <thead>
        <tr>
            <th>Nama</th>
            <th>Deskripsi</th>
            <th>Aksi</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($categories as $category): ?>
            <tr>
                <td><?= sanitize($category['name']) ?></td>
                <td><?= sanitize($category['description']) ?></td>
                <td class="table-actions">
                    <a class="button secondary" href="<?= BASE_URL ?>/index.php?page=kategori&edit=<?= $category['id'] ?>">Edit</a>
                    <form method="post" action="<?= BASE_URL ?>/actions/kategori_hapus.php" onsubmit="return confirm('Hapus kategori ini? Ini akan menghapus kategori dari semua barang terkait.');">
                        <input type="hidden" name="id" value="<?= $category['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <button class="button" type="submit" style="background-color:#b91c1c;">Hapus</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
