<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';

require_login();
guard_post();
verify_csrf_token($_POST['csrf_token'] ?? '');

$type = $_POST['type'] ?? '';

$pdo = get_db_connection();

switch ($type) {
    case 'label':
        $batchId = (int) ($_POST['batch_id'] ?? 0);
        if ($batchId) {
            $pdo->prepare("UPDATE product_batches SET label_printed = 1, label_printed_at = NOW() WHERE id = :id")
                ->execute([':id' => $batchId]);
        }
        break;
    default:
        // Placeholder for future notification acknowledgements.
        break;
}

redirect_with_message('/index.php?page=notifikasi', 'Notifikasi diperbarui.');

