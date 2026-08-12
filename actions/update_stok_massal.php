<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';

require_role(ROLE_MANAJER);
guard_post();
verify_csrf_token($_POST['csrf_token'] ?? '');

$pdo = get_db_connection();
$stok_data = $_POST['stok'] ?? [];

if (empty($stok_data)) {
    redirect_with_message('/index.php?page=stok_cepat', 'Tidak ada data yang diubah.', 'warning');
}

try {
    $pdo->beginTransaction();
    $user = current_user();

    $stmtUpdate = $pdo->prepare("UPDATE product_batches SET stock_remaining = :qty, updated_at = NOW() WHERE id = :id");
    $stmtLog = $pdo->prepare("INSERT INTO stock_adjustments (product_id, batch_id, adjustment_type, quantity, reason, created_by, created_at) 
                              SELECT product_id, id, 'manual', :qty, 'Update massal via Stok Cepat', :user_id, NOW() 
                              FROM product_batches WHERE id = :id");

    foreach ($stok_data as $batch_id => $qty) {
        $qty = (float)$qty;
        
        // Update stok batch
        $stmtUpdate->execute([':qty' => $qty, ':id' => $batch_id]);
        
        // Log penyesuaian (opsional, tapi bagus untuk audit)
        $stmtLog->execute([':qty' => $qty, ':user_id' => $user['id'], ':id' => $batch_id]);
    }

    $pdo->commit();
    redirect_with_message('/index.php?page=stok_cepat', 'Seluruh stok berhasil diperbarui.');
} catch (Throwable $e) {
    $pdo->rollBack();
    redirect_with_message('/index.php?page=stok_cepat', 'Gagal memperbarui stok: ' . $e->getMessage(), 'error');
}
