<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';

require_role(ROLE_KASIR);
guard_post();
verify_csrf_token($_POST['csrf_token'] ?? '');

$pdo = get_db_connection();
$id = isset($_POST['id']) ? (int) $_POST['id'] : null;
$data = [
    ':name' => trim($_POST['name'] ?? ''),
    ':contact_person' => trim($_POST['contact_person'] ?? ''),
    ':phone' => trim($_POST['phone'] ?? ''),
    ':email' => trim($_POST['email'] ?? ''),
    ':address' => trim($_POST['address'] ?? ''),
];

if ($data[':name'] === '') {
    redirect_with_message('/index.php?page=pemasok', 'Nama pemasok wajib diisi.', 'error');
}

if ($id) {
    $data[':id'] = $id;
    $pdo->prepare("
        UPDATE suppliers
        SET name = :name,
            contact_person = :contact_person,
            phone = :phone,
            email = :email,
            address = :address,
            updated_at = NOW()
        WHERE id = :id
    ")->execute($data);

    redirect_with_message('/index.php?page=pemasok', 'Pemasok diperbarui.');
}

$pdo->prepare("
    INSERT INTO suppliers (name, contact_person, phone, email, address, created_at, updated_at)
    VALUES (:name, :contact_person, :phone, :email, :address, NOW(), NOW())
")->execute($data);

redirect_with_message('/index.php?page=pemasok', 'Pemasok baru ditambahkan.');

