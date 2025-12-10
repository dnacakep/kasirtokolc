<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';
require_once __DIR__ . '/../includes/stock_utils.php';
require_once __DIR__ . '/../includes/member_debt.php';

require_role(ROLE_KASIR);
guard_post();
verify_csrf_token($_POST['csrf_token'] ?? '');

$pdo = get_db_connection();
$ensurePaymentEnum = static function (PDO $pdo): void {
    static $initialized = false;
    if ($initialized) {
        return;
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM sales LIKE 'payment_method'");
        $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;

        $type = strtolower((string) ($column['Type'] ?? ''));
        $isEnum = str_contains($type, 'enum(');
        $supportsHutang = str_contains($type, "'hutang'");
        $isCharLike = str_contains($type, 'char(');

        $lengthOk = true;
        if ($isCharLike) {
            if (preg_match('/char\((\d+)\)/', $type, $m)) {
                $len = (int) ($m[1] ?? 0);
                // We need at least 6 chars to store "hutang" safely.
                $lengthOk = $len >= 6;
            }
        }

        if (($isEnum && $supportsHutang) || ($isCharLike && $lengthOk)) {
            $initialized = true;
            return;
        }

        // Force a permissive column that accepts all methods we use.
        $pdo->exec("ALTER TABLE sales MODIFY COLUMN payment_method VARCHAR(16) NOT NULL DEFAULT 'cash'");

        // Try to constrain back to the expected set if possible.
        try {
            $pdo->exec("ALTER TABLE sales MODIFY COLUMN payment_method ENUM('cash','debit','qris','hutang') NOT NULL DEFAULT 'cash'");
        } catch (Throwable $inner) {
            // Leave as VARCHAR(16) if enum alter fails.
        }

        // Re-check after attempting adjustments.
        $stmt2 = $pdo->query("SHOW COLUMNS FROM sales LIKE 'payment_method'");
        $column2 = $stmt2 ? $stmt2->fetch(PDO::FETCH_ASSOC) : null;
        $type2 = strtolower((string) ($column2['Type'] ?? ''));
        $isEnum2 = str_contains($type2, 'enum(');
        $supportsHutang2 = str_contains($type2, "'hutang'");
        $isVarchar2 = str_contains($type2, 'char(');

        if (!($isEnum2 && $supportsHutang2) && !$isVarchar2) {
            throw new RuntimeException('Kolom payment_method belum mendukung hutang. Jalankan /actions/fix_sales_payment_method.php lalu coba lagi.');
        }
    } catch (Throwable $e) {
        throw new RuntimeException('Gagal memastikan kolom payment_method mendukung hutang: ' . $e->getMessage());
    }

    $initialized = true;
};

$ensurePaymentEnum($pdo);
$user = current_user();

$productIds = $_POST['product_id'] ?? [];
$quantities = $_POST['quantity'] ?? [];
$prices = $_POST['price'] ?? [];
$discounts = $_POST['discount'] ?? [];

$items = [];
for ($i = 0; $i < count($productIds); $i++) {
    $productId = (int) ($productIds[$i] ?? 0);
    $quantity = (float) ($quantities[$i] ?? 0);
    $price = (float) ($prices[$i] ?? 0);
    $discount = (float) ($discounts[$i] ?? 0);

    if ($productId && $quantity > 0 && $price >= 0) {
        $items[] = [
            'product_id' => $productId,
            'quantity' => $quantity,
            'price' => $price,
            'discount' => max(0, $discount),
        ];
    }
}

if (!$items) {
    redirect_with_message('/index.php?page=transaksi', 'Tambahkan minimal satu barang.', 'error');
}

$memberId = (int) ($_POST['member_id'] ?? 0);
$paymentMethod = $_POST['payment_method'] ?? 'cash';
$validPaymentMethods = ['cash', 'debit', 'qris', 'hutang'];
if (!in_array($paymentMethod, $validPaymentMethods, true)) {
    $paymentMethod = 'cash';
}
$pointsUsed = max(0, (int) ($_POST['points_used'] ?? 0));
$cashPaid = (float) ($_POST['cash_paid'] ?? 0);
$notes = trim($_POST['notes'] ?? '');

