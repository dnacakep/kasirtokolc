<?php

if (!function_exists('ensure_csrf_token')) {
    require_once __DIR__ . '/../config/auth.php';
    require_once __DIR__ . '/../includes/fungsi.php';
}

ensure_csrf_token();

$actor = current_user();
if ($actor['role'] === ROLE_KASIR) {
    if (!headers_sent()) {
        http_response_code(403);
    }
    ?>
    <section class="card">
        <h2>Akses Terbatas</h2>
        <p class="muted">Hanya manajer atau admin yang dapat membuka laporan keuangan.</p>
    </section>
    <?php
    return;
}

$pdo = get_db_connection();

$rangeStart = $_GET['start'] ?? date('Y-m-01');
$rangeEnd = $_GET['end'] ?? date('Y-m-d');

$summaryStmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(grand_total),0) AS total_penjualan,
        COALESCE(SUM(discount_amount),0) AS total_diskon,
        COUNT(*) AS jumlah_transaksi
    FROM sales
    WHERE DATE(created_at) BETWEEN :start AND :end
");
$summaryStmt->execute([
    ':start' => $rangeStart,
    ':end' => $rangeEnd,
]);
$summary = $summaryStmt->fetch();

$expenseStmt = $pdo->prepare("
    SELECT COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END),0) FROM expenses
    WHERE DATE(expense_date) BETWEEN :start AND :end
");
$expenseStmt->execute([
    ':start' => $rangeStart,
    ':end' => $rangeEnd,
]);
$expenses = (float) $expenseStmt->fetchColumn();

