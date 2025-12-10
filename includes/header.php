<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/fungsi.php';
require_once __DIR__ . '/cash_drawer.php';

require_login();

$currentPage = $_GET['page'] ?? 'dashboard';
$user = current_user();

if ($user && $user['role'] === ROLE_KASIR) {
    if (empty($_SESSION['cash_drawer_session_id'])) {
        $pdo = get_db_connection();
        $openSession = fetch_open_cash_session($pdo, (int) $user['id']);
        if ($openSession) {
            $_SESSION['cash_drawer_session_id'] = (int) $openSession['id'];
        } elseif ($currentPage !== 'cash_drawer_open') {
            $_SESSION['cash_drawer_pending_open'] = 1;
            header('Location: ' . BASE_URL . '/index.php?page=cash_drawer_open');
            exit;
        }
    }
}

$flashMessage = consume_flash_message();
$sanitizedPage = preg_replace('/[^a-z0-9_-]+/i', '', (string) $currentPage);
$bodyClasses = ['page-' . ($sanitizedPage ?: 'dashboard')];
if ($currentPage === 'transaksi') {
    $bodyClasses[] = 'pos-tablet-layout';
}
$bodyClassAttr = trim(implode(' ', array_unique(array_filter($bodyClasses))));

$assetVersions = $assetVersions ?? [];
$versionedAssets = [
    'assets/css/style.css',
    'assets/js/app.js',
    'assets/js/scanner.js',
];
foreach ($versionedAssets as $assetPath) {
    if (!isset($assetVersions[$assetPath])) {
        $absolutePath = __DIR__ . '/../' . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $assetPath);
        $assetVersions[$assetPath] = file_exists($absolutePath) ? (string) filemtime($absolutePath) : (string) time();
    }
}

?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= APP_NAME ?> - <?= ucfirst(str_replace('_', ' ', $currentPage)) ?></title>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    
    <!-- PWA Meta Tags -->
    <meta name="theme-color" content="#1e40af">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?= APP_NAME ?>">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="application-name" content="<?= APP_NAME ?>">
    <meta name="msapplication-TileColor" content="#1e40af">
    <meta name="msapplication-config" content="/browserconfig.xml">
    
    <!-- PWA Manifest -->
    <link rel="manifest" href="<?= BASE_URL ?>/manifest.json">
    
    <!-- Apple Touch Icons -->
    <link rel="apple-touch-icon" sizes="192x192" href="<?= BASE_URL ?>/assets/images/icon-192.png">
    <link rel="apple-touch-icon" sizes="512x512" href="<?= BASE_URL ?>/assets/images/icon-512.png">
    
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= $assetVersions['assets/css/style.css'] ?>">
</head>
<body class="<?= htmlspecialchars($bodyClassAttr, ENT_QUOTES, 'UTF-8') ?>">
<div class="layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="sidebar-backdrop"></div>
    <div class="content-area">
        <?php include __DIR__ . '/topbar.php'; ?>
        <main class="content">
            <?php if ($flashMessage): ?>
                <div class="alert <?= sanitize($flashMessage['type']) ?>" data-autodismiss="4500">
                    <?= sanitize($flashMessage['text']) ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($_SESSION['import_barang_warnings'])): ?>
                <div class="alert warning">
                    <strong>Catatan import:</strong>
                    <ul>
                        <?php foreach ($_SESSION['import_barang_warnings'] as $warning): ?>
                            <li><?= sanitize($warning) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php unset($_SESSION['import_barang_warnings']); ?>
            <?php endif; ?>
