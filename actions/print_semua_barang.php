<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';

require_login(); // Hanya user yang login yang bisa mencetak

$pdo = get_db_connection();

$categoryId = isset($_GET['category_id']) ? (int) $_GET['category_id'] : null;
$selectedCategoryName = null;

if ($categoryId > 0) {
    $categoryStmt = $pdo->prepare("SELECT name FROM categories WHERE id = :id LIMIT 1");
    $categoryStmt->execute([':id' => $categoryId]);
    $selectedCategoryName = $categoryStmt->fetchColumn() ?: null;
    if (!$selectedCategoryName) {
        $categoryId = null;
    }
}

$sql = "
    SELECT
        p.name AS nama_barang,
        c.name AS nama_kategori,
        COALESCE(SUM(pb.stock_remaining), 0) AS stok
    FROM products p
    LEFT JOIN product_batches pb ON p.id = pb.product_id
    LEFT JOIN categories c ON p.category_id = c.id
";
$params = [];

if ($categoryId) {
    $sql .= " WHERE p.category_id = :category_id";
    $params[':category_id'] = $categoryId;
}

$sql .= "
    GROUP BY p.id, p.name, c.name
    ORDER BY c.name ASC, p.name ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Set header untuk memastikan browser tidak menyimpan cache dan mengunduh jika perlu
header('Content-Type: text/html; charset=UTF-8');
header('Content-Disposition: inline; filename="daftar-semua-barang-' . date('YmdHis') . '.html"');

// Sertakan template cetak
include __DIR__ . '/../print_template/semua_barang_template.php';

exit;
