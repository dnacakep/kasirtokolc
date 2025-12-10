<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';

require_role(ROLE_MANAJER);
guard_post();
verify_csrf_token($_POST['csrf_token'] ?? '');

$start = $_POST['start'] ?? date('Y-m-01');
$end = $_POST['end'] ?? date('Y-m-d');

$pdo = get_db_connection();
$summaryStmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(s.grand_total), 0) AS total_penjualan,
        COALESCE(SUM(s.discount_amount), 0) AS total_diskon,
        COALESCE(SUM(CASE WHEN si.quantity > 0 THEN si.quantity * pb.purchase_price ELSE 0 END), 0) AS total_modal,
        COALESCE(SUM(s.points_used), 0) AS total_poin_digunakan,
        COALESCE(SUM(s.points_earned), 0) AS total_poin_didapat
    FROM sales s
    LEFT JOIN sale_items si ON si.sale_id = s.id
    LEFT JOIN product_batches pb ON pb.id = si.batch_id
    WHERE DATE(s.created_at) BETWEEN :start AND :end
");
$summaryStmt->execute([
    ':start' => $start,
    ':end' => $end,
]);
$summary = $summaryStmt->fetch();

$expenseStmt = $pdo->prepare("
    SELECT COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) AS total_pengeluaran
    FROM expenses
    WHERE DATE(expense_date) BETWEEN :start AND :end
");
$expenseStmt->execute([
    ':start' => $start,
    ':end' => $end,
]);
$totalExpenses = (float) $expenseStmt->fetchColumn();

$labaKotor = (float) $summary['total_penjualan'] - (float) $summary['total_modal'];
$labaBersih = $labaKotor - $totalExpenses;

$dailyStmt = $pdo->prepare("
    SELECT
        dates.dt AS tanggal,
        COALESCE(revenueAgg.total_penjualan, 0) AS total_penjualan,
        COALESCE(cogsAgg.total_modal, 0) AS total_modal,
        COALESCE(expenseAgg.total_pengeluaran, 0) AS total_pengeluaran
    FROM (
        SELECT DATE(created_at) AS dt
        FROM sales
        WHERE DATE(created_at) BETWEEN :start_sales AND :end_sales
        UNION
        SELECT DATE(expense_date) AS dt
        FROM expenses
        WHERE DATE(expense_date) BETWEEN :start_expense AND :end_expense
    ) AS dates
    LEFT JOIN (
        SELECT
            DATE(created_at) AS tanggal,
            SUM(grand_total) AS total_penjualan
        FROM sales
        WHERE DATE(created_at) BETWEEN :start_sales AND :end_sales
        GROUP BY DATE(created_at)
    ) AS revenueAgg ON revenueAgg.tanggal = dates.dt
    LEFT JOIN (
        SELECT
            DATE(s.created_at) AS tanggal,
            SUM(CASE WHEN si.quantity > 0 THEN si.quantity * pb.purchase_price ELSE 0 END) AS total_modal
        FROM sales s
        LEFT JOIN sale_items si ON si.sale_id = s.id
        LEFT JOIN product_batches pb ON pb.id = si.batch_id
        WHERE DATE(s.created_at) BETWEEN :start_sales AND :end_sales
        GROUP BY DATE(s.created_at)
    ) AS cogsAgg ON cogsAgg.tanggal = dates.dt
    LEFT JOIN (
        SELECT
            DATE(expense_date) AS tanggal,
            SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) AS total_pengeluaran
        FROM expenses
        WHERE DATE(expense_date) BETWEEN :start_expense AND :end_expense
        GROUP BY DATE(expense_date)
    ) AS expenseAgg ON expenseAgg.tanggal = dates.dt
    WHERE dates.dt BETWEEN :start_sales AND :end_sales
    ORDER BY dates.dt ASC
");
$dailyStmt->execute([
    ':start_sales' => $start,
    ':end_sales' => $end,
    ':start_expense' => $start,
    ':end_expense' => $end,
]);
$dailyRows = $dailyStmt->fetchAll();

