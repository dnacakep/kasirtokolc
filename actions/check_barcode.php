<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';

header('Content-Type: application/json');

// Hanya izinkan metode GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit();
}

$barcode = trim($_GET['barcode'] ?? '');
$exclude_id = (int) ($_GET['exclude_id'] ?? 0); // Untuk kasus edit barang

if ($barcode === '' || !ctype_digit($barcode)) {
    http_response_code(400);
    echo json_encode(['error' => 'Barcode harus berupa angka.']);
    exit();
}

$pdo = get_db_connection();

try {
    $sql = "SELECT id, name FROM products WHERE barcode = :barcode";
    if ($exclude_id > 0) {
        $sql .= " AND id != :exclude_id";
    }
    $stmt = $pdo->prepare($sql);
    $params = [':barcode' => $barcode];
    if ($exclude_id > 0) {
        $params[':exclude_id'] = $exclude_id;
    }
    $stmt->execute($params);

    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($product) {
        echo json_encode([
            'exists' => true,
            'product' => [
                'id' => (int) $product['id'],
                'name' => $product['name'],
            ],
        ]);
    } else {
        echo json_encode(['exists' => false]);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}

?>
