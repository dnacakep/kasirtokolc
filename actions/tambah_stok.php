<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';
require_once __DIR__ . '/../includes/activity_logger.php';

require_role(ROLE_KASIR);
guard_post();
verify_csrf_token($_POST['csrf_token'] ?? '');

$productId = (int) ($_POST['product_id'] ?? 0);
$batchCode = trim($_POST['batch_code'] ?? '');
$stockIn = (int) ($_POST['stock_in'] ?? 0);
$purchasePrice = (float) ($_POST['purchase_price'] ?? 0);
$sellPrice = (float) ($_POST['sell_price'] ?? 0);
$expiryDate = $_POST['expiry_date'] ?? null;
$receivedAt = $_POST['received_at'] ?? date('Y-m-d H:i:s');
$supplierId = $_POST['supplier_id'] ? (int) $_POST['supplier_id'] : null;

if (!$productId || $batchCode === '' || $stockIn <= 0) {
    redirect_with_message('/index.php?page=stok', 'Lengkapi data batch dengan benar.', 'error');
}

$pdo = get_db_connection();
$user = current_user();

$stmt = $pdo->prepare("
    INSERT INTO product_batches (product_id, supplier_id, batch_code, stock_in, stock_remaining, purchase_price, sell_price, expiry_date, received_at, label_printed, created_at, updated_at)
    VALUES (:product_id, :supplier_id, :batch_code, :stock_in, :stock_remaining, :purchase_price, :sell_price, :expiry_date, :received_at, 0, NOW(), NOW())
");
$stmt->execute([
    ':product_id' => $productId,
    ':supplier_id' => $supplierId,
    ':batch_code' => $batchCode,
    ':stock_in' => $stockIn,
    ':stock_remaining' => $stockIn,
    ':purchase_price' => $purchasePrice,
    ':sell_price' => $sellPrice,
    ':expiry_date' => $expiryDate ?: null,
    ':received_at' => $receivedAt,
]);
$batchId = (int) $pdo->lastInsertId();

inventory_log('stock_added', [
    'product_id' => $productId,
    'batch_id' => $batchId,
    'quantity' => $stockIn,
    'purchase_price' => $purchasePrice,
    'sell_price' => $sellPrice,
    'supplier_id' => $supplierId,
    'expiry_date' => $expiryDate ?: null,
    'received_at' => $receivedAt,
    'user_id' => $user['id'] ?? null,
]);

redirect_with_message('/index.php?page=stok', 'Batch stok ditambahkan.');
