<?php

if (!function_exists('ensure_csrf_token')) {
    require_once __DIR__ . '/../config/auth.php';
    require_once __DIR__ . '/../includes/fungsi.php';
}

require_once __DIR__ . '/../includes/approval_helpers.php';

ensure_csrf_token();

$pdo = get_db_connection();
ensure_expense_request_schema($pdo);

$currentUser = current_user();
$canDecideExpenses = $currentUser && in_array($currentUser['role'], [ROLE_MANAJER, ROLE_ADMIN], true);

$pendingExpenseRequests = fetch_expense_requests($pdo, 'pending', 50);
$recentExpenseRequests = array_filter(
    fetch_expense_requests($pdo, null, 150),
    static function ($row) {
        return $row['status'] !== 'pending';
    }
);

$expenses = $pdo->query("
    SELECT e.*, u.full_name AS created_by_name
    FROM expenses e
    LEFT JOIN users u ON u.id = e.created_by
    ORDER BY e.expense_date DESC
    LIMIT 100
")->fetchAll();

$totalMonth = $pdo->query("
    SELECT COALESCE(SUM(amount),0) FROM expenses
    WHERE DATE_FORMAT(expense_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
")->fetchColumn();

?>

<section class="card">
    <h2>Ajukan Pengeluaran</h2>
    <form method="post" action="<?= BASE_URL ?>/actions/tambah_pengeluaran.php">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <div class="grid-2">
            <div class="form-group">
                <label for="expense_date">Tanggal</label>
                <input type="date" id="expense_date" name="expense_date" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="form-group">
                <label for="category">Kategori</label>
                <input type="text" id="category" name="category" placeholder="Gaji, listrik, dll" required>
            </div>
        </div>
        <div class="form-group">
            <label for="amount">Nominal</label>
            <input type="number" id="amount" name="amount" min="0" step="0.01" required>
        </div>
        <div class="form-group">
            <label for="description">Deskripsi</label>
            <textarea id="description" name="description" rows="3"></textarea>
        </div>
        <button class="button" type="submit">Kirim Pengajuan</button>
        <p class="muted" style="margin-top:0.5rem;">Pengajuan akan direview oleh manajer atau admin sebelum tercatat ke laporan.</p>
    </form>
</section>

<section class="card" style="margin-top:1.5rem;">
    <h2>Pengajuan Pengeluaran Menunggu</h2>
    <?php if (!$pendingExpenseRequests): ?>
        <p class="muted">Tidak ada pengajuan yang menunggu persetujuan.</p>
    <?php else: ?>
        <table class="table">
            <thead>
            <tr>
                <th>Tanggal</th>
                <th>Kategori</th>
                <th>Nominal</th>
                <th>Deskripsi</th>
                <th>Diajukan Oleh</th>
                <th>Diajukan Pada</th>
                <?php if ($canDecideExpenses): ?>
                    <th>Aksi</th>
                <?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($pendingExpenseRequests as $request): ?>
                <tr>
                    <td><?= format_date($request['expense_date']) ?></td>
                    <td><?= sanitize($request['category']) ?></td>
                    <td><?= format_rupiah($request['amount']) ?></td>
                    <td><?= sanitize($request['description']) ?></td>
                    <td><?= sanitize($request['created_by_name'] ?? '-') ?></td>
                    <td><?= format_date($request['created_at'], true) ?></td>
                    <?php if ($canDecideExpenses): ?>
                        <td>
                            <form method="post" action="<?= BASE_URL ?>/actions/decide_pengeluaran.php" style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                                <input type="hidden" name="decision" value="approve">
                                <button class="button small" type="submit">Setujui</button>
                            </form>
                            <form method="post" action="<?= BASE_URL ?>/actions/decide_pengeluaran.php" style="margin-top:0.5rem;">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                                <input type="hidden" name="decision" value="reject">
                                <label class="muted" for="reject-notes-<?= (int) $request['id'] ?>" style="display:block; margin-bottom:0.25rem;">Alasan (opsional)</label>
                                <textarea id="reject-notes-<?= (int) $request['id'] ?>" name="notes" rows="2" placeholder="Catatan penolakan"></textarea>
                                <button class="button secondary small" type="submit" style="margin-top:0.25rem;">Tolak</button>
                            </form>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$canDecideExpenses): ?>
            <p class="muted" style="margin-top:0.75rem;">Hubungi manajer untuk proses persetujuan.</p>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="card" style="margin-top:1.5rem;">
    <h2>Riwayat Pengajuan Pengeluaran</h2>
    <?php if (!$recentExpenseRequests): ?>
        <p class="muted">Belum ada riwayat pengajuan.</p>
    <?php else: ?>
        <table class="table">
            <thead>
            <tr>
                <th>Tanggal</th>
                <th>Kategori</th>
                <th>Nominal</th>
                <th>Status</th>
                <th>Diputuskan Oleh</th>
                <th>Catatan</th>
                <th>Diajukan Pada</th>
                <th>Diputuskan Pada</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($recentExpenseRequests as $history): ?>
                <tr>
                    <td><?= format_date($history['expense_date']) ?></td>
                    <td><?= sanitize($history['category']) ?></td>
                    <td><?= format_rupiah($history['amount']) ?></td>
                    <td><?= ucfirst($history['status']) ?></td>
                    <td><?= sanitize($history['decision_by_name'] ?? '-') ?></td>
                    <td><?= sanitize($history['decision_notes'] ?? '-') ?></td>
                    <td><?= format_date($history['created_at'], true) ?></td>
                    <td><?= $history['decision_at'] ? format_date($history['decision_at'], true) : '-' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section class="card" style="margin-top:1.5rem;">
    <h2>Pengeluaran Disetujui Bulan Ini</h2>
    <p>Total: <strong><?= format_rupiah((float) $totalMonth) ?></strong></p>
    <table class="table">
        <thead>
        <tr>
            <th>Tanggal</th>
            <th>Kategori</th>
            <th>Deskripsi</th>
            <th>Nominal</th>
            <th>Diajukan Oleh</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($expenses as $expense): ?>
            <tr>
                <td><?= format_date($expense['expense_date']) ?></td>
                <td><?= sanitize($expense['category']) ?></td>
                <td><?= sanitize($expense['description']) ?></td>
                <td><?= format_rupiah($expense['amount']) ?></td>
                <td><?= sanitize($expense['created_by_name'] ?? '-') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
