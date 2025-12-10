<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/activity_logger.php';

/**
 * Ensure that the child product has enough stock by automatically converting parent stock.
 *
 * @param PDO   $pdo
 * @param int   $childProductId
 * @param float $deficitQty
 * @param float $childSellPrice
 * @param int   $userId
 * @return void
 */
function ensure_child_stock(PDO $pdo, int $childProductId, float $deficitQty, float $childSellPrice, int $userId): void
{
    if ($deficitQty <= 0) {
        return;
    }

    $conversionStmt = $pdo->prepare("
        SELECT parent_product_id, child_quantity, auto_breakdown
        FROM product_conversions
        WHERE child_product_id = :child_id
        LIMIT 1
    ");
    $conversionStmt->execute([':child_id' => $childProductId]);
    $conversion = $conversionStmt->fetch();

    if (!$conversion || !((int) $conversion['auto_breakdown'])) {
        return;
    }

    $conversionQty = (float) $conversion['child_quantity'];
    if ($conversionQty <= 0) {
        return;
    }

    $parentProductId = (int) $conversion['parent_product_id'];
    $unitsNeeded = (int) ceil($deficitQty / $conversionQty);
    if ($unitsNeeded <= 0) {
        return;
    }

    $parentBatchStmt = $pdo->prepare("
        SELECT id, stock_remaining, supplier_id, purchase_price, sell_price, expiry_date, received_at
        FROM product_batches
        WHERE product_id = :parent_id
          AND stock_remaining > 0
        ORDER BY received_at ASC, id ASC
        FOR UPDATE
    ");
    $parentBatchStmt->execute([':parent_id' => $parentProductId]);
    $parentBatches = $parentBatchStmt->fetchAll();

    if (!$parentBatches) {
        return;
    }

    $updateParentBatchStmt = $pdo->prepare("
        UPDATE product_batches
        SET stock_remaining = stock_remaining - :qty,
            updated_at = NOW()
        WHERE id = :id
    ");
    $insertChildBatchStmt = $pdo->prepare("
        INSERT INTO product_batches
            (product_id, supplier_id, source_batch_id, batch_code, stock_in, stock_remaining, purchase_price, sell_price, expiry_date, received_at, label_printed, label_printed_at, created_at, updated_at)
        VALUES
            (:product_id, :supplier_id, :source_batch_id, :batch_code, :stock_in, :stock_remaining, :purchase_price, :sell_price, :expiry_date, :received_at, 0, NULL, NOW(), NOW())
    ");
    $insertAdjustmentStmt = $pdo->prepare("
        INSERT INTO stock_adjustments
            (product_id, batch_id, adjustment_type, quantity, reason, created_by, created_at)
        VALUES
            (:product_id, :batch_id, :adjustment_type, :quantity, :reason, :created_by, NOW())
    ");

    $unitsConverted = 0;
    $deficitRemaining = $deficitQty;
    $conversionDetails = [];

    foreach ($parentBatches as $batch) {
        if ($unitsConverted >= $unitsNeeded) {
            break;
        }

        $takeUnits = min($unitsNeeded - $unitsConverted, (int) $batch['stock_remaining']);
        if ($takeUnits <= 0) {
            continue;
        }

        $totalChildStock = (int) round($takeUnits * $conversionQty);
        if ($totalChildStock <= 0) {
            continue;
        }

        $updateParentBatchStmt->execute([
            ':qty' => $takeUnits,
            ':id' => $batch['id'],
        ]);

        $insertAdjustmentStmt->execute([
            ':product_id' => $parentProductId,
            ':batch_id' => $batch['id'],
            ':adjustment_type' => 'convert_out',
            ':quantity' => $takeUnits,
            ':reason' => 'Konversi otomatis ke produk ID ' . $childProductId,
            ':created_by' => $userId,
        ]);

        $childBatchCode = sprintf(
            'AUTO-%s-%s',
            $batch['id'],
            strtoupper(bin2hex(random_bytes(2)))
        );

        $childPurchasePrice = $conversionQty > 0 ? round(((float) $batch['purchase_price']) / $conversionQty, 2) : 0;
        $receivedAt = $batch['received_at'] ?: date('Y-m-d H:i:s');

        $insertChildBatchStmt->execute([
            ':product_id' => $childProductId,
            ':supplier_id' => $batch['supplier_id'],
            ':source_batch_id' => $batch['id'],
            ':batch_code' => $childBatchCode,
            ':stock_in' => $totalChildStock,
            ':stock_remaining' => $totalChildStock,
            ':purchase_price' => $childPurchasePrice,
            ':sell_price' => $childSellPrice,
            ':expiry_date' => $batch['expiry_date'],
            ':received_at' => $receivedAt,
        ]);

        $childBatchId = (int) $pdo->lastInsertId();

        $insertAdjustmentStmt->execute([
            ':product_id' => $childProductId,
            ':batch_id' => $childBatchId,
            ':adjustment_type' => 'convert_in',
            ':quantity' => $totalChildStock,
            ':reason' => 'Konversi otomatis dari produk ID ' . $parentProductId,
            ':created_by' => $userId,
        ]);

        $conversionDetails[] = [
            'parent_batch_id' => (int) $batch['id'],
            'child_batch_id' => $childBatchId,
            'parent_units_used' => $takeUnits,
            'child_units_created' => $totalChildStock,
            'parent_purchase_price' => (float) $batch['purchase_price'],
            'child_purchase_price' => $childPurchasePrice,
        ];

        $unitsConverted += $takeUnits;
        $deficitRemaining -= $totalChildStock;

        if ($deficitRemaining <= 0) {
            break;
        }
    }

    if (!empty($conversionDetails)) {
        inventory_log('auto_conversion', [
            'parent_product_id' => $parentProductId,
            'child_product_id' => $childProductId,
            'deficit_requested' => $deficitQty,
            'units_requested' => $unitsNeeded,
            'child_sell_price' => $childSellPrice,
            'user_id' => $userId,
            'conversions' => $conversionDetails,
        ]);
    }
}

/**
 * Create, update, or delete product conversion configuration.
 *
 * @param PDO      $pdo
 * @param int      $childProductId
 * @param int|null $parentProductId
 * @param float|null $childQuantity
 * @param bool     $autoBreakdown
 * @param int|null $userId
 * @return void
 */
function upsert_product_conversion(PDO $pdo, int $childProductId, ?int $parentProductId, ?float $childQuantity, bool $autoBreakdown, ?int $userId = null): void
{
    $fetchStmt = $pdo->prepare("
        SELECT id, parent_product_id, child_quantity, auto_breakdown
        FROM product_conversions
        WHERE child_product_id = :child_id
        LIMIT 1
    ");
    $fetchStmt->execute([':child_id' => $childProductId]);
    $existing = $fetchStmt->fetch();

    $deleteStmt = $pdo->prepare("DELETE FROM product_conversions WHERE child_product_id = :child_id");

    if (
        !$parentProductId ||
        !$childQuantity ||
        $childQuantity <= 0 ||
        $parentProductId === $childProductId
    ) {
        $deleteStmt->execute([':child_id' => $childProductId]);

        if ($existing) {
            inventory_log('conversion_config', [
                'action' => 'removed',
                'child_product_id' => $childProductId,
                'previous' => [
                    'parent_product_id' => (int) $existing['parent_product_id'],
                    'child_quantity' => (float) $existing['child_quantity'],
                    'auto_breakdown' => (int) $existing['auto_breakdown'],
                ],
                'user_id' => $userId,
            ]);
        }
        return;
    }

    if ($existing) {
        $updateStmt = $pdo->prepare("
            UPDATE product_conversions
            SET parent_product_id = :parent_id,
                child_quantity = :child_quantity,
                auto_breakdown = :auto_breakdown,
                updated_at = NOW()
            WHERE id = :id
        ");
        $updateStmt->execute([
            ':parent_id' => $parentProductId,
            ':child_quantity' => $childQuantity,
            ':auto_breakdown' => $autoBreakdown ? 1 : 0,
            ':id' => $existing['id'],
        ]);

        inventory_log('conversion_config', [
            'action' => 'updated',
            'child_product_id' => $childProductId,
            'parent_product_id' => $parentProductId,
            'child_quantity' => $childQuantity,
            'auto_breakdown' => $autoBreakdown ? 1 : 0,
            'user_id' => $userId,
        ]);
        return;
    }

    $insertStmt = $pdo->prepare("
        INSERT INTO product_conversions
            (parent_product_id, child_product_id, child_quantity, auto_breakdown, created_at, updated_at)
        VALUES
            (:parent_id, :child_id, :child_quantity, :auto_breakdown, NOW(), NOW())
    ");
    $insertStmt->execute([
        ':parent_id' => $parentProductId,
        ':child_id' => $childProductId,
        ':child_quantity' => $childQuantity,
        ':auto_breakdown' => $autoBreakdown ? 1 : 0,
    ]);

    inventory_log('conversion_config', [
        'action' => 'created',
        'child_product_id' => $childProductId,
        'parent_product_id' => $parentProductId,
        'child_quantity' => $childQuantity,
        'auto_breakdown' => $autoBreakdown ? 1 : 0,
        'user_id' => $userId,
    ]);
}
