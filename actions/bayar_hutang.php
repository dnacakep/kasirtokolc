<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';
require_once __DIR__ . '/../includes/member_debt.php';

require_role(ROLE_KASIR);
guard_post();
verify_csrf_token($_POST['csrf_token'] ?? '');

$pdo = get_db_connection();

$debtId = (int) ($_POST['debt_id'] ?? 0);
$amount = isset($_POST['amount']) ? (float) $_POST['amount'] : 0.0;
$note = trim((string) ($_POST['note'] ?? ''));

if ($debtId <= 0) {
    redirect_with_message('/index.php?page=hutang_member', 'Data hutang tidak valid.', 'error');
}

try {
    $result = record_member_debt_payment($pdo, $debtId, $amount, current_user()['id'] ?? null, $note);
    $outstanding = (float) $result['outstanding'];
    $status = (string) $result['status'];

    $message = 'Pembayaran hutang berhasil dicatat.';
    if ($outstanding > 0) {
        $message .= ' Sisa: ' . format_rupiah($outstanding) . ' (' . strtoupper($status) . ').';
    } else {
        $message .= ' Hutang telah lunas.';
    }

    redirect_with_message('/index.php?page=hutang_member', $message, 'success');
} catch (Throwable $e) {
    redirect_with_message('/index.php?page=hutang_member', $e->getMessage(), 'error');
}
