<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';

require_role(ROLE_KASIR);
guard_post();
verify_csrf_token($_POST['csrf_token'] ?? '');

$batchIds = $_POST['batch_ids'] ?? [];
if (!is_array($batchIds) || count($batchIds) === 0) {
    redirect_with_message('/index.php?page=label_harga', 'Pilih minimal satu batch.', 'error');
}

$batchIds = array_map('intval', $batchIds);
$batchIds = array_filter($batchIds, fn($id) => $id > 0);

if (!$batchIds) {
    redirect_with_message('/index.php?page=label_harga', 'Batch tidak valid.', 'error');
}

$placeholders = implode(',', array_fill(0, count($batchIds), '?'));
$pdo = get_db_connection();
$stmt = $pdo->prepare("UPDATE product_batches SET label_printed = 1, label_printed_at = NOW() WHERE id IN ($placeholders)");
$stmt->execute($batchIds);

redirect_with_message('/index.php?page=label_harga', 'Label terpilih ditandai sudah dicetak.');