try {
    $pdo->beginTransaction();

    $subtotal = 0;
    $totalDiscount = 0;
    $totalItems = 0;
    $pointsEarned = 0;

    $productCache = [];

    foreach ($items as &$item) {
        if (!isset($productCache[$item['product_id']])) {
            $stmt = $pdo->prepare("SELECT id, name, points_reward FROM products WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $item['product_id']]);
            $product = $stmt->fetch();
            if (!$product) {
                throw new RuntimeException('Produk tidak ditemukan.');
            }
            $productCache[$item['product_id']] = $product;
        }

        $lineTotal = $item['quantity'] * $item['price'];
        $lineDiscount = min($item['discount'], $lineTotal);
        $lineNet = $lineTotal - $lineDiscount;

        $subtotal += $lineNet;
        $totalDiscount += $lineDiscount;
        $totalItems += $item['quantity'];

        $item['net_total'] = $lineNet;
        $item['name'] = $productCache[$item['product_id']]['name'];
    }
    unset($item);

    if ($paymentMethod === 'hutang' && $memberId <= 0) {
        throw new RuntimeException('Transaksi hutang wajib memilih member.');
    }

    if ($memberId > 0) {
        foreach ($items as $item) {
            $pointsReward = (int) ($productCache[$item['product_id']]['points_reward'] ?? 0);
            if ($pointsReward > 0) {
                // Calculate points based on the net total for that item line
                $pointsEarned += (int) floor($item['net_total'] / $pointsReward);
            }
        }

        $stmt = $pdo->prepare("SELECT id, points_balance FROM members WHERE id = :id AND status = 'active' LIMIT 1");
        $stmt->execute([':id' => $memberId]);
        $member = $stmt->fetch();
        if (!$member) {
            throw new RuntimeException('Member tidak valid.');
        }
        if ($pointsUsed > $member['points_balance']) {
            throw new RuntimeException('Poin member tidak mencukupi.');
        }
    } else {
        $pointsUsed = 0;
    }

    $netTotal = max(0, $subtotal - $pointsUsed);
    $debtPrincipal = 0.0;
    $debtAdminFee = 0.0;
    $recordedCashPaid = 0.0;
    $grandTotal = round($netTotal, 2);
    $change = 0.0;

    if ($paymentMethod === 'hutang') {
        $cashPaidNormalized = max(0.0, $cashPaid);
        if ($cashPaidNormalized > $netTotal) {
            $cashPaidNormalized = $netTotal;
        }

        $debtPrincipal = max(0.0, $netTotal - $cashPaidNormalized);
        if ($debtPrincipal <= 0.0) {
            // Treat as cash sale if tidak ada hutang tersisa.
            $paymentMethod = 'cash';
            $recordedCashPaid = $cashPaidNormalized;
            $grandTotal = round($netTotal, 2);
            $change = max(0.0, $recordedCashPaid - $grandTotal);
        } else {
            $debtAdminFee = round($debtPrincipal * 0.10, 2);
            $recordedCashPaid = $cashPaidNormalized;
            $grandTotal = round($netTotal + $debtAdminFee, 2);
            $change = 0.0;
        }
    }

    if ($paymentMethod === 'cash') {
        if ($recordedCashPaid === 0.0) {
            $recordedCashPaid = max(0.0, $cashPaid);
        }
        $grandTotal = round($netTotal, 2);
        $change = max(0.0, $recordedCashPaid - $grandTotal);
    } elseif ($paymentMethod !== 'hutang') {
        // Non-cash, non-credit methods ignore cash input.
        $grandTotal = round($netTotal, 2);
    }

    $invoiceCode = 'INV-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));

    $stmt = $pdo->prepare("
        INSERT INTO sales (invoice_code, member_id, cashier_id, total_items, subtotal, discount_amount, points_used, points_earned, grand_total, payment_method, cash_paid, change_returned, notes, created_at, updated_at)
        VALUES (:invoice_code, :member_id, :cashier_id, :total_items, :subtotal, :discount_amount, :points_used, :points_earned, :grand_total, :payment_method, :cash_paid, :change_returned, :notes, NOW(), NOW())
    ");
    $stmt->execute([
        ':invoice_code' => $invoiceCode,
        ':member_id' => $memberId ?: null,
        ':cashier_id' => $user['id'],
        ':total_items' => $totalItems,
        ':subtotal' => $subtotal,
        ':discount_amount' => $totalDiscount,
        ':points_used' => $pointsUsed,
        ':points_earned' => $pointsEarned,
        ':grand_total' => $grandTotal,
        ':payment_method' => $paymentMethod,
        ':cash_paid' => $recordedCashPaid,
        ':change_returned' => $change,
        ':notes' => $notes,
    ]);

    $saleId = (int) $pdo->lastInsertId();

    $insertItemStmt = $pdo->prepare("
        INSERT INTO sale_items (sale_id, product_id, batch_id, quantity, price, discount, total)
        VALUES (:sale_id, :product_id, :batch_id, :quantity, :price, :discount, :total)
    ");
    $updateBatchStmt = $pdo->prepare("UPDATE product_batches SET stock_remaining = stock_remaining - :quantity WHERE id = :id");
    $insertAdjustmentStmt = $pdo->prepare("
        INSERT INTO stock_adjustments (product_id, batch_id, adjustment_type, quantity, reason, created_by, created_at)
        VALUES (:product_id, :batch_id, 'sale', :quantity, :reason, :created_by, NOW())
    ");

    foreach ($items as $item) {
        $remainingQty = $item['quantity'];
        $lineDiscount = $item['discount'];
        $originalQty = $item['quantity'];
        $discountLeft = $lineDiscount;

        $batchStmt = $pdo->prepare("
            SELECT id, stock_remaining, sell_price
            FROM product_batches
            WHERE product_id = :product_id 
              AND stock_remaining > 0
              AND (expiry_date IS NULL OR expiry_date >= CURDATE())
            ORDER BY received_at ASC, id ASC
        ");
        $batchStmt->execute([':product_id' => $item['product_id']]);
        $batches = $batchStmt->fetchAll();

        $availableStock = 0.0;
        foreach ($batches as $batch) {
            $availableStock += (float) $batch['stock_remaining'];
        }

        if ($availableStock < $remainingQty) {
            $deficit = $remainingQty - $availableStock;
            ensure_child_stock($pdo, $item['product_id'], $deficit, $item['price'], $user['id']);

            $batchStmt->execute([':product_id' => $item['product_id']]);
            $batches = $batchStmt->fetchAll();

            $availableStock = 0.0;
            foreach ($batches as $batch) {
                $availableStock += (float) $batch['stock_remaining'];
            }
        }

        if (!$batches || $availableStock < $remainingQty) {
            throw new RuntimeException('Stok kosong untuk ' . $item['name']);
        }

        foreach ($batches as $index => $batch) {
            if ($remainingQty <= 0) {
                break;
            }

            $takeQty = min($remainingQty, $batch['stock_remaining']);
            if ($takeQty <= 0) {
                continue;
            }

            $batchDiscount = 0;
            if ($lineDiscount > 0) {
                if ($index === count($batches) - 1 || $remainingQty === $takeQty) {
                    $batchDiscount = $discountLeft;
                } else {
                    $batchDiscount = round(($takeQty / $originalQty) * $lineDiscount, 2);
                    $discountLeft -= $batchDiscount;
                }
            }

            $lineTotal = ($takeQty * $item['price']) - $batchDiscount;

            $insertItemStmt->execute([
                ':sale_id' => $saleId,
                ':product_id' => $item['product_id'],
                ':batch_id' => $batch['id'],
                ':quantity' => $takeQty,
                ':price' => $item['price'],
                ':discount' => $batchDiscount,
                ':total' => $lineTotal,
            ]);

            $updateBatchStmt->execute([
                ':quantity' => $takeQty,
                ':id' => $batch['id'],
            ]);

            $insertAdjustmentStmt->execute([
                ':product_id' => $item['product_id'],
                ':batch_id' => $batch['id'],
                ':quantity' => $takeQty,
                ':reason' => 'Penjualan ' . $invoiceCode,
                ':created_by' => $user['id'],
            ]);

            $remainingQty -= $takeQty;
        }

        if ($remainingQty > 0) {
            throw new RuntimeException('Stok tidak mencukupi untuk ' . $item['name']);
        }
    }

    if ($memberId > 0) {
        $pdo->prepare("
            UPDATE members
            SET points_balance = points_balance - :used + :earned,
                updated_at = NOW()
            WHERE id = :id
        ")->execute([
            ':used' => $pointsUsed,
            ':earned' => $pointsEarned,
            ':id' => $memberId,
        ]);

        if ($pointsUsed > 0) {
            $pdo->prepare("
                INSERT INTO member_points (member_id, sale_id, points_change, description, created_at)
                VALUES (:member_id, :sale_id, :points_change, :description, NOW())
            ")->execute([
                ':member_id' => $memberId,
                ':sale_id' => $saleId,
                ':points_change' => -$pointsUsed,
                ':description' => 'Penukaran poin pada ' . $invoiceCode,
            ]);
        }

        if ($pointsEarned > 0) {
            $pdo->prepare("
                INSERT INTO member_points (member_id, sale_id, points_change, description, created_at)
                VALUES (:member_id, :sale_id, :points_change, :description, NOW())
            ")->execute([
                ':member_id' => $memberId,
                ':sale_id' => $saleId,
                ':points_change' => $pointsEarned,
                ':description' => 'Poin transaksi ' . $invoiceCode,
            ]);
        }
    }

    if ($paymentMethod === 'hutang') {
        create_member_debt($pdo, $saleId, $memberId, $debtPrincipal, $debtAdminFee);
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    redirect_with_message('/index.php?page=transaksi', 'Gagal menyimpan transaksi: ' . $e->getMessage(), 'error');
}

set_last_sale_summary([
    'sale_id' => $saleId,
    'invoice_code' => $invoiceCode,
    'grand_total' => $grandTotal,
    'payment_method' => $paymentMethod,
    'created_at' => date('Y-m-d H:i:s'),
]);

redirect_with_message('/index.php?page=transaksi', 'Transaksi berhasil disimpan.');
