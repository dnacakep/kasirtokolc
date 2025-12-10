<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';

require_role(ROLE_KASIR);
guard_post();
verify_csrf_token($_POST['csrf_token'] ?? '');

$id = (int) ($_POST['id'] ?? 0);
if (!$id) {
    redirect_with_message('/index.php?page=barang', 'Barang tidak ditemukan.', 'error');
}

$pdo = get_db_connection();

// Logika toggle: UPDATE products SET is_active = NOT is_active
$stmt = $pdo->prepare("UPDATE products SET is_active = !is_active WHERE id = :id");
$stmt->execute([':id' => $id]);

redirect_with_message('/index.php?page=barang', 'Status barang berhasil diubah.');
