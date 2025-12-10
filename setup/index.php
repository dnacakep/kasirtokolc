<?php

require_once __DIR__ . '/../config/setup_state.php';

if (setup_is_completed()) {
    $target = (setup_base_url() ?: '') . '/pages/login.php';
    header('Location: ' . $target);
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$flash = setup_consume_flash();

$old = setup_old_form();

if (empty($_SESSION['setup_csrf'])) {
    $_SESSION['setup_csrf'] = bin2hex(random_bytes(16));
}

$config = setup_load_config();

$baseUrl = $old['base_url'] ?? $config['base_url'] ?? setup_guess_base_url();
$dbConfig = $config['db'] ?? [];
$storeConfig = $config['store'] ?? [];

$dbDefaults = [
    'host' => 'localhost',
    'port' => 3306,
    'database' => 'tokolc',
    'username' => 'tokolc',
    'password' => '',
    'charset' => 'utf8mb4',
];

$dbConfig = array_merge($dbDefaults, $dbConfig);

$storeNameValue = $old['store_name'] ?? ($storeConfig['name'] ?? 'Kasir Minimarket');
$storePhoneValue = $old['store_phone'] ?? ($storeConfig['phone'] ?? '');
$storeAddressValue = $old['store_address'] ?? ($storeConfig['address'] ?? '');

$dbHostValue = $old['db_host'] ?? $dbConfig['host'];
$dbPortValue = $old['db_port'] ?? $dbConfig['port'];
$dbNameValue = $old['db_name'] ?? $dbConfig['database'];
$dbUserValue = $old['db_username'] ?? $dbConfig['username'];
$dbPassValue = $old['db_password'] ?? $dbConfig['password'];
$dbCharsetValue = $old['db_charset'] ?? $dbConfig['charset'];

$adminFullNameValue = $old['admin_full_name'] ?? '';
$adminUsernameValue = $old['admin_username'] ?? '';

function setup_field(string $key, array $source, string $default = ''): string
{
    return htmlspecialchars((string) ($source[$key] ?? $default), ENT_QUOTES, 'UTF-8');
}

?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Wizard Instalasi · Kasir Toko</title>
    <link rel="stylesheet" href="<?= setup_base_url() ?>/assets/css/style.css">
    <style>
        body { background: #0f172a; color: #0f172a; }
        .setup-wrapper {
            max-width: 900px;
            margin: 2rem auto;
            background: #ffffff;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 10px 40px rgba(0,0,0,0.08);
        }
        .setup-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1rem; }
        .muted { color: #4b5563; }
        .alert { padding: 0.9rem 1rem; border-radius: 8px; margin-bottom: 1rem; }
        .alert.success { background: #ecfdf3; color: #166534; border: 1px solid #bbf7d0; }
        .alert.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .card { border: 1px solid #e5e7eb; padding: 1rem; border-radius: 10px; margin-bottom: 1rem; }
    </style>
</head>
<body>
<div class="setup-wrapper">
    <h1>Wizard Instalasi</h1>
    <p class="muted">Lengkapi data awal agar aplikasi siap dipakai. Semua isian dapat diubah nanti dari menu pengaturan.</p>

    <?php if ($flash): ?>
        <div class="alert <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['text'], ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="post" action="process.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['setup_csrf'], ENT_QUOTES, 'UTF-8') ?>">

        <section class="card">
            <h2>Profil Toko</h2>
            <div class="setup-grid">
                <div class="form-group">
                    <label for="store_name">Nama Toko</label>
                    <input type="text" id="store_name" name="store_name" required value="<?= htmlspecialchars($storeNameValue, ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-group">
                    <label for="store_phone">Nomor HP / Telepon</label>
                    <input type="text" id="store_phone" name="store_phone" value="<?= htmlspecialchars($storePhoneValue, ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </div>
            <div class="form-group">
                <label for="store_address">Alamat</label>
                <textarea id="store_address" name="store_address" rows="2"><?= htmlspecialchars($storeAddressValue, ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
        </section>

        <section class="card">
            <h2>Konfigurasi Aplikasi</h2>
            <div class="form-group">
                <label for="base_url">Base URL</label>
                <input type="text" id="base_url" name="base_url" required value="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>">
                <p class="muted">Contoh: <code>/kasirtokolc</code> untuk lokal atau <code>https://domainmu.com</code> jika memakai domain.</p>
            </div>
        </section>

        <section class="card">
            <h2>Database MySQL</h2>
            <div class="setup-grid">
                <div class="form-group">
                    <label for="db_host">Host</label>
                    <input type="text" id="db_host" name="db_host" required value="<?= htmlspecialchars($dbHostValue, ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-group">
                    <label for="db_port">Port</label>
                    <input type="number" id="db_port" name="db_port" required value="<?= htmlspecialchars($dbPortValue, ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-group">
                    <label for="db_name">Nama Database</label>
                    <input type="text" id="db_name" name="db_name" required value="<?= htmlspecialchars($dbNameValue, ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-group">
                    <label for="db_username">Username</label>
                    <input type="text" id="db_username" name="db_username" required value="<?= htmlspecialchars($dbUserValue, ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-group">
                    <label for="db_password">Password</label>
                    <input type="password" id="db_password" name="db_password" value="<?= htmlspecialchars($dbPassValue, ENT_QUOTES, 'UTF-8') ?>" autocomplete="new-password">
                </div>
                <div class="form-group">
                    <label for="db_charset">Charset</label>
                    <input type="text" id="db_charset" name="db_charset" required value="<?= htmlspecialchars($dbCharsetValue, ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </div>
        </section>

        <section class="card">
            <h2>Akun Administrator</h2>
            <div class="setup-grid">
                <div class="form-group">
                    <label for="admin_full_name">Nama Lengkap</label>
                    <input type="text" id="admin_full_name" name="admin_full_name" required value="<?= htmlspecialchars($adminFullNameValue, ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-group">
                    <label for="admin_username">Username</label>
                    <input type="text" id="admin_username" name="admin_username" required value="<?= htmlspecialchars($adminUsernameValue, ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-group">
                    <label for="admin_password">Kata Sandi</label>
                    <input type="password" id="admin_password" name="admin_password" required autocomplete="new-password">
                </div>
                <div class="form-group">
                    <label for="admin_password_confirm">Ulangi Kata Sandi</label>
                    <input type="password" id="admin_password_confirm" name="admin_password_confirm" required autocomplete="new-password">
                </div>
            </div>
            <p class="muted">Akun ini akan dibuat sebagai <strong>Admin Super</strong> untuk mengelola pengguna lain.</p>
        </section>

        <button class="button" type="submit" style="width:100%;">Simpan &amp; Mulai</button>
    </form>
</div>
</body>
</html>
