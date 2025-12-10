<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';

require_login();

if (!current_user() || !in_array(current_user()['role'], [ROLE_ADMIN, ROLE_MANAJER, ROLE_KASIR], true)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$pdo = get_db_connection();

try {
    $pdo->exec("ALTER TABLE sales MODIFY COLUMN payment_method VARCHAR(16) NOT NULL DEFAULT 'cash'");
    try {
        $pdo->exec("ALTER TABLE sales MODIFY COLUMN payment_method ENUM('cash','debit','qris','hutang') NOT NULL DEFAULT 'cash'");
        $status = 'ENUM updated to include hutang.';
    } catch (Throwable $inner) {
        $status = 'Column left as VARCHAR(16); ENUM alter failed: ' . $inner->getMessage();
    }

    echo 'OK: ' . $status;
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Error updating payment_method: ' . $e->getMessage();
}
