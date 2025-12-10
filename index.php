<?php

require_once __DIR__ . '/config/setup_state.php';
if (setup_requires_wizard()) {
    header('Location: ' . setup_build_url());
    exit;
}

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/fungsi.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/pages/login.php');
    exit;
}

$page = $_GET['page'] ?? 'dashboard';
$pageFile = __DIR__ . '/pages/' . $page . '.php';

if (!preg_match('/^[a-z0-9_]+$/', $page) || !file_exists($pageFile)) {
    http_response_code(404);
    $page = '404';
    $pageFile = __DIR__ . '/pages/404.php';
}

include __DIR__ . '/includes/header.php';
include $pageFile;
include __DIR__ . '/includes/footer.php';
