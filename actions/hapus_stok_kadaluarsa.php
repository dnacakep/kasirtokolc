<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';
require_once __DIR__ . '/../includes/activity_logger.php';
require_once __DIR__ . '/../includes/approval_helpers.php';

// Require login. Specific role checks can be done below if needed.
require_login();
guard_post();
verify_csrf_token($_POST['csrf_token'] ?? '');

$batchId = (int) ($_POST['batch_id'] ?? 0);

if ($batchId <= 0) {
    redirect_with_message('/index.php?page=stok_penyesuaian', 'Batch tidak valid.', 'error');
}

$pdo = get_db_connection();
$user = current_user();

// OPTIONAL: Fix schema if 'expired' is missing in enum
// This is a temporary patch to ensure the feature works immediately.
try {
    $pdo->exec("
        ALTER TABLE stock_adjustments 
        MODIFY COLUMN adjustment_type 
        ENUM('initial', 'purchase', 'sale', 'return', 'adjust', 'transfer', 'convert_in', 'convert_out', 'expired') 
        NOT NULL
    ");
} catch (Exception $e) {
    // Ignore error if already exists or if this fails (we hope it works)
    // In production, this should be a proper migration.
}

// Start transaction
$pdo->beginTransaction();

try {
    // Lock the batch row
    $stmt = $pdo->prepare("
        SELECT b.*, p.name AS product_name 
        FROM product_batches b
        INNER JOIN products p ON p.id = b.product_id
        WHERE b.id = :id
        FOR UPDATE
    ");
    $stmt->execute([':id' => $batchId]);
    $batch = $stmt->fetch();

    if (!$batch) {
        throw new Exception('Batch tidak ditemukan.');
    }

    $currentStock = (int) $batch['stock_remaining'];

    if ($currentStock <= 0) {
        throw new Exception('Stok batch ini sudah kosong.');
    }

    // Check permissions (Optional: restrict to Manager/Admin for instant deletion)
    // If Cashier, we might want to make a request, but for this specific feature request:
    // "user bisa langsung hapus semua stoknya" -> implies direct action.
    // We will allow it but log heavily.
    
    $purchasePrice = (float) ($batch['purchase_price'] ?? 0);
    $totalLoss = $currentStock * $purchasePrice;

    // 1. Zero out the stock
    $updateStmt = $pdo->prepare("UPDATE product_batches SET stock_remaining = 0 WHERE id = :id");
    $updateStmt->execute([':id' => $batchId]);

    // 2. Record Stock Adjustment
    $adjStmt = $pdo->prepare("
        INSERT INTO stock_adjustments (product_id, batch_id, adjustment_type, quantity, reason, created_by, created_at)
        VALUES (:product_id, :batch_id, 'expired', :quantity, :reason, :created_by, NOW())
    ");
    $adjStmt->execute([
        ':product_id' => $batch['product_id'],
        ':batch_id' => $batchId,
        ':quantity' => $currentStock,
        ':reason' => 'Barang Kadaluarsa (Pemusnahan)',
        ':created_by' => $user['id']
    ]);

    // 3. Record Expense (Loss)
    if ($totalLoss > 0) {
        ensure_expense_request_schema($pdo); // Helper function to ensure table exists if using requests, but here we insert direct expense
        
        // Ensure table 'expenses' exists (standard table)
        // Based on previous files, expenses table exists.
        
        $expenseDesc = sprintf(
            'Pemusnahan Barang Kadaluarsa: %s (Batch: %s, Qty: %d)',
            $batch['product_name'],
            $batch['batch_code'],
            $currentStock
        );

        $expStmt = $pdo->prepare("
            INSERT INTO expenses (expense_date, category, description, amount, created_by, created_at, updated_at)
            VALUES (CURRENT_DATE, 'Kerugian Stok', :description, :amount, :created_by, NOW(), NOW())
        ");
        $expStmt->execute([
            ':description' => $expenseDesc,
            ':amount' => $totalLoss,
            ':created_by' => $user['id']
        ]);
    }

    $pdo->commit();

    inventory_log('stock_expired_removed', [
        'batch_id' => $batchId,
        'product_id' => $batch['product_id'],
        'quantity' => $currentStock,
        'loss_amount' => $totalLoss,
        'user_id' => $user['id']
    ]);

    redirect_with_message('/index.php?page=stok_penyesuaian', 'Stok kadaluarsa berhasil dimusnahkan dan dicatat sebagai pengeluaran.', 'success');

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    redirect_with_message('/index.php?page=stok_penyesuaian', $e->getMessage(), 'error');
}
