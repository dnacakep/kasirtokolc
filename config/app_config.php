<?php

require_once __DIR__ . '/timezone.php';
require_once __DIR__ . '/setup_state.php';

$SETUP_CONFIG = setup_load_config();

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

define('APP_NAME', $SETUP_CONFIG['store']['name'] ?? 'Kasir Minimarket');

$baseUrl = $SETUP_CONFIG['base_url'] ?? getenv('BASE_URL');
if ($baseUrl !== false && $baseUrl !== null && $baseUrl !== '') {
    $baseUrl = rtrim($baseUrl, '/');
} else {
    $baseUrl = setup_guess_base_url();
}

define('BASE_URL', $baseUrl === '' ? '' : $baseUrl);
define('SESSION_TIMEOUT', 60 * 60 * 4);

define('ROLE_KASIR', 'kasir');
define('ROLE_MANAJER', 'manajer');
define('ROLE_ADMIN', 'adminsuper');

if (!defined('KASIR_INACTIVITY_TIMEOUT')) {
    define('KASIR_INACTIVITY_TIMEOUT', 60 * 60 * 8);
}

$ROLE_HIERARCHY = [
    ROLE_KASIR => 1,
    ROLE_MANAJER => 5,
    ROLE_ADMIN => 10,
];
