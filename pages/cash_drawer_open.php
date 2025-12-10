<?php

if (!function_exists('ensure_csrf_token')) {
    require_once __DIR__ . '/../config/auth.php';
    require_once __DIR__ . '/../includes/fungsi.php';
}

require_once __DIR__ . '/../includes/cash_drawer.php';

require_role(ROLE_KASIR);
ensure_csrf_token();

$pdo = get_db_connection();
$user = current_user();

ensure_cash_drawer_schema($pdo);

$existingSession = fetch_open_cash_session($pdo, (int) $user['id']);
if ($existingSession) {
    $_SESSION['cash_drawer_session_id'] = (int) $existingSession['id'];
    unset($_SESSION['cash_drawer_pending_open']);
    redirect_with_message('/index.php?page=dashboard', 'Shift kasir Anda sudah aktif.', 'info');
}

$latestSession = fetch_latest_cash_session($pdo);
$previousShift = null;

if ($latestSession) {
    $stmtUser = $pdo->prepare('SELECT full_name, username FROM users WHERE id = :id LIMIT 1');
    $stmtUser->execute([':id' => $latestSession['user_id']]);
    $previousUser = $stmtUser->fetch(PDO::FETCH_ASSOC);

    if ($previousUser) {
        $isClosed = $latestSession['closed_at'] !== null;
        $referenceTime = $isClosed ? $latestSession['closed_at'] : date('Y-m-d H:i:s');
        $summary = summarize_cash_session($pdo, $latestSession, $referenceTime);
        $expected = $isClosed && $latestSession['expected_closing_amount'] !== null
            ? (float) $latestSession['expected_closing_amount']
            : $summary['expected_balance'];

        $counted = $isClosed && $latestSession['closing_amount'] !== null
            ? (float) $latestSession['closing_amount']
            : null;

        $difference = $isClosed && $latestSession['closing_difference'] !== null
            ? (float) $latestSession['closing_difference']
            : ($isClosed && $counted !== null ? $counted - $expected : null);

        $previousShift = [
            'cashier_name' => $previousUser['full_name'] ?: $previousUser['username'],
            'opened_at' => $latestSession['opened_at'],
            'closed_at' => $latestSession['closed_at'],
            'expected' => $expected,
            'counted' => $counted,
            'difference' => $difference,
            'status' => $isClosed ? 'closed' : 'open',
            'cash_sales' => $summary['cash_sales'],
            'cash_expenses' => $summary['cash_expenses'],
            'closing_notes' => $latestSession['closing_notes'] ?? null,
        ];
    }
}

unset($_SESSION['cash_drawer_pending_open']);

?>

<section class="card">
    <h2>Saldo Laci Kas Terakhir</h2>
    <?php if ($previousShift): ?>
        <p class="muted">
            Shift sebelumnya oleh <strong><?= sanitize($previousShift['cashier_name']) ?></strong>
            (mulai <?= format_date($previousShift['opened_at'], true) ?>).
            <?php if ($previousShift['status'] === 'open'): ?>
                Belum ditutup. Harap cocokkan saldo berikut.
            <?php else: ?>
                Ditutup pada <?= format_date($previousShift['closed_at'], true) ?>.
            <?php endif; ?>
        </p>
        <div class="grid-3">
            <div>
                <p class="muted">Saldo yang seharusnya</p>
                <p class="metric"><?= format_rupiah($previousShift['expected']) ?></p>
            </div>
            <div>
                <p class="muted">Penjualan tunai</p>
                <p class="metric"><?= format_rupiah($previousShift['cash_sales']) ?></p>
            </div>
            <div>
                <p class="muted">Pengeluaran kas</p>
                <p class="metric"><?= format_rupiah($previousShift['cash_expenses']) ?></p>
            </div>
        </div>
        <?php if ($previousShift['status'] === 'closed'): ?>
            <div class="grid-3" style="margin-top:1rem;">
                <div>
                    <p class="muted">Saldo dihitung</p>
                    <p class="metric"><?= $previousShift['counted'] !== null ? format_rupiah($previousShift['counted']) : '-' ?></p>
                </div>
                <div>
                    <p class="muted">Selisih</p>
                    <p class="metric <?= ($previousShift['difference'] ?? 0) === 0.0 ? 'muted' : ($previousShift['difference'] > 0 ? 'text-success' : 'text-danger') ?>">
                        <?= $previousShift['difference'] !== null ? format_rupiah($previousShift['difference']) : '-' ?>
                    </p>
                </div>
                <div>
                    <p class="muted">Catatan</p>
                    <p><?= $previousShift['closing_notes'] ? sanitize($previousShift['closing_notes']) : '-' ?></p>
                </div>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <p class="muted">Belum ada shift kasir sebelumnya yang tercatat.</p>
    <?php endif; ?>
</section>

<section class="card" style="margin-top:1.5rem;">
    <h2>Mulai Shift Kasir</h2>
    <form method="post" action="<?= BASE_URL ?>/actions/cash_drawer_open.php" class="form-wide">
        <input type="hidden" name="csrf_token" value="<?= sanitize($_SESSION['csrf_token']) ?>">
        <div class="form-group">
            <label for="opening_amount">Saldo awal di laci kas</label>
            <input type="number" id="opening_amount" name="opening_amount" min="0" step="0.01" required autofocus>
            <p class="muted" style="margin-top:0.25rem;">Masukkan nominal sesuai hasil hitung fisik saat ini.</p>
        </div>
        <div class="form-group">
            <label for="opening_notes">Catatan (opsional)</label>
            <textarea id="opening_notes" name="opening_notes" rows="3" placeholder="Misal: uang pas, ada pecahan kecil, dsb."></textarea>
        </div>
        <?php if ($previousShift && $previousShift['status'] === 'open'): ?>
            <div class="form-group">
                <label for="closing_notes">Catatan selisih shift sebelumnya (opsional)</label>
                <textarea id="closing_notes" name="closing_notes" rows="3" placeholder="Catatan jika saldo tidak sesuai."></textarea>
            </div>
        <?php endif; ?>
        <button class="button" type="submit">Mulai Shift</button>
    </form>
</section>
