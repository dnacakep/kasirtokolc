<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';
require_once __DIR__ . '/../includes/activity_logger.php';
require_once __DIR__ . '/../includes/approval_helpers.php';

require_role(ROLE_KASIR);
guard_post();
verify_csrf_token($_POST['csrf_token'] ?? '');

$productId = (int) ($_POST['product_id'] ?? 0);
$quantity = (int) ($_POST['quantity'] ?? 0);
$reason = trim($_POST['reason'] ?? '');
$recordExpense = ($_POST['record_expense'] ?? '0') === '1';

if (!$productId || $quantity <= 0 || $reason === '') {
    redirect_with_message('/index.php?page=stok', 'Lengkapi penyesuaian stok.', 'error');
}

$pdo = get_db_connection();
$user = current_user();

$productStmt = $pdo->prepare("SELECT name FROM products WHERE id = :id LIMIT 1");
$productStmt->execute([':id' => $productId]);
$productName = $productStmt->fetchColumn();

if (!$productName) {
    redirect_with_message('/index.php?page=stok', 'Produk tidak ditemukan.', 'error');
}

$totalStockStmt = $pdo->prepare("SELECT COALESCE(SUM(stock_remaining),0) FROM product_batches WHERE product_id = :id");
$totalStockStmt->execute([':id' => $productId]);
$currentStock = (int) $totalStockStmt->fetchColumn();

$metadata = [
    'product_name' => $productName,
    'current_stock' => $currentStock,
    'requested_quantity' => $quantity,
    'record_expense' => $recordExpense ? 1 : 0,
];

ensure_stock_request_schema($pdo);

