<?php

if (!function_exists('ensure_csrf_token')) {
    require_once __DIR__ . '/../config/auth.php';
    require_once __DIR__ . '/../includes/fungsi.php';
}

ensure_csrf_token();

$pdo = get_db_connection();
$suppliers = $pdo->query("SELECT * FROM suppliers ORDER BY name ASC")->fetchAll();

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
$editSupplier = null;

if ($editId) {
    $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $editId]);
    $editSupplier = $stmt->fetch();
}

?>

<section class="card">
    <h2><?= $editSupplier ? 'Edit Pemasok' : 'Tambah Pemasok' ?></h2>
    <form method="post" action="<?= BASE_URL ?>/actions/pemasok_simpan.php">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <?php if ($editSupplier): ?>
            <input type="hidden" name="id" value="<?= $editSupplier['id'] ?>">
        <?php endif; ?>
        <div class="form-group">
            <label for="name">Nama Pemasok</label>
            <input type="text" id="name" name="name" required value="<?= sanitize($editSupplier['name'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="contact_person">Nama Kontak</label>
            <input type="text" id="contact_person" name="contact_person" value="<?= sanitize($editSupplier['contact_person'] ?? '') ?>">
        </div>
        <div class="grid-2">
            <div class="form-group">
                <label for="phone">Telepon</label>
                <input type="text" id="phone" name="phone" value="<?= sanitize($editSupplier['phone'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="<?= sanitize($editSupplier['email'] ?? '') ?>">
            </div>
        </div>
        <div class="form-group">
            <label for="address">Alamat</label>
            <textarea id="address" name="address" rows="3"><?= sanitize($editSupplier['address'] ?? '') ?></textarea>
        </div>
        <button class="button" type="submit"><?= $editSupplier ? 'Perbarui' : 'Simpan' ?></button>
    </form>
</section>

<section class="card" style="margin-top:1.5rem;">
    <h2>Data Pemasok</h2>
    <table class="table">
        <thead>
        <tr>
            <th>Nama</th>
            <th>Kontak</th>
            <th>Telepon</th>
            <th>Alamat</th>
            <th>Aksi</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($suppliers as $supplier): ?>
            <tr>
                <td><?= sanitize($supplier['name']) ?></td>
                <td><?= sanitize($supplier['contact_person']) ?></td>
                <td><?= sanitize($supplier['phone']) ?></td>
                <td><?= sanitize($supplier['address']) ?></td>
                <td class="table-actions">
                    <a class="button secondary" href="<?= BASE_URL ?>/index.php?page=pemasok&edit=<?= $supplier['id'] ?>">Edit</a>
                    <form method="post" action="<?= BASE_URL ?>/actions/pemasok_hapus.php" onsubmit="return confirm('Hapus pemasok ini?');">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="id" value="<?= $supplier['id'] ?>">
                        <button class="button" type="submit" style="background-color:#b91c1c;">Hapus</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
