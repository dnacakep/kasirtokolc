<?php

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/fungsi.php';

$pdo = get_db_connection();

$lowStocks = fetch_low_stock_items($pdo);
$expiring = fetch_expiring_items($pdo);
$pendingLabels = fetch_pending_labels($pdo);

echo 'Ringkasan Notifikasi ' . date('Y-m-d H:i:s') . PHP_EOL;
echo '--------------------------------------' . PHP_EOL;
echo 'Stok menipis: ' . count($lowStocks) . PHP_EOL;
echo 'Kadaluarsa 7 hari: ' . count($expiring) . PHP_EOL;
echo 'Label belum dicetak: ' . count($pendingLabels) . PHP_EOL;

