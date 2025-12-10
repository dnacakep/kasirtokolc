<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';
require_once __DIR__ . '/../includes/approval_helpers.php';

require_role(ROLE_MANAJER);
guard_post();
verify_csrf_token($_POST['csrf_token'] ?? '');

$requestId = (int) ($_POST['request_id'] ?? 0);
$decision = $_POST['decision'] ?? '';
$notes = trim($_POST['notes'] ?? '');

if ($requestId <= 0 || !in_array($decision, ['approve', 'reject'], true)) {
    redirect_with_message('/index.php?page=pengeluaran', 'Permintaan tidak valid.', 'error');
}

$pdo = get_db_connection();
ensure_expense_request_schema($pdo);

$pdo->beginTransaction();

$stmt = $pdo->prepare("SELECT * FROM expense_requests WHERE id = :id FOR UPDATE");
$stmt->execute([':id' => $requestId]);
$request = $stmt->fetch();

if (!$request) {
    $pdo->rollBack();
    redirect_with_message('/index.php?page=pengeluaran', 'Pengajuan tidak ditemukan.', 'error');
}

if ($request['status'] !== 'pending') {
    $pdo->rollBack();
    redirect_with_message('/index.php?page=pengeluaran', 'Pengajuan sudah diproses sebelumnya.', 'error');
}

$user = current_user();
$now = date('Y-m-d H:i:s');

if ($decision === 'approve') {
    $insertExpense = $pdo->prepare("
        INSERT INTO expenses (expense_date, category, description, amount, created_by, created_at, updated_at)
        VALUES (:expense_date, :category, :description, :amount, :created_by, :created_at, :updated_at)
    ");
    $insertExpense->execute([
        ':expense_date' => $request['expense_date'],
        ':category' => $request['category'],
        ':description' => $request['description'],
        ':amount' => $request['amount'],
        ':created_by' => $request['created_by'],
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    $status = 'approved';
    $message = 'Pengeluaran disetujui dan dicatat.';
} else {
    $status = 'rejected';
    $message = 'Pengajuan pengeluaran ditolak.';
}

$update = $pdo->prepare("
    UPDATE expense_requests
    SET status = :status,
        decision_by = :decision_by,
        decision_at = :decision_at,
        decision_notes = :decision_notes
    WHERE id = :id
");
$update->execute([
    ':status' => $status,
    ':decision_by' => $user['id'],
    ':decision_at' => $now,
    ':decision_notes' => $notes !== '' ? $notes : null,
    ':id' => $requestId,
]);

$pdo->commit();

redirect_with_message('/index.php?page=pengeluaran', $message);

