<?php

if (!function_exists('ensure_csrf_token')) {
    require_once __DIR__ . '/../config/auth.php';
    require_once __DIR__ . '/../includes/fungsi.php';
}

ensure_csrf_token();
$user = current_user();
$isAdmin = $user && $user['role'] === ROLE_ADMIN;

$settings = get_store_settings();
$logoUrl = '';
if (!empty($settings['logo_path'])) {
    $logoUrl = BASE_URL . '/' . ltrim($settings['logo_path'], '/');
}

?>

<section class="card">
    <h2>Pengaturan Toko</h2>
    <p class="muted">Perbarui identitas toko yang digunakan pada label harga dan struk transaksi.</p>

    <form method="post" action="<?= BASE_URL ?>/actions/simpan_toko.php" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

        <div class="form-group">
            <label for="store_name">Nama Toko</label>
            <input type="text" id="store_name" name="store_name" required maxlength="150" value="<?= sanitize($settings['store_name'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label for="address">Alamat</label>
            <textarea id="address" name="address" rows="3"><?= sanitize($settings['address'] ?? '') ?></textarea>
        </div>

        <div class="grid-2">
            <div class="form-group">
                <label for="phone">Nomor HP / Telepon</label>
                <input type="text" id="phone" name="phone" maxlength="40" value="<?= sanitize($settings['phone'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="notes">Catatan (muncul di struk)</label>
                <input type="text" id="notes" name="notes" maxlength="180" value="<?= sanitize($settings['notes'] ?? '') ?>">
            </div>
        </div>

        <div class="form-group">
            <label for="logo">Logo Toko (PNG/JPG, opsional)</label>
            <input type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/jpg">
            <?php if ($logoUrl): ?>
                <div style="margin-top:0.5rem;">
                    <img src="<?= $logoUrl ?>" alt="Logo Toko" style="max-height:80px;">
                </div>
                <label style="display:flex;align-items:center;gap:0.5rem;margin-top:0.5rem;">
                    <input type="checkbox" name="remove_logo" value="1">
                    Hapus logo
                </label>
            <?php endif; ?>
        </div>

        <button class="button" type="submit">Simpan Pengaturan</button>
        <?php if (!empty($settings['updated_at'])): ?>
            <p class="muted" style="margin-top:0.75rem;">Terakhir diperbarui: <?= format_date($settings['updated_at'], true) ?></p>
        <?php endif; ?>
    </form>
</section>

<?php if ($isAdmin): ?>
<section class="card" style="margin-top:1rem;">
    <h3>Wizard Setup</h3>
    <p class="muted">Jalankan ulang wizard instalasi untuk mengubah konfigurasi awal.</p>
    <form method="post" action="<?= BASE_URL ?>/actions/restart_setup.php" onsubmit="return confirm('Wizard akan dijalankan ulang. Lanjutkan?');">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <button class="button" type="submit">Buka Wizard Setup</button>
    </form>
</section>
<?php endif; ?>
