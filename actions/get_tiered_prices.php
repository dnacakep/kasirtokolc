<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$productId = (int) ($_GET['product_id'] ?? 0);
if (!$productId) {
    http_response_code(400);
    echo json_encode(['error' => 'product_id required']);
    exit;
}

$pdo = get_db_connection();
$tiers = fetch_tiered_prices($pdo, $productId);

header('Content-Type: application/json');
echo json_encode($tiers);
