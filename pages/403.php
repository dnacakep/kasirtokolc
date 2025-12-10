<?php
require_once __DIR__ . '/../config/app_config.php';
http_response_code(403);
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Akses ditolak</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<div class="login-wrapper">
    <h1>Akses ditolak</h1>
    <p class="muted">Anda tidak memiliki hak akses ke halaman ini.</p>
    <a class="button" href="<?= BASE_URL ?>/index.php?page=dashboard">Kembali</a>
</div>
</body>
</html>
