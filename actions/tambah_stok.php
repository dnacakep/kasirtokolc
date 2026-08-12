<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';
require_once __DIR__ . '/../includes/activity_logger.php';

require_role(ROLE_KASIR);
guard_post();
verify_csrf_token($_POST['csrf_token'] ?? '');

function parse_short_date_input(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }

    $digits = preg_replace('/\D+/', '', $trimmed);
    if ($digits !== '' && strlen($digits) === 6) {
        $day = (int) substr($digits, 0, 2);
        $month = (int) substr($digits, 2, 2);
        $year = 2000 + (int) substr($digits, 4, 2);

        if (checkdate($month, $day, $year)) {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }

        return null;
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed)) {
        [$year, $month, $day] = array_map('intval', explode('-', $trimmed));
        if (checkdate($month, $day, $year)) {
            return $trimmed;
        }
    }

    return null;
}

function normalize_received_at_input(?string $value): ?string
{
    $trimmed = trim((string) $value);
    if ($trimmed === '') {
        return date('Y-m-d H:i:s');
    }

    $formats = [
        'Y-m-d\TH:i',
        'Y-m-d\TH:i:s',
        'Y-m-d H:i',
        'Y-m-d H:i:s',
        'Y-m-d',
    ];

    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $trimmed);
        if ($date instanceof DateTime) {
            if ($format === 'Y-m-d') {
                $date->setTime(0, 0, 0);
            }
            return $date->format('Y-m-d H:i:s');
        }
    }

    return null;
}

$productId = (int) ($_POST['product_id'] ?? 0);
$batchCode = trim($_POST['batch_code'] ?? '');
$stockIn = (float) ($_POST['stock_in'] ?? 0);
$purchasePrice = (float) ($_POST['purchase_price'] ?? 0);
$sellPrice = (float) ($_POST['sell_price'] ?? 0);
$expiryDate = parse_short_date_input($_POST['expiry_date'] ?? null);
$receivedAt = normalize_received_at_input($_POST['received_at'] ?? null);
$supplierId = $_POST['supplier_id'] ? (int) $_POST['supplier_id'] : null;

if (!$productId || $batchCode === '' || $stockIn <= 0) {
    redirect_with_message('/index.php?page=stok', 'Lengkapi data batch dengan benar.', 'error');
}

if (isset($_POST['expiry_date']) && trim((string) $_POST['expiry_date']) !== '' && $expiryDate === null) {
    redirect_with_message('/index.php?page=stok', 'Format tanggal kadaluarsa tidak valid. Gunakan format ddmmyy.', 'error');
}

if ($receivedAt === null) {
    redirect_with_message('/index.php?page=stok', 'Format tanggal masuk tidak valid.', 'error');
}

$pdo = get_db_connection();
$user = current_user();

try {
    $pdo->beginTransaction();

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

    // Log ke stock_adjustments supaya muncul di halaman Riwayat Stok.
    // Gunakan adjustment_type "purchase" untuk stok masuk / tambah batch.
    $stmtAdj = $pdo->prepare("
        INSERT INTO stock_adjustments (product_id, batch_id, adjustment_type, quantity, reason, created_by, created_at)
        VALUES (:product_id, :batch_id, 'purchase', :quantity, :reason, :created_by, NOW())
    ");
    $stmtAdj->execute([
        ':product_id' => $productId,
        ':batch_id' => $batchId,
        ':quantity' => $stockIn,
        ':reason' => 'Tambah stok (batch ' . $batchCode . ')',
        ':created_by' => $user['id'] ?? null,
    ]);

    // 5. Simpan harga grosir (tiered pricing) jika diisi
    $tierMinQtys = $_POST['tier_min_qty'] ?? [];
    $tierPrices = $_POST['tier_price'] ?? [];

    if (is_array($tierMinQtys) && is_array($tierPrices)) {
        ensure_tiered_prices_schema($pdo);
        $supportsTieredPrices = db_table_exists($pdo, 'tiered_prices');

        $tiers = [];
        $count = min(count($tierMinQtys), count($tierPrices));
        for ($i = 0; $i < $count; $i++) {
            $minQty = (int) ($tierMinQtys[$i] ?? 0);
            $price = (float) ($tierPrices[$i] ?? 0);
            if ($minQty <= 0 || $price <= 0) {
                continue;
            }
            if ($minQty < 2) {
                continue;
            }
            $tiers[$minQty] = $price;
        }

        if (!empty($tiers) && $supportsTieredPrices) {
            // Hapus harga grosir lama untuk produk ini, lalu simpan yang baru
            $deleteOld = $pdo->prepare("DELETE FROM tiered_prices WHERE product_id = :product_id");
            $deleteOld->execute([':product_id' => $productId]);

            ksort($tiers);
            $insertTier = $pdo->prepare("
                INSERT INTO tiered_prices (product_id, min_qty, price, created_at, updated_at)
                VALUES (:product_id, :min_qty, :price, NOW(), NOW())
            ");
            foreach ($tiers as $minQty => $price) {
                $insertTier->execute([
                    ':product_id' => $productId,
                    ':min_qty' => (int) $minQty,
                    ':price' => (float) $price,
                ]);
            }
        } elseif (!empty($tiers)) {
            error_log('Harga grosir tidak disimpan karena tabel tiered_prices belum tersedia.');
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    redirect_with_message('/index.php?page=stok', 'Gagal menambahkan batch stok: ' . $e->getMessage(), 'error');
}

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
