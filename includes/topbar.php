<?php

$user = current_user();
$today = date('l, d M Y');

$cashSessionInfo = null;
if ($user && $user['role'] === ROLE_KASIR && !empty($_SESSION['cash_drawer_session_id'])) {
    $pdo = get_db_connection();
    $session = fetch_cash_session_by_id($pdo, (int) $_SESSION['cash_drawer_session_id']);
    if ($session) {
        $summary = summarize_cash_session($pdo, $session);
        $cashSessionInfo = [
            'expected' => $summary['expected_balance'],
            'opening' => (float) $session['opening_amount'],
            'opened_at' => $session['opened_at'],
        ];
    }
}

?>
<header class="topbar">
    <div class="topbar-left">
        <button class="sidebar-toggle" type="button" data-toggle-sidebar aria-expanded="false" aria-controls="sidebar-nav">
            ☰
        </button>
        <div>
            <strong><?= ucfirst(str_replace('_', ' ', $currentPage)) ?></strong>
            <span class="muted"> · <?= $today ?></span>
        </div>
    </div>
    <div class="topbar-right">
        <?php if ($cashSessionInfo): ?>
            <div class="topbar-cash">
                <span class="muted">Saldo kas:</span>
                <strong><?= format_rupiah($cashSessionInfo['expected']) ?></strong>
                <span class="muted">(modal awal <?= format_rupiah($cashSessionInfo['opening']) ?>)</span>
            </div>
        <?php endif; ?>
        <button class="dark-mode-toggle" type="button" id="darkModeToggle" title="Toggle Dark Mode">
            🌙
        </button>
        <span class="muted">Masuk sebagai <?= sanitize($user['username']) ?></span>
    </div>
</header>