$salesStmt = $pdo->prepare("
    SELECT
        s.invoice_code,
        s.created_at,
        s.payment_method,
        s.grand_total,
        s.discount_amount,
        s.points_used,
        s.points_earned,
        u.full_name AS kasir,
        m.name AS member_name,
        COALESCE(SUM(CASE WHEN si.quantity > 0 THEN si.quantity * pb.purchase_price ELSE 0 END), 0) AS total_modal
    FROM sales s
    LEFT JOIN users u ON u.id = s.cashier_id
    LEFT JOIN members m ON m.id = s.member_id
    LEFT JOIN sale_items si ON si.sale_id = s.id
    LEFT JOIN product_batches pb ON pb.id = si.batch_id
    WHERE DATE(s.created_at) BETWEEN :start AND :end
    GROUP BY
        s.id,
        s.invoice_code,
        s.created_at,
        s.payment_method,
        s.grand_total,
        s.discount_amount,
        s.points_used,
        s.points_earned,
        u.full_name,
        m.name
    ORDER BY s.created_at ASC
");
$salesStmt->execute([
    ':start' => $start,
    ':end' => $end,
]);
$sales = $salesStmt->fetchAll();

$itemStmt = $pdo->prepare("
    SELECT
        s.invoice_code,
        s.created_at,
        p.barcode AS barcode_produk,
        p.name AS nama_produk,
        si.quantity,
        si.price,
        si.total,
        COALESCE(si.quantity * pb.purchase_price, 0) AS total_modal
    FROM sale_items si
    INNER JOIN sales s ON s.id = si.sale_id
    LEFT JOIN products p ON p.id = si.product_id
    LEFT JOIN product_batches pb ON pb.id = si.batch_id
    WHERE DATE(s.created_at) BETWEEN :start AND :end
    ORDER BY s.created_at ASC, s.invoice_code ASC, p.name ASC
");
$itemStmt->execute([
    ':start' => $start,
    ':end' => $end,
]);
$itemDetails = $itemStmt->fetchAll();

$filename = sprintf('laporan_penjualan_%s_sd_%s.csv', $start, $end);
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

fputcsv($output, ['Ringkasan Periode']);
fputcsv($output, ['Periode Mulai', $start]);
fputcsv($output, ['Periode Selesai', $end]);
fputcsv($output, ['Total Penjualan', $summary['total_penjualan']]);
fputcsv($output, ['Total Diskon', $summary['total_diskon']]);
fputcsv($output, ['Total Modal', $summary['total_modal']]);
fputcsv($output, ['Total Pengeluaran', $totalExpenses]);
fputcsv($output, ['Laba Kotor', $labaKotor]);
fputcsv($output, ['Laba Bersih', $labaBersih]);
fputcsv($output, ['Total Poin Digunakan', $summary['total_poin_digunakan']]);
fputcsv($output, ['Total Poin Didapat', $summary['total_poin_didapat']]);

fputcsv($output, []);
fputcsv($output, ['Laporan Harian']);
fputcsv($output, ['Tanggal', 'Total Penjualan', 'Total Modal', 'Total Pengeluaran', 'Laba Kotor', 'Laba Bersih']);
foreach ($dailyRows as $row) {
    $dailyLabaKotor = (float) $row['total_penjualan'] - (float) $row['total_modal'];
    $dailyLabaBersih = $dailyLabaKotor - (float) $row['total_pengeluaran'];
    fputcsv($output, [
        $row['tanggal'],
        $row['total_penjualan'],
        $row['total_modal'],
        $row['total_pengeluaran'],
        $dailyLabaKotor,
        $dailyLabaBersih,
    ]);
}

fputcsv($output, []);
fputcsv($output, ['Detail Transaksi']);
fputcsv($output, [
    'Invoice',
    'Tanggal',
    'Metode Bayar',
    'Total',
    'Diskon',
    'Total Modal',
    'Laba Kotor',
    'Poin Digunakan',
    'Poin Didapat',
    'Kasir',
    'Member',
]);

foreach ($sales as $sale) {
    $saleModal = (float) $sale['total_modal'];
    $saleGrossProfit = (float) $sale['grand_total'] - $saleModal;
    fputcsv($output, [
        $sale['invoice_code'],
        $sale['created_at'],
        $sale['payment_method'],
        $sale['grand_total'],
        $sale['discount_amount'],
        $saleModal,
        $saleGrossProfit,
        $sale['points_used'],
        $sale['points_earned'],
        $sale['kasir'],
        $sale['member_name'],
    ]);
}

fputcsv($output, []);
fputcsv($output, ['Detail Barang Terjual']);
fputcsv($output, [
    'Invoice',
    'Tanggal',
    'Barcode',
    'Nama Barang',
    'Jumlah',
    'Harga Satuan',
    'Subtotal',
    'Total Modal',
    'Laba Kotor',
]);

foreach ($itemDetails as $item) {
    $itemModal = (float) $item['total_modal'];
    $itemGrossProfit = (float) $item['total'] - $itemModal;
    fputcsv($output, [
        $item['invoice_code'],
        $item['created_at'],
        $item['barcode_produk'],
        $item['nama_produk'],
        $item['quantity'],
        $item['price'],
        $item['total'],
        $itemModal,
        $itemGrossProfit,
    ]);
}

fclose($output);
exit;
