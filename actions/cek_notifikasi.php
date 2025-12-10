<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$pdo = get_db_connection();
$data = [
    'low_stock' => fetch_low_stock_items_v2($pdo),
    'expiring' => fetch_expiring_items($pdo),
    'label_pending' => fetch_pending_labels($pdo),
];

header('Content-Type: application/json');
echo json_encode($data);

 