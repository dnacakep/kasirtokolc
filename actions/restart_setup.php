<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';
require_once __DIR__ . '/../config/setup_state.php';

guard_post();
require_role(ROLE_ADMIN);

$csrfSession = $_SESSION['csrf_token'] ?? '';
$csrfInput = $_POST['csrf_token'] ?? '';
if (!hash_equals($csrfSession, $csrfInput)) {
    http_response_code(400);
    exit('CSRF token tidak valid');
}

$config = setup_load_config();
$config['installed'] = false;
setup_save_config($config);

setup_flash('success', 'Wizard diaktifkan ulang. Silakan lanjutkan konfigurasi.');

logout_user();
header('Location: ' . setup_build_url());
exit;
