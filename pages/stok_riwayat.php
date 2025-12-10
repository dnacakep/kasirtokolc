<?php

if (!function_exists('ensure_csrf_token')) {
    require_once __DIR__ . '/../config/auth.php';
    require_once __DIR__ . '/../includes/fungsi.php';
}

require_once __DIR__ . '/../includes/approval_helpers.php';

ensure_csrf_token();

$pdo = get_db_connection();
ensure_stock_request_schema($pdo);

$currentUser = current_user();
$canDecideStockRequests = $currentUser && in_array($currentUser['role'], [ROLE_MANAJER, ROLE_ADMIN], true);

$pendingStockRequests = fetch_stock_adjustment_requests($pdo, 'pending', 50);
$historyStockRequests = array_filter(
    fetch_stock_adjustment_requests($pdo, null, 150),
    static function ($row) {
        return $row['status'] !== 'pending';
    }
);

$adjustmentsQuery = "
    SELECT sa.*, p.name AS product_name, u.username AS adjusted_by_username
    FROM stock_adjustments sa
    INNER JOIN products p ON p.id = sa.product_id
    LEFT JOIN users u ON u.id = sa.created_by
    ORDER BY sa.created_at DESC
    LIMIT 50
";
$stmtAdjustments = $pdo->prepare($adjustmentsQuery);
$stmtAdjustments->execute();
$adjustments = $stmtAdjustments->fetchAll();

?>

<section class="card">
    <h2>Pengajuan Penyesuaian Stok Menunggu</h2>
    <?php if (!$pendingStockRequests): ?>
        <p class="muted">Tidak ada pengajuan penyesuaian yang menunggu persetujuan.</p>
    <?php else: ?>
        <table class="table">
            <thead>
            <tr>
                <th>Produk</th>
                <th>Jumlah</th>
                <th>Alasan</th>
                <th>Catatan Stok</th>
                <th>Diajukan Oleh</th>
                <th>Diajukan Pada</th>
                <?php if ($canDecideStockRequests): ?>
                    <th>Aksi</th>
                <?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($pendingStockRequests as $request): ?>
                <?php $metadata = decode_request_metadata($request['metadata'] ?? null); ?>
                <tr>
                    <td><?= sanitize($request['product_name']) ?></td>
                    <td><?= (int) $request['requested_quantity'] ?></td>
                    <td><?= sanitize($request['reason']) ?></td>
                    <td>
                        <?php if ($metadata): ?>
                            <span class="muted">
                                Stok saat diajukan: <?= isset($metadata['current_stock']) ? (int) $metadata['current_stock'] : '-' ?>
                                <?php if (!empty($metadata['record_expense'])): ?>
                                    &middot; Catat pengeluaran
                                <?php endif; ?>
                            </span>
                        <?php else: ?>
                            <span class="muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td><?= sanitize($request['created_by_name'] ?? '-') ?></td>
                    <td><?= format_date($request['created_at'], true) ?></td>
                    <?php if ($canDecideStockRequests): ?>
                        <td>
                            <div style="display:flex; flex-direction:column; gap:0.5rem;">
                                <form method="post" action="<?= BASE_URL ?>/actions/decide_stock_adjustment.php" style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center;">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                                    <input type="hidden" name="decision" value="approve">
                                    <button class="button small" type="submit">Setujui & Terapkan</button>
                                </form>
                                <form method="post" action="<?= BASE_URL ?>/actions/decide_stock_adjustment.php">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                                    <input type="hidden" name="decision" value="reject">
                                    <label class="muted" for="stock-reject-notes-<?= (int) $request['id'] ?>" style="display:block; margin-bottom:0.25rem;">Catatan (opsional)</label>
                                    <textarea id="stock-reject-notes-<?= (int) $request['id'] ?>" name="notes" rows="2" placeholder="Alasan penolakan"></textarea>
                                    <button class="button secondary small" type="submit" style="margin-top:0.25rem;">Tolak</button>
                                </form>
                            </div>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$canDecideStockRequests): ?>
            <p class="muted" style="margin-top:0.75rem;">Hubungi manajer untuk memproses penyesuaian ini.</p>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="card" style="margin-top:1.5rem;">
    <h2>Riwayat Pengajuan Penyesuaian</h2>
    <?php if (!$historyStockRequests): ?>
        <p class="muted">Belum ada riwayat penyesuaian yang diputuskan.</p>
    <?php else: ?>
        <table class="table">
            <thead>
            <tr>
                <th>Produk</th>
                <th>Jumlah</th>
                <th>Alasan</th>
                <th>Status</th>
                <th>Diputuskan Oleh</th>
                <th>Catatan</th>
                <th>Diajukan Pada</th>
                <th>Diputuskan Pada</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($historyStockRequests as $history): ?>
                <tr>
                    <td><?= sanitize($history['product_name']) ?></td>
                    <td><?= (int) $history['requested_quantity'] ?></td>
                    <td><?= sanitize($history['reason']) ?></td>
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
    <h2>Riwayat Penyesuaian Stok</h2>
    <table class="table">
        <thead>
        <tr>
            <th>Produk</th>
            <th>Tipe Penyesuaian</th>
            <th>Jumlah</th>
            <th>Alasan</th>
            <th>Disesuaikan Oleh</th>
            <th>Tanggal</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($adjustments as $adjustment): ?>
            <tr>
                <td><?= sanitize($adjustment['product_name']) ?></td>
                <td><?= sanitize($adjustment['adjustment_type']) ?></td>
                <td><?= (int) $adjustment['quantity'] ?></td>
                <td><?= sanitize($adjustment['reason']) ?></td>
                <td><?= sanitize($adjustment['adjusted_by_username'] ?? 'N/A') ?></td>
                <td><?= format_date($adjustment['created_at'], true) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
