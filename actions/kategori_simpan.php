<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';

require_role(ROLE_KASIR);
guard_post();
verify_csrf_token($_POST['csrf_token'] ?? '');

$pdo = get_db_connection();
$id = isset($_POST['id']) ? (int) $_POST['id'] : null;
$name = trim($_POST['name'] ?? '');
$description = trim($_POST['description'] ?? '');

if ($name === '') {
    redirect_with_message('/index.php?page=kategori', 'Nama kategori wajib diisi.', 'error');
}

if ($id) {
    $stmt = $pdo->prepare("UPDATE categories SET name = :name, description = :description, updated_at = NOW() WHERE id = :id");
    $stmt->execute([
        ':name' => $name,
        ':description' => $description,
        ':id' => $id,
    ]);
    redirect_with_message('/index.php?page=kategori', 'Kategori berhasil diperbarui.');
}

$stmt = $pdo->prepare("INSERT INTO categories (name, description, created_at, updated_at) VALUES (:name, :description, NOW(), NOW())");
$stmt->execute([
    ':name' => $name,
    ':description' => $description,
]);

redirect_with_message('/index.php?page=kategori', 'Kategori baru ditambahkan.');

