<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';
require_once __DIR__ . '/../includes/approval_helpers.php';

require_role(ROLE_KASIR);
guard_post();
verify_csrf_token($_POST['csrf_token'] ?? '');

$date = $_POST['expense_date'] ?? '';
$category = trim($_POST['category'] ?? '');
$amount = (float) ($_POST['amount'] ?? 0);
$description = trim($_POST['description'] ?? '');

if ($date === '' || $category === '' || $amount <= 0) {
    redirect_with_message('/index.php?page=pengeluaran', 'Pastikan tanggal, kategori, dan nominal terisi benar.', 'error');
}

$pdo = get_db_connection();
$user = current_user();

ensure_expense_request_schema($pdo);

$stmt = $pdo->prepare("
    INSERT INTO expense_requests (expense_date, category, description, amount, created_by)
    VALUES (:expense_date, :category, :description, :amount, :created_by)
");
$stmt->execute([
    ':expense_date' => $date,
    ':category' => $category,
    ':description' => $description,
    ':amount' => $amount,
    ':created_by' => $user['id'],
]);

redirect_with_message('/index.php?page=pengeluaran', 'Pengajuan pengeluaran dikirim dan menunggu persetujuan.');
