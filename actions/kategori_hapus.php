<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';

require_role(ROLE_KASIR);
guard_post();
verify_csrf_token($_POST['csrf_token'] ?? '');

$id = (int) ($_POST['id'] ?? 0);
if (!$id) {
    redirect_with_message('/index.php?page=kategori', 'Kategori tidak ditemukan.', 'error');
}

$pdo = get_db_connection();

$stmt = $pdo->prepare("SELECT COUNT(*) AS jumlah FROM products WHERE category_id = :id");
$stmt->execute([':id' => $id]);
$count = (int) $stmt->fetchColumn();

if ($count > 0) {
    redirect_with_message('/index.php?page=kategori', 'Kategori digunakan oleh barang, tidak dapat dihapus.', 'error');
}

$pdo->prepare("DELETE FROM categories WHERE id = :id")->execute([':id' => $id]);

redirect_with_message('/index.php?page=kategori', 'Kategori dihapus.');

