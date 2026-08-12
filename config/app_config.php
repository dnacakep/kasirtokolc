<?php

require_once __DIR__ . '/timezone.php';

if (!defined('APP_ENV')) {
    $appEnv = getenv('APP_ENV') ?: 'production';
    define('APP_ENV', $appEnv);
}

if (!defined('APP_DEBUG')) {
    $debugEnv = getenv('APP_DEBUG');
    if ($debugEnv === false) {
        define('APP_DEBUG', APP_ENV !== 'production');
    } else {
        $debugValue = filter_var($debugEnv, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        define('APP_DEBUG', $debugValue ?? false);
    }
}

require_once __DIR__ . '/error_handler.php';
app_initialize_error_handling();

define('APP_NAME', 'Kasir Minimarket');

$baseUrl = getenv('BASE_URL');
if ($baseUrl !== false && $baseUrl !== null) {
    $baseUrl = rtrim($baseUrl, '/');
} else {
    $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $appRoot = realpath(__DIR__ . '/..') ?: '';

    $documentRoot = rtrim(str_replace('\\', '/', $documentRoot), '/');
    $appRoot = rtrim(str_replace('\\', '/', $appRoot), '/');

    if ($documentRoot && str_starts_with($appRoot, $documentRoot)) {
        $baseUrl = substr($appRoot, strlen($documentRoot));
        $baseUrl = $baseUrl === '' ? '' : '/' . ltrim($baseUrl, '/');
    } else {
        $baseUrl = '/kasirtokolc';
    }
}

define('BASE_URL', $baseUrl);
define('SESSION_TIMEOUT', 60 * 60 * 24 * 30); // 30 hari

define('ROLE_KASIR', 'kasir');
define('ROLE_MANAJER', 'manajer');
define('ROLE_ADMIN', 'adminsuper');

if (!defined('KASIR_INACTIVITY_TIMEOUT')) {
    define('KASIR_INACTIVITY_TIMEOUT', 60 * 60 * 24 * 30); // 30 hari
}

$ROLE_HIERARCHY = [
    ROLE_KASIR => 1,
    ROLE_MANAJER => 5,
    ROLE_ADMIN => 10,
];
