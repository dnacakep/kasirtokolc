<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';
require_once __DIR__ . '/../includes/member_debt.php';

require_role(ROLE_KASIR);
ensure_csrf_token();

$pdo = get_db_connection();
ensure_member_debt_schema($pdo);
ensure_member_debt_payment_schema($pdo);

$debtsStmt = $pdo->query("
    SELECT md.*, m.name AS member_name, m.member_code, s.invoice_code, s.created_at AS sale_date
    FROM member_debts md
    INNER JOIN members m ON m.id = md.member_id
    INNER JOIN sales s ON s.id = md.sale_id
    WHERE md.total_amount > md.paid_amount
    ORDER BY md.created_at DESC
");
$debts = $debtsStmt->fetchAll();

?>

<section class="card">
    <div class="card-header">
        <h2>Hutang Member</h2>
        <p class="muted">Catat pembayaran hutang member per transaksi.</p>
    </div>

    <?php if (!$debts): ?>
        <p class="muted" style="margin:0">Tidak ada hutang aktif.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-compact">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Member</th>
                        <th>Tanggal</th>
                        <th>Total Hutang</th>
                        <th>Sudah Dibayar</th>
                        <th>Sisa</th>
                        <th>Status</th>
                        <th style="width:280px;">Pembayaran</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($debts as $debt):
                    $total = (float) $debt['total_amount'];
                    $paid = (float) $debt['paid_amount'];
                    $outstanding = max(0, round($total - $paid, 2));
                    $statusLabel = $debt['status'] === 'partial' ? 'Sebagian' : ($debt['status'] === 'paid' ? 'Lunas' : 'Belum');
                ?>
                    <tr>
                        <td><strong><?= sanitize($debt['invoice_code']) ?></strong></td>
                        <td>
                            <div><?= sanitize($debt['member_name']) ?></div>
                            <small class="muted"><?= sanitize($debt['member_code']) ?></small>
                        </td>
                        <td><?= format_date($debt['sale_date'], true) ?></td>
                        <td><?= format_rupiah($total) ?></td>
                        <td><?= format_rupiah($paid) ?></td>
                        <td><strong><?= format_rupiah($outstanding) ?></strong></td>
                        <td><?= sanitize($statusLabel) ?></td>
                        <td>
                            <form method="post" action="<?= BASE_URL ?>/actions/bayar_hutang.php" class="inline-form">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="debt_id" value="<?= (int) $debt['id'] ?>">
                                <div class="grid-2" style="gap:6px; align-items:center;">
                                    <input type="number" name="amount" min="0.01" step="0.01" max="<?= $outstanding ?>" value="<?= $outstanding ?>" required>
                                    <input type="text" name="note" placeholder="Catatan (opsional)">
                                </div>
                                <div style="margin-top:6px; display:flex; gap:8px; align-items:center;">
                                    <button type="submit" class="button primary small" <?= $outstanding <= 0 ? 'disabled' : '' ?>>Bayar</button>
                                    <?php if ($outstanding > 0): ?>
                                        <small class="muted">Maks <?= format_rupiah($outstanding) ?></small>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
