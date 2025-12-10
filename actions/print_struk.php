<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';
require_once __DIR__ . '/../includes/member_debt.php';

require_login();

$saleId = (int) ($_GET['sale_id'] ?? 0);
if (!$saleId) {
    echo 'Transaksi tidak ditemukan.';
    exit;
}

$pdo = get_db_connection();

$stmt = $pdo->prepare("
    SELECT s.*, u.full_name AS kasir, m.name AS member_name
    FROM sales s
    LEFT JOIN users u ON u.id = s.cashier_id
    LEFT JOIN members m ON m.id = s.member_id
    WHERE s.id = :id
    LIMIT 1
");
$stmt->execute([':id' => $saleId]);
$sale = $stmt->fetch();

if (!$sale) {
    echo 'Transaksi tidak ditemukan.';
    exit;
}

$memberDebt = null;
if ($sale['payment_method'] === 'hutang') {
    $memberDebt = fetch_member_debt($pdo, (int) $sale['id']);
}

$member_total_points = 0;
if ($sale['member_id']) {
    $stmt = $pdo->prepare("SELECT points_balance FROM members WHERE id = :id");
    $stmt->execute([':id' => $sale['member_id']]);
    $member_total_points = (int) $stmt->fetchColumn();
}

$stmt = $pdo->prepare("
    SELECT si.*, p.name
    FROM sale_items si
    INNER JOIN products p ON p.id = si.product_id
    WHERE si.sale_id = :id
");
$stmt->execute([':id' => $saleId]);
$items = $stmt->fetchAll();

include __DIR__ . '/../print_template/struk_template.php';
