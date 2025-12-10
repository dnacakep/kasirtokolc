<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';

require_role(ROLE_KASIR);
guard_post();
verify_csrf_token($_POST['csrf_token'] ?? '');

$id = (int) ($_POST['id'] ?? 0);
if (!$id) {
    redirect_with_message('/index.php?page=pemasok', 'Pemasok tidak ditemukan.', 'error');
}

$pdo = get_db_connection();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM product_batches WHERE supplier_id = :id");
$stmt->execute([':id' => $id]);
if ($stmt->fetchColumn() > 0) {
    redirect_with_message('/index.php?page=pemasok', 'Pemasok masih terhubung dengan riwayat stok.', 'error');
}

$pdo->prepare("DELETE FROM suppliers WHERE id = :id")->execute([':id' => $id]);

redirect_with_message('/index.php?page=pemasok', 'Pemasok dihapus.');
