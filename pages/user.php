<?php

if (!function_exists('ensure_csrf_token')) {
    require_once __DIR__ . '/../config/auth.php';
    require_once __DIR__ . '/../includes/fungsi.php';
}

ensure_csrf_token();

require_role(ROLE_MANAJER);

$pdo = get_db_connection();
$users = $pdo->query("SELECT id, username, full_name, role, is_active, last_login_at FROM users ORDER BY role DESC, username ASC")->fetchAll();

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
$editUser = null;
if ($editId) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $editId]);
    $editUser = $stmt->fetch();
}

?>

<section class="card">
    <h2><?= $editUser ? 'Edit Pengguna' : 'Tambah Pengguna' ?></h2>
    <form method="post" action="<?= BASE_URL ?>/actions/simpan_user.php">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <?php if ($editUser): ?>
            <input type="hidden" name="id" value="<?= $editUser['id'] ?>">
        <?php endif; ?>
        <div class="grid-2">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required value="<?= sanitize($editUser['username'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="full_name">Nama Lengkap</label>
                <input type="text" id="full_name" name="full_name" required value="<?= sanitize($editUser['full_name'] ?? '') ?>">
            </div>
        </div>
        <div class="grid-2">
            <div class="form-group">
                <label for="role">Role</label>
                <select id="role" name="role">
                    <option value="<?= ROLE_KASIR ?>" <?= ($editUser['role'] ?? ROLE_KASIR) === ROLE_KASIR ? 'selected' : '' ?>>Kasir</option>
                    <option value="<?= ROLE_MANAJER ?>" <?= ($editUser['role'] ?? '') === ROLE_MANAJER ? 'selected' : '' ?>>Manajer</option>
                    <option value="<?= ROLE_ADMIN ?>" <?= ($editUser['role'] ?? '') === ROLE_ADMIN ? 'selected' : '' ?>>Admin Super</option>
                </select>
            </div>
            <div class="form-group">
                <label for="is_active">Status</label>
                <select id="is_active" name="is_active">
                    <option value="1" <?= ($editUser['is_active'] ?? 1) ? 'selected' : '' ?>>Aktif</option>
                    <option value="0" <?= isset($editUser) && !$editUser['is_active'] ? 'selected' : '' ?>>Nonaktif</option>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label for="password">Kata Sandi <?= $editUser ? '(kosongkan jika tidak diganti)' : '' ?></label>
            <input type="password" id="password" name="password" <?= $editUser ? '' : 'required' ?>>
        </div>
        <button class="button" type="submit"><?= $editUser ? 'Perbarui' : 'Simpan' ?></button>
    </form>
</section>

<section class="card" style="margin-top:1.5rem;">
    <h2>Daftar Pengguna</h2>
    <table class="table">
        <thead>
        <tr>
            <th>Username</th>
            <th>Nama</th>
            <th>Role</th>
            <th>Status</th>
            <th>Login Terakhir</th>
            <th>Aksi</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $user): ?>
            <tr>
                <td><?= sanitize($user['username']) ?></td>
                <td><?= sanitize($user['full_name']) ?></td>
                <td><?= get_role_label($user['role']) ?></td>
                <td>
                    <span class="badge <?= $user['is_active'] ? 'success' : 'warning' ?>">
                        <?= $user['is_active'] ? 'Aktif' : 'Nonaktif' ?>
                    </span>
                </td>
                <td><?= $user['last_login_at'] ? format_date($user['last_login_at'], true) : '-' ?></td>
                <td>
                    <a class="button secondary" href="<?= BASE_URL ?>/index.php?page=user&edit=<?= $user['id'] ?>">Edit</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
