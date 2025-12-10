<?php

if (!function_exists('ensure_csrf_token')) {
    require_once __DIR__ . '/../config/auth.php';
    require_once __DIR__ . '/../includes/fungsi.php';
}

ensure_csrf_token();

$pdo = get_db_connection();
$members = $pdo->query("SELECT * FROM members ORDER BY created_at DESC")->fetchAll();

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
$editMember = null;

if ($editId) {
    $stmt = $pdo->prepare("SELECT * FROM members WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $editId]);
    $editMember = $stmt->fetch();
}

$pointHistory = [];
if ($editMember) {
    $stmt = $pdo->prepare("
        SELECT mp.*, s.invoice_code
        FROM member_points mp
        LEFT JOIN sales s ON s.id = mp.sale_id
        WHERE mp.member_id = :id
        ORDER BY mp.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([':id' => $editMember['id']]);
    $pointHistory = $stmt->fetchAll();
}

?>

<div class="grid-2">
    <section class="card">
        <h2><?= $editMember ? 'Edit Member' : 'Tambah Member' ?></h2>
        <form method="post" action="<?= BASE_URL ?>/actions/simpan_member.php">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <?php if ($editMember): ?>
                <input type="hidden" name="id" value="<?= $editMember['id'] ?>">
            <?php endif; ?>
            <div class="form-group">
                <label for="member_code">Kode Member</label>
                <input type="text" id="member_code" name="member_code" value="<?= sanitize($editMember['member_code'] ?? 'Akan dibuat otomatis') ?>" readonly style="background-color: #f3f4f6;">
                <?php if (!$editMember): ?>
                    <small class="muted">Kode member akan dibuat secara otomatis oleh sistem.</small>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label for="name">Nama</label>
                <input type="text" id="name" name="name" required value="<?= sanitize($editMember['name'] ?? '') ?>">
            </div>
            <div class="grid-2">
                <div class="form-group">
                    <label for="phone">Telepon</label>
                    <input type="text" id="phone" name="phone" value="<?= sanitize($editMember['phone'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?= sanitize($editMember['email'] ?? '') ?>">
                </div>
            </div>
            <div class="form-group">
                <label for="address">Alamat</label>
                <textarea id="address" name="address" rows="3"><?= sanitize($editMember['address'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="active" <?= ($editMember['status'] ?? '') === 'active' ? 'selected' : '' ?>>Aktif</option>
                    <option value="inactive" <?= ($editMember['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Nonaktif</option>
                </select>
            </div>
            <button class="button" type="submit"><?= $editMember ? 'Perbarui' : 'Simpan' ?></button>
        </form>
    </section>

    <?php if ($editMember): ?>
        <section class="card">
            <h2>Riwayat Poin</h2>
            <?php if (!$pointHistory): ?>
                <p class="muted">Belum ada transaksi poin.</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Perubahan</th>
                        <th>Keterangan</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pointHistory as $history): ?>
                        <tr>
                            <td><?= format_date($history['created_at'], true) ?></td>
                            <td><?= (int) $history['points_change'] ?></td>
                            <td><?= sanitize($history['description'] ?? $history['invoice_code']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>

<section class="card" style="margin-top:1.5rem;">
    <h2>Daftar Member</h2>
    <table class="table">
        <thead>
        <tr>
            <th>Kode</th>
            <th>Nama</th>
            <th>Telepon</th>
            <th>Poin</th>
            <th>Status</th>
            <th>Aksi</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($members as $member): ?>
            <tr>
                <td><?= sanitize($member['member_code']) ?></td>
                <td><?= sanitize($member['name']) ?></td>
                <td><?= sanitize($member['phone']) ?></td>
                <td><?= (int) $member['points_balance'] ?></td>
                <td>
                    <span class="badge <?= $member['status'] === 'active' ? 'success' : 'warning' ?>">
                        <?= ucfirst($member['status']) ?>
                    </span>
                </td>
                <td class="table-actions">
                    <a class="button secondary" href="<?= BASE_URL ?>/index.php?page=member&edit=<?= $member['id'] ?>">Detail</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
