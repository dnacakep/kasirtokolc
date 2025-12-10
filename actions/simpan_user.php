<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';

require_role(ROLE_MANAJER);
guard_post();
verify_csrf_token($_POST['csrf_token'] ?? '');

$pdo = get_db_connection();
$actor = current_user();

$id = isset($_POST['id']) ? (int) $_POST['id'] : null;
$username = trim($_POST['username'] ?? '');
$fullName = trim($_POST['full_name'] ?? '');
$role = $_POST['role'] ?? ROLE_KASIR;
$isActive = isset($_POST['is_active']) && $_POST['is_active'] == '1' ? 1 : 0;
$password = $_POST['password'] ?? '';

if ($username === '' || $fullName === '') {
    redirect_with_message('/index.php?page=user' . ($id ? '&edit=' . $id : ''), 'Username dan nama wajib diisi.', 'error');
}

$validRoles = [ROLE_KASIR, ROLE_MANAJER, ROLE_ADMIN];
if (!in_array($role, $validRoles, true)) {
    $role = ROLE_KASIR;
}

if ($id) {
    $data = [
        ':username' => $username,
        ':full_name' => $fullName,
        ':role' => $role,
        ':is_active' => $isActive,
        ':id' => $id,
    ];
    $sql = "UPDATE users SET username = :username, full_name = :full_name, role = :role, is_active = :is_active";
    if ($password !== '') {
        $sql .= ", password_hash = :password_hash";
        $data[':password_hash'] = password_hash($password, PASSWORD_BCRYPT);
    }
    $sql .= ", updated_at = NOW() WHERE id = :id";
    $pdo->prepare($sql)->execute($data);
    redirect_with_message('/index.php?page=user&edit=' . $id, 'Pengguna diperbarui.');
}

$stmt = $pdo->prepare("
    INSERT INTO users (username, full_name, role, password_hash, is_active, created_at, updated_at, created_by)
    VALUES (:username, :full_name, :role, :password_hash, :is_active, NOW(), NOW(), :created_by)
");
$stmt->execute([
    ':username' => $username,
    ':full_name' => $fullName,
    ':role' => $role,
    ':password_hash' => password_hash($password, PASSWORD_BCRYPT),
    ':is_active' => $isActive,
    ':created_by' => $actor['id'],
]);

redirect_with_message('/index.php?page=user', 'Pengguna baru dibuat.');