$modalStmt = $pdo->prepare("
    SELECT COALESCE(SUM(CASE WHEN si.quantity > 0 THEN si.quantity * pb.purchase_price ELSE 0 END),0) AS total_modal
    FROM sale_items si
    INNER JOIN product_batches pb ON pb.id = si.batch_id
    INNER JOIN sales s ON s.id = si.sale_id
    WHERE DATE(s.created_at) BETWEEN :start AND :end
");
$modalStmt->execute([
    ':start' => $rangeStart,
    ':end' => $rangeEnd,
]);
$totalModal = (float) $modalStmt->fetchColumn();

$labaBersih = $summary['total_penjualan'] - $totalModal - $expenses;
$labaKotor = $summary['total_penjualan'] - $totalModal;

// Query untuk laba harian
$dailyProfitStmt = $pdo->prepare("
    SELECT
        dates.dt AS sale_date,
        COALESCE(revenueAgg.daily_revenue, 0) AS daily_revenue,
        COALESCE(cogsAgg.daily_cogs, 0) AS daily_cogs,
        COALESCE(expenseAgg.daily_expense, 0) AS daily_expense
    FROM (
        SELECT DATE(created_at) AS dt
        FROM sales
        WHERE DATE(created_at) BETWEEN :start_dt_sales AND :end_dt_sales
        UNION
        SELECT DATE(expense_date) AS dt
        FROM expenses
        WHERE DATE(expense_date) BETWEEN :start_dt_expenses AND :end_dt_expenses
    ) AS dates
    LEFT JOIN (
        SELECT
            DATE(created_at) AS sale_date,
            SUM(grand_total) AS daily_revenue
        FROM sales
        WHERE DATE(created_at) BETWEEN :start_sales AND :end_sales
        GROUP BY DATE(created_at)
    ) AS revenueAgg ON revenueAgg.sale_date = dates.dt
    LEFT JOIN (
        SELECT
            DATE(s.created_at) AS sale_date,
            SUM(CASE WHEN si.quantity > 0 THEN si.quantity * pb.purchase_price ELSE 0 END) AS daily_cogs
        FROM sales s
        LEFT JOIN sale_items si ON si.sale_id = s.id
        LEFT JOIN product_batches pb ON pb.id = si.batch_id
        WHERE DATE(s.created_at) BETWEEN :start_cogs AND :end_cogs
        GROUP BY DATE(s.created_at)
    ) AS cogsAgg ON cogsAgg.sale_date = dates.dt
    LEFT JOIN (
        SELECT
            DATE(expense_date) AS expense_date,
            SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) AS daily_expense
        FROM expenses
        WHERE DATE(expense_date) BETWEEN :start_expense AND :end_expense
        GROUP BY DATE(expense_date)
    ) AS expenseAgg ON expenseAgg.expense_date = dates.dt
    WHERE dates.dt BETWEEN :start AND :end
    ORDER BY dates.dt ASC
");
$dailyProfitStmt->execute([
    ':start_dt_sales' => $rangeStart,
    ':end_dt_sales' => $rangeEnd,
    ':start_dt_expenses' => $rangeStart,
    ':end_dt_expenses' => $rangeEnd,
    ':start_sales' => $rangeStart,
    ':end_sales' => $rangeEnd,
    ':start_cogs' => $rangeStart,
    ':end_cogs' => $rangeEnd,
    ':start_expense' => $rangeStart,
    ':end_expense' => $rangeEnd,
    ':start' => $rangeStart,
    ':end' => $rangeEnd,
]);
$dailyProfits = $dailyProfitStmt->fetchAll();

$chartLabels = [];
$chartData = [];
foreach ($dailyProfits as $dp) {
    $chartLabels[] = format_date($dp['sale_date']);
    $dailyNetProfit = $dp['daily_revenue'] - $dp['daily_cogs'] - $dp['daily_expense'];
    $chartData[] = $dailyNetProfit;
}

?>

<section class="card">
    <h2>Laporan Periode</h2>
    <form method="get" action="<?= BASE_URL ?>/index.php">
        <input type="hidden" name="page" value="laporan">
        <div class="grid-2">
            <div class="form-group">
                <label for="start">Mulai</label>
                <input type="date" id="start" name="start" value="<?= sanitize($rangeStart) ?>">
            </div>
            <div class="form-group">
                <label for="end">Selesai</label>
                <input type="date" id="end" name="end" value="<?= sanitize($rangeEnd) ?>">
            </div>
        </div>
        <button class="button" type="submit">Terapkan</button>
    </form>

    <div class="card-grid" style="margin-top:1.5rem;">
        <div class="card">
            <h3>Total Penjualan</h3>
            <p><?= format_rupiah($summary['total_penjualan']) ?></p>
        </div>
        <div class="card">
            <h3>Total Diskon</h3>
            <p><?= format_rupiah($summary['total_diskon']) ?></p>
        </div>
        <div class="card">
            <h3>Total Pengeluaran</h3>
            <p><?= format_rupiah($expenses) ?></p>
        </div>
        <div class="card">
            <h3>Laba Kotor</h3>
            <p><?= format_rupiah($labaKotor) ?></p>
        </div>
        <div class="card">
            <h3>Laba Bersih</h3>
            <p><?= format_rupiah($labaBersih) ?></p>
        </div>
    </div>

    <form style="margin-top:1.5rem;" method="post" action="<?= BASE_URL ?>/actions/export_laporan.php">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="start" value="<?= sanitize($rangeStart) ?>">
        <input type="hidden" name="end" value="<?= sanitize($rangeEnd) ?>">
        <button class="button secondary" type="submit">Ekspor CSV</button>
    </form>
    <form style="margin-top:0.75rem;" method="post" action="<?= BASE_URL ?>/actions/export_dataset.php">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="start" value="<?= sanitize($rangeStart) ?>">
        <input type="hidden" name="end" value="<?= sanitize($rangeEnd) ?>">
        <button class="button secondary" type="submit">Ekspor Dataset Lengkap (ZIP)</button>
    </form>
    <form style="margin-top:0.75rem;" method="post" action="<?= BASE_URL ?>/actions/export_dataset_excel.php">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="start" value="<?= sanitize($rangeStart) ?>">
        <input type="hidden" name="end" value="<?= sanitize($rangeEnd) ?>">
        <button class="button secondary" type="submit">Ekspor Excel Analisis</button>
    </form>
</section>

<section class="card" style="margin-top:1.5rem;">
    <h2>Grafik Laba Harian</h2>
    <div style="width: 100%; height: 400px;">
        <canvas id="dailyProfitChart"></canvas>
    </div>
</section>

<section class="card" style="margin-top:1.5rem;">
    <h2>Detail Penjualan</h2>
    <table class="table">
        <thead>
        <tr>
            <th>Invoice</th>
            <th>Tanggal</th>
            <th>Total Penjualan</th>
            <th>Total Modal</th>
            <th>Laba Kotor</th>
        </tr>
        </thead>
        <tbody>
        <?php
        $salesDetailStmt = $pdo->prepare("
            SELECT
                s.invoice_code,
                s.created_at,
                s.grand_total,
                COALESCE(SUM(CASE WHEN si.quantity > 0 THEN si.quantity * pb.purchase_price ELSE 0 END), 0) AS total_modal_per_sale
            FROM sales s
            LEFT JOIN sale_items si ON si.sale_id = s.id
            LEFT JOIN product_batches pb ON pb.id = si.batch_id
            WHERE DATE(s.created_at) BETWEEN :start AND :end
            GROUP BY s.id, s.invoice_code, s.created_at, s.grand_total
            ORDER BY s.created_at DESC
        ");
        $salesDetailStmt->execute([
            ':start' => $rangeStart,
            ':end' => $rangeEnd,
        ]);
        $salesDetails = $salesDetailStmt->fetchAll();
        ?>

        <?php if (empty($salesDetails)): ?>
            <tr>
                <td colspan="5" class="muted" style="text-align:center;">Tidak ada data penjualan untuk periode ini.</td>
            </tr>
        <?php else: ?>
            <?php foreach ($salesDetails as $sale): ?>
                <tr>
                    <td><?= sanitize($sale['invoice_code']) ?></td>
                    <td><?= format_date($sale['created_at'], true) ?></td>
                    <td><?= format_rupiah($sale['grand_total']) ?></td>
                    <td><?= format_rupiah($sale['total_modal_per_sale']) ?></td>
                    <td><?= format_rupiah($sale['grand_total'] - $sale['total_modal_per_sale']) ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</section>
