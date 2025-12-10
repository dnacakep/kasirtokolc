<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';
require_once __DIR__ . '/../includes/cash_drawer.php';

require_role(ROLE_KASIR);
guard_post();
verify_csrf_token($_POST['csrf_token'] ?? '');

$rawOpening = trim($_POST['opening_amount'] ?? '');
$openingNotes = trim($_POST['opening_notes'] ?? '');
$closingNotes = trim($_POST['closing_notes'] ?? '');

if ($rawOpening === '') {
    redirect_with_message('/index.php?page=cash_drawer_open', 'Harap isi nominal uang yang ada di laci.', 'error');
}

$normalized = preg_replace('/[^\d.,-]/', '', $rawOpening);
$commaCount = substr_count($normalized, ',');
$dotCount = substr_count($normalized, '.');

if ($commaCount > 0 && $dotCount > 0) {
    $normalized = str_replace('.', '', $normalized);
    $normalized = str_replace(',', '.', $normalized);
} elseif ($commaCount > 0) {
    $normalized = str_replace(',', '.', $normalized);
} elseif ($dotCount > 1) {
    $lastDot = strrpos($normalized, '.');
    $normalized = str_replace('.', '', substr($normalized, 0, $lastDot)) . '.' . substr($normalized, $lastDot + 1);
}

if (!is_numeric($normalized)) {
    redirect_with_message('/index.php?page=cash_drawer_open', 'Nominal kas tidak valid.', 'error');
}

$openingAmount = (float) $normalized;

if ($openingAmount < 0) {
    redirect_with_message('/index.php?page=cash_drawer_open', 'Nominal kas tidak valid.', 'error');
}

$pdo = get_db_connection();
$user = current_user();

ensure_cash_drawer_schema($pdo);

$pdo->beginTransaction();

$existingSession = fetch_open_cash_session($pdo, (int) $user['id']);
if ($existingSession) {
    $pdo->rollBack();
    $_SESSION['cash_drawer_session_id'] = (int) $existingSession['id'];
    unset($_SESSION['cash_drawer_pending_open']);
    redirect_with_message('/index.php?page=dashboard', 'Shift kasir Anda masih aktif.', 'info');
}

$now = date('Y-m-d H:i:s');
$latestSession = fetch_latest_cash_session($pdo);

if ($latestSession && (int) $latestSession['user_id'] !== (int) $user['id'] && $latestSession['closed_at'] === null) {
    $summary = summarize_cash_session($pdo, $latestSession, $now);
    close_cash_session(
        $pdo,
        (int) $latestSession['id'],
        $openingAmount,
        $summary['expected_balance'],
        (int) $user['id'],
        $closingNotes !== '' ? $closingNotes : null,
        $now
    );
}

$sessionId = create_cash_session($pdo, (int) $user['id'], $openingAmount, $openingNotes !== '' ? $openingNotes : null, $now);

$pdo->commit();

$_SESSION['cash_drawer_session_id'] = $sessionId;
unset($_SESSION['cash_drawer_pending_open']);

redirect_with_message('/index.php?page=dashboard', 'Shift kasir dimulai. Selamat bekerja!');
