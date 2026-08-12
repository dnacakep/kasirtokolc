<?php

/**
 * Integrity Guard: Menjaga konsistensi data stok vs riwayat transaksi.
 */

function verify_product_stock_integrity(PDO $pdo, int $productId): array {
    // 1. Hitung total stok dari batches (Stok Sekarang)
    $stmt = $pdo->prepare("SELECT SUM(stock_remaining) FROM product_batches WHERE product_id = :id");
    $stmt->execute([':id' => $productId]);
    $currentStock = (float) $stmt->fetchColumn();

    // 2. Hitung stok yang seharusnya berdasarkan riwayat:
    // Rumus: (Total Stok Masuk) - (Total Terjual) + (Total Penyesuaian)
    
    // Total Masuk (Initial + Purchase)
    $stmt = $pdo->prepare("SELECT SUM(quantity) FROM stock_adjustments WHERE product_id = :id AND adjustment_type IN ('initial', 'purchase', 'convert_in')");
    $stmt->execute([':id' => $productId]);
    $totalIn = (float) $stmt->fetchColumn();

    // Total Keluar (Sale + Expired + Convert Out)
    $stmt = $pdo->prepare("SELECT SUM(quantity) FROM stock_adjustments WHERE product_id = :id AND adjustment_type IN ('sale', 'expired', 'convert_out', 'adjust')");
    $stmt->execute([':id' => $productId]);
    $totalOut = (float) $stmt->fetchColumn();

    $expectedStock = $totalIn - $totalOut;
    $diff = abs($currentStock - $expectedStock);

    if ($diff > 0.001) { // Ada selisih
        // Catat ke log error khusus
        $stmt = $pdo->prepare("INSERT INTO stock_audit_logs (product_id, batch_id, old_stock, new_stock, change_amount, action_type, created_at) 
                               VALUES (:p_id, 0, :expected, :current, :diff, 'INTEGRITY_ALERT', NOW())");
        $stmt->execute([
            ':p_id' => $productId,
            ':expected' => $expectedStock,
            ':current' => $currentStock,
            ':diff' => $currentStock - $expectedStock
        ]);

        return [
            'status' => 'FAIL',
            'diff' => $currentStock - $expectedStock,
            'expected' => $expectedStock,
            'current' => $currentStock
        ];
    }

    return ['status' => 'OK'];
}
