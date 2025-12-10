<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';

require_role(ROLE_KASIR);
guard_post();
verify_csrf_token($_POST['csrf_token'] ?? '');

$pdo = get_db_connection();

$id = isset($_POST['id']) ? (int) $_POST['id'] : null;
$data = [
    ':member_code' => trim($_POST['member_code'] ?? ''),
    ':name' => trim($_POST['name'] ?? ''),
    ':phone' => trim($_POST['phone'] ?? ''),
    ':email' => trim($_POST['email'] ?? ''),
    ':address' => trim($_POST['address'] ?? ''),
    ':status' => $_POST['status'] ?? 'active',
];

if ($id) {
    // Editing an existing member
    if (empty($data[':name'])) {
        redirect_with_message('/index.php?page=member&edit=' . $id, 'Nama wajib diisi.', 'error');
    }

    $data[':id'] = $id;
    $pdo->prepare("
        UPDATE members
        SET member_code = :member_code, -- Kode tidak diubah, tapi tetap di-bind
            name = :name,
            phone = :phone,
            email = :email,
            address = :address,
            status = :status,
            updated_at = NOW()
        WHERE id = :id
    ")->execute($data);

    redirect_with_message('/index.php?page=member&edit=' . $id, 'Data member diperbarui.');

} else {
    // Creating a new member
    if (empty($data[':name'])) {
        redirect_with_message('/index.php?page=member', 'Nama wajib diisi.', 'error');
    }

    // Auto-generate member code
    $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(member_code, 2) AS UNSIGNED)) as max_code FROM members WHERE member_code RLIKE '^M[0-9]+$'");
    $maxCode = $stmt->fetchColumn();
    $nextNumber = ($maxCode ? (int)$maxCode : 0) + 1;
    $data[':member_code'] = sprintf('M%04d', $nextNumber);

    $pdo->prepare("
        INSERT INTO members (member_code, name, phone, email, address, status, points_balance, created_at, updated_at)
        VALUES (:member_code, :name, :phone, :email, :address, :status, 0, NOW(), NOW())
    ")->execute($data);

    redirect_with_message('/index.php?page=member', 'Member baru ditambahkan dengan kode ' . $data[':member_code'] . '.');
}

