<?php

require_once __DIR__ . '/../config/setup_state.php';
if (setup_requires_wizard() && !setup_is_setup_request()) {
    header('Location: ' . setup_build_url());
    exit;
}

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';

if (is_logged_in()) {
    header('Location: ' . BASE_URL . '/index.php?page=dashboard');
    exit;
}

$flash = consume_flash_message();

?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masuk · <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<div class="login-wrapper">
    <h1><?= APP_NAME ?></h1>
    <p class="muted">Masuk menggunakan akun kasir, manajer, atau admin.</p>

    <?php if (isset($_GET['expired'])): ?>
        <div class="alert error">Sesi Anda berakhir, silakan masuk kembali.</div>
    <?php endif; ?>

    <?php if ($flash): ?>
        <div class="alert <?= sanitize($flash['type']) ?>"><?= sanitize($flash['text']) ?></div>
    <?php endif; ?>

    <?php ensure_csrf_token(); ?>
    <form method="post" action="<?= BASE_URL ?>/actions/login.php">
        <div class="form-group">
            <label for="username">Nama Pengguna</label>
            <input type="text" id="username" name="username" autofocus required>
        </div>
        <div class="form-group">
            <label for="password">Kata Sandi</label>
            <input type="password" id="password" name="password" required>
        </div>
        <div class="form-group-inline">
            <input type="checkbox" id="remember_me" name="remember_me" value="1">
            <label for="remember_me">Ingat Saya</label>
        </div>
        <input type="hidden" name="csrf_token" value="<?= sanitize($_SESSION['csrf_token']) ?>">
        <button class="button" type="submit">Masuk</button>
    </form>
</div>
</body>
</html>

