<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';
require_once __DIR__ . '/../includes/activity_logger.php';
require_once __DIR__ . '/../includes/approval_helpers.php';

require_role(ROLE_MANAJER);
guard_post();
verify_csrf_token($_POST['csrf_token'] ?? '');

$requestId = (int) ($_POST['request_id'] ?? 0);
$decision = $_POST['decision'] ?? '';
$notes = trim($_POST['notes'] ?? '');

if ($requestId <= 0 || !in_array($decision, ['approve', 'reject'], true)) {
    redirect_with_message('/index.php?page=stok', 'Permintaan tidak valid.', 'error');
}

$pdo = get_db_connection();
ensure_stock_request_schema($pdo);

$pdo->beginTransaction();

$stmt = $pdo->prepare("
    SELECT sar.*, p.name AS product_name
    FROM stock_adjustment_requests sar
    INNER JOIN products p ON p.id = sar.product_id
    WHERE sar.id = :id
    FOR UPDATE
");
$stmt->execute([':id' => $requestId]);
$request = $stmt->fetch();

if (!$request) {
    $pdo->rollBack();
    redirect_with_message('/index.php?page=stok', 'Pengajuan penyesuaian tidak ditemukan.', 'error');
}

if ($request['status'] !== 'pending') {
    $pdo->rollBack();
    redirect_with_message('/index.php?page=stok', 'Pengajuan sudah diproses sebelumnya.', 'error');
}

$user = current_user();
$now = date('Y-m-d H:i:s');

if ($decision === 'reject') {
    $pdo->prepare("
        UPDATE stock_adjustment_requests
        SET status = 'rejected',
            decision_by = :decision_by,
            decision_at = :decision_at,
            decision_notes = :decision_notes
        WHERE id = :id
    ")->execute([
        ':decision_by' => $user['id'],
        ':decision_at' => $now,
        ':decision_notes' => $notes !== '' ? $notes : null,
        ':id' => $requestId,
    ]);

    $pdo->commit();

    inventory_log('stock_adjustment_rejected', [
        'request_id' => $requestId,
        'product_id' => $request['product_id'],
        'quantity' => $request['requested_quantity'],
        'reason' => $request['reason'],
        'decision_by' => $user['id'],
        'notes' => $notes,
    ]);

    redirect_with_message('/index.php?page=stok', 'Pengajuan penyesuaian stok ditolak.');
}

$productId = (int) $request['product_id'];
$requestedQty = (int) $request['requested_quantity'];
$reason = $request['reason'];
$recordExpense = (int) $request['record_expense'] === 1;

$batchStmt = $pdo->prepare("
    SELECT id, stock_remaining, purchase_price, batch_code
    FROM product_batches
    WHERE product_id = :product_id
    ORDER BY received_at ASC, id ASC
    FOR UPDATE
");
$batchStmt->execute([':product_id' => $productId]);
$batches = $batchStmt->fetchAll();

if (!$batches) {
    $pdo->rollBack();
    redirect_with_message('/index.php?page=stok', 'Tidak ada stok yang tersedia untuk disesuaikan.', 'error');
}

$remaining = $requestedQty;
$totalExpenseAmount = 0.0;
$consumedBatches = [];

$updateBatchStmt = $pdo->prepare("UPDATE product_batches SET stock_remaining = stock_remaining - :qty WHERE id = :id");
$insertAdjustmentStmt = $pdo->prepare("
    INSERT INTO stock_adjustments (product_id, batch_id, adjustment_type, quantity, reason, created_by, created_at)
    VALUES (:product_id, :batch_id, 'adjust', :quantity, :reason, :created_by, NOW())
");

foreach ($batches as $batch) {
    if ($remaining <= 0) {
        break;
    }
    $available = (int) $batch['stock_remaining'];
    if ($available <= 0) {
        continue;
    }
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
        'batch_code' => $batch['batch_code'] ?? null,
        'quantity' => $take,
        'purchase_price' => $purchasePrice,
    ];
}

if ($remaining > 0) {
    $pdo->rollBack();
    redirect_with_message('/index.php?page=stok', 'Stok tersisa tidak mencukupi untuk disesuaikan.', 'error');
}

$expenseRecorded = false;
$expenseSkippedDueToZero = false;

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
            $request['product_name'] ?: ('Produk #' . $productId),
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
            ':created_by' => $request['created_by'],
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $expenseRecorded = true;
    } else {
        $expenseSkippedDueToZero = true;
    }
}

$pdo->prepare("
    UPDATE stock_adjustment_requests
    SET status = 'approved',
        decision_by = :decision_by,
        decision_at = :decision_at,
        decision_notes = :decision_notes
    WHERE id = :id
")->execute([
    ':decision_by' => $user['id'],
    ':decision_at' => $now,
    ':decision_notes' => $notes !== '' ? $notes : null,
    ':id' => $requestId,
]);

$pdo->commit();

inventory_log('stock_adjustment_approved', [
    'request_id' => $requestId,
    'product_id' => $productId,
    'quantity' => $requestedQty,
    'reason' => $reason,
    'record_expense' => $recordExpense ? 1 : 0,
    'expense_amount' => $recordExpense ? $totalExpenseAmount : 0,
    'approved_by' => $user['id'],
    'notes' => $notes,
]);

$message = 'Penyesuaian stok disetujui dan diterapkan.';
if ($expenseRecorded) {
    $message .= ' Pengeluaran juga dicatat.';
} elseif ($expenseSkippedDueToZero) {
    $message .= ' Pengeluaran tidak dicatat karena harga beli tidak tersedia.';
}

redirect_with_message('/index.php?page=stok', $message);

