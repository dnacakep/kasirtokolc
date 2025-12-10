<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';

require_login(); // Hanya user yang login yang bisa mencetak

$pdo = get_db_connection();

// Ambil data stok menipis
$lowStockItems = fetch_low_stock_items_v2($pdo);

// Set header untuk memastikan browser tidak menyimpan cache dan mengunduh jika perlu
header('Content-Type: text/html; charset=UTF-8');
header('Content-Disposition: inline; filename="daftar-stok-menipis-' . date('YmdHis') . '.html"');

// Sertakan template cetak
include __DIR__ . '/../print_template/stok_menipis_template.php';

exit;