$insert = $pdo->prepare("
    INSERT INTO stock_adjustment_requests (product_id, requested_quantity, reason, record_expense, metadata, created_by)
    VALUES (:product_id, :requested_quantity, :reason, :record_expense, :metadata, :created_by)
");
$insert->execute([
    ':product_id' => $productId,
    ':requested_quantity' => $quantity,
    ':reason' => $reason,
    ':record_expense' => $recordExpense ? 1 : 0,
    ':metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ':created_by' => $user['id'],
]);

$requestId = (int) $pdo->lastInsertId();

inventory_log('stock_adjustment_requested', [
    'request_id' => $requestId,
    'product_id' => $productId,
    'quantity' => $quantity,
    'reason' => $reason,
    'record_expense' => $recordExpense ? 1 : 0,
    'requested_by' => $user['id'] ?? null,
]);

// AUTO-APPROVE FOR MANAGER/ADMIN
if (in_array($user['role'], [ROLE_MANAJER, ROLE_ADMIN], true)) {
    // Auto-approve logic (similar to decide_stock_adjustment.php)
    
    // Fetch batches to deduct from
    $batchStmt = $pdo->prepare("
        SELECT id, stock_remaining, purchase_price, batch_code
        FROM product_batches
        WHERE product_id = :product_id
        ORDER BY received_at ASC, id ASC
        FOR UPDATE
    ");
    $batchStmt->execute([':product_id' => $productId]);
    $batches = $batchStmt->fetchAll();
    
    $remaining = $quantity;
    $totalExpenseAmount = 0.0;
    $consumedBatches = [];
    
    // Check if enough stock
    $totalAvailable = 0;
    foreach ($batches as $b) $totalAvailable += $b['stock_remaining'];
    
    if ($totalAvailable < $quantity) {
        // Cannot fulfill request automatically due to insufficient stock.
        // We leave it as pending or we could reject it.
        // For safety, let's leave it pending with a warning, or reject immediately.
        // Better: show message that stock is insufficient, but request is saved.
        redirect_with_message('/index.php?page=stok_penyesuaian', 'Pengajuan disimpan, namun stok fisik tidak mencukupi untuk pemotongan otomatis.', 'warning');
    }
    
    $updateBatchStmt = $pdo->prepare("UPDATE product_batches SET stock_remaining = stock_remaining - :qty WHERE id = :id");
    $insertAdjustmentStmt = $pdo->prepare("
        INSERT INTO stock_adjustments (product_id, batch_id, adjustment_type, quantity, reason, created_by, created_at)
        VALUES (:product_id, :batch_id, 'adjust', :quantity, :reason, :created_by, NOW())
    ");
    
    foreach ($batches as $batch) {
        if ($remaining <= 0) break;
        
        $available = (int) $batch['stock_remaining'];
        if ($available <= 0) continue;
        
        $take = min($remaining, $available);
        
        $updateBatchStmt->execute([
            ':qty' => $take,
            ':id' => $batch['id'],
        ]);
        
        $insertAdjustmentStmt->execute([
            ':product_id' => $productId,
            ':batch_id' => $batch['id'],
            ':quantity' => $take,
            ':reason' => $reason,
            ':created_by' => $user['id'],
        ]);
        
        $remaining -= $take;
        
        $purchasePrice = isset($batch['purchase_price']) ? (float) $batch['purchase_price'] : 0.0;
        if ($purchasePrice > 0) {
            $totalExpenseAmount += $purchasePrice * $take;
        }
        
        $consumedBatches[] = [
            'batch_code' => $batch['batch_code'],
            'quantity' => $take,
            'purchase_price' => $purchasePrice,
        ];
    }
    
    // Record Expense if requested
    $expenseRecorded = false;
    if ($recordExpense) {
        $expenseAmount = round($totalExpenseAmount, 2);
        if ($expenseAmount > 0) {
            $consumedSummary = array_map(static function ($detail) {
                $code = $detail['batch_code'] ?: 'batch';
                $quantity = (int) $detail['quantity'];
                $price = number_format((float) $detail['purchase_price'], 2, ',', '.');
                return "{$code} x{$quantity} @{$price}";
            }, $consumedBatches);

            $expenseDescription = sprintf(
                'Penyesuaian stok %s - %s%s',
                $productName,
                $reason,
                $consumedSummary ? ' [' . implode('; ', $consumedSummary) . ']' : ''
            );
            
            $insertExpenseStmt = $pdo->prepare("
                INSERT INTO expenses (expense_date, category, description, amount, created_by, created_at, updated_at)
                VALUES (:expense_date, :category, :description, :amount, :created_by, :created_at, :updated_at)
            ");
            $insertExpenseStmt->execute([
                ':expense_date' => date('Y-m-d'),
                ':category' => 'Penyesuaian Stok',
                ':description' => $expenseDescription,
                ':amount' => $expenseAmount,
                ':created_by' => $user['id'],
                ':created_at' => date('Y-m-d H:i:s'),
                ':updated_at' => date('Y-m-d H:i:s'),
            ]);
            $expenseRecorded = true;
        }
    }
    
    // Update Request Status
    $pdo->prepare("
        UPDATE stock_adjustment_requests
        SET status = 'approved',
            decision_by = :decision_by,
            decision_at = NOW(),
            decision_notes = 'Auto-approved by system (Self-Approval)'
        WHERE id = :id
    ")->execute([
        ':decision_by' => $user['id'],
        ':id' => $requestId,
    ]);
    
    inventory_log('stock_adjustment_auto_approved', [
        'request_id' => $requestId,
        'product_id' => $productId,
        'quantity' => $quantity,
        'expense_recorded' => $expenseRecorded,
        'user_id' => $user['id']
    ]);
    
    $msg = 'Penyesuaian stok berhasil diterapkan.';
    if ($expenseRecorded) $msg .= ' Pengeluaran telah dicatat.';
    
    redirect_with_message('/index.php?page=stok_penyesuaian', $msg);
}

redirect_with_message('/index.php?page=stok_penyesuaian', 'Pengajuan penyesuaian stok dikirim dan menunggu persetujuan.');
