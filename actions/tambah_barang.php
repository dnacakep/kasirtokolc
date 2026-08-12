<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';
require_once __DIR__ . '/../includes/activity_logger.php';

require_role(ROLE_KASIR);
guard_post();
verify_csrf_token($_POST['csrf_token'] ?? '');

function ini_size_to_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }

    $unit = strtolower(substr($value, -1));
    $number = (float) $value;

    switch ($unit) {
        case 'g':
            return (int) ($number * 1024 * 1024 * 1024);
        case 'm':
            return (int) ($number * 1024 * 1024);
        case 'k':
            return (int) ($number * 1024);
        default:
            return (int) $number;
    }
}

$pdo = get_db_connection();
ensure_product_image_support($pdo);
$supportsProductImage = db_column_exists($pdo, 'products', 'image_path');
$supportsBatchLabelPrinted = db_column_exists($pdo, 'product_batches', 'label_printed');
$supportsBatchLabelPrintedAt = db_column_exists($pdo, 'product_batches', 'label_printed_at');

function parse_short_date(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }

    $digits = preg_replace('/\D+/', '', $trimmed);
    if ($digits !== '') {
        if (strlen($digits) === 6) {
            $day = (int) substr($digits, 0, 2);
            $month = (int) substr($digits, 2, 2);
            $year = 2000 + (int) substr($digits, 4, 2);

            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
            return null;
        }
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed)) {
        [$year, $month, $day] = array_map('intval', explode('-', $trimmed));
        if (checkdate($month, $day, $year)) {
            return $trimmed;
        }
    }

    return null;
}

function normalize_received_at_value(?string $value): ?string
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

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
$postMaxSize = ini_size_to_bytes((string) ini_get('post_max_size'));
if ($postMaxSize > 0 && $contentLength > $postMaxSize) {
    redirect_with_message('/index.php?page=barang', 'Upload terlalu besar untuk diproses server. Kecilkan ukuran gambar lalu coba lagi.', 'error');
}

$rawBarcode = isset($_POST['barcode']) ? trim((string) $_POST['barcode']) : '';
$barcode = $rawBarcode === '' ? null : $rawBarcode;

if ($barcode !== null && !ctype_digit($barcode)) {
    redirect_with_message('/index.php?page=barang', 'Barcode hanya boleh berisi angka.', 'error');
}

$imagePath = null;
if (!empty($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    if (!$supportsProductImage) {
        redirect_with_message('/index.php?page=barang', 'Database server belum memiliki kolom foto barang. Tambahkan barang tanpa foto dulu, atau jalankan update struktur database.', 'error');
    }

    if (($_FILES['image']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        redirect_with_message('/index.php?page=barang', 'Gagal mengunggah foto barang.', 'error');
    }

    if (($_FILES['image']['size'] ?? 0) > 10 * 1024 * 1024) {
        redirect_with_message('/index.php?page=barang', 'Ukuran foto maksimal 10 MB.', 'error');
    }

    try {
        $imagePath = store_product_image($_FILES['image']);
    } catch (Throwable $e) {
        redirect_with_message('/index.php?page=barang', $e->getMessage(), 'error');
    }
}

// Data untuk tabel products
$productData = [
    ':barcode' => $barcode,
    ':name' => trim($_POST['name'] ?? ''),
    ':category_id' => !empty($_POST['category_id']) ? (int) $_POST['category_id'] : null,
    ':unit' => trim($_POST['unit'] ?? ''),
    ':stock_minimum' => (int) ($_POST['stock_minimum'] ?? 0),
    ':description' => trim($_POST['description'] ?? ''),
    ':points_reward' => (int) ($_POST['points_reward'] ?? 0),
];

if ($supportsProductImage) {
    $productData[':image_path'] = $imagePath;
}

// Data untuk tabel product_batches. Nama input di form adalah 'stock_initial'.
$stockInitial = (float) ($_POST['stock_initial'] ?? 0);
$expiryParsed = parse_short_date($_POST['expiry_date'] ?? null);
$receivedAtNormalized = normalize_received_at_value($_POST['received_at'] ?? null);

if (isset($_POST['expiry_date']) && $_POST['expiry_date'] !== '' && $expiryParsed === null) {
    redirect_with_message('/index.php?page=barang', 'Format tanggal kadaluarsa tidak valid. Gunakan format ddmmyy.', 'error');
}

if ($receivedAtNormalized === null) {
    redirect_with_message('/index.php?page=barang', 'Format tanggal masuk tidak valid.', 'error');
}

$batchData = [
    ':supplier_id' => !empty($_POST['supplier_id']) ? (int) $_POST['supplier_id'] : null,
    ':batch_code' => trim($_POST['batch_code'] ?? ''),
    ':stock_in' => $stockInitial,
    ':purchase_price' => (float) ($_POST['purchase_price'] ?? 0),
    ':sell_price' => (float) ($_POST['sell_price'] ?? 0),
    ':expiry_date' => $expiryParsed,
    ':received_at' => $receivedAtNormalized,
];

if ($batchData[':batch_code'] === '') {
    $batchData[':batch_code'] = 'BATCH-' . date('ymdHis');
}

// Validasi dasar
if (empty($productData[':name']) || empty($batchData[':batch_code']) || $stockInitial <= 0) {
    redirect_with_message('/index.php?page=barang', 'Nama barang, kode batch, dan jumlah masuk wajib diisi dengan benar.', 'error');
}

if ($batchData[':supplier_id'] === null) {
    redirect_with_message('/index.php?page=barang', 'Pemasok wajib dipilih untuk stok awal barang.', 'error');
}

if ($productData[':barcode'] !== null) {
    $stmt = $pdo->prepare("SELECT id FROM products WHERE barcode = :barcode");
    $stmt->execute([':barcode' => $productData[':barcode']]);
    if ($stmt->fetch()) {
        redirect_with_message('/index.php?page=barang', 'Barcode sudah digunakan oleh produk lain.', 'error');
    }
}

$user = current_user();
$tierMinQtys = $_POST['tier_min_qty'] ?? [];
$tierPrices = $_POST['tier_price'] ?? [];

try {
    ensure_tiered_prices_schema($pdo);
    $supportsTieredPrices = db_table_exists($pdo, 'tiered_prices');

    $pdo->beginTransaction();

    // 1. Simpan ke tabel products
    $productColumns = ['barcode', 'name', 'category_id', 'unit', 'stock_minimum', 'description', 'points_reward'];
    $productPlaceholders = [':barcode', ':name', ':category_id', ':unit', ':stock_minimum', ':description', ':points_reward'];
    if ($supportsProductImage) {
        $productColumns[] = 'image_path';
        $productPlaceholders[] = ':image_path';
    }
    $productColumns[] = 'created_at';
    $productPlaceholders[] = 'NOW()';
    $productColumns[] = 'updated_at';
    $productPlaceholders[] = 'NOW()';

    $stmt = $pdo->prepare(
        'INSERT INTO products (' . implode(', ', $productColumns) . ') VALUES (' . implode(', ', $productPlaceholders) . ')'
    );
    $stmt->execute($productData);
    $productId = (int) $pdo->lastInsertId();

    // 2. Simpan ke tabel product_batches
    $batchColumns = ['product_id', 'supplier_id', 'batch_code', 'stock_in', 'stock_remaining', 'purchase_price', 'sell_price', 'expiry_date', 'received_at'];
    $batchPlaceholders = [':product_id', ':supplier_id', ':batch_code', ':stock_in', ':stock_remaining', ':purchase_price', ':sell_price', ':expiry_date', ':received_at'];
    if ($supportsBatchLabelPrinted) {
        $batchColumns[] = 'label_printed';
        $batchPlaceholders[] = '0';
    }
    if ($supportsBatchLabelPrintedAt) {
        $batchColumns[] = 'label_printed_at';
        $batchPlaceholders[] = 'NULL';
    }
    $batchColumns[] = 'created_at';
    $batchPlaceholders[] = 'NOW()';
    $batchColumns[] = 'updated_at';
    $batchPlaceholders[] = 'NOW()';

    $stmt = $pdo->prepare(
        'INSERT INTO product_batches (' . implode(', ', $batchColumns) . ') VALUES (' . implode(', ', $batchPlaceholders) . ')'
    );
    $stmt->execute([
        ':product_id' => $productId,
        ':supplier_id' => $batchData[':supplier_id'],
        ':batch_code' => $batchData[':batch_code'],
        ':stock_in' => $batchData[':stock_in'],
        ':stock_remaining' => $batchData[':stock_in'], // Stok sisa sama dengan stok awal
        ':purchase_price' => $batchData[':purchase_price'],
        ':sell_price' => $batchData[':sell_price'],
        ':expiry_date' => $batchData[':expiry_date'],
        ':received_at' => $batchData[':received_at'],
    ]);
    $batchId = (int) $pdo->lastInsertId();

    // 3. Simpan ke tabel stock_adjustments
    $stmt = $pdo->prepare("
        INSERT INTO stock_adjustments (product_id, batch_id, adjustment_type, quantity, reason, created_by, created_at)
        VALUES (:product_id, :batch_id, 'initial', :quantity, :reason, :created_by, NOW())
    ");
    $stmt->execute([
        ':product_id' => $productId,
        ':batch_id' => $batchId,
        ':quantity' => $batchData[':stock_in'],
        ':reason' => 'Stok awal barang baru',
        ':created_by' => $user['id'],
    ]);

    // 4. Simpan harga grosir (tiered pricing) jika diisi
    if (is_array($tierMinQtys) && is_array($tierPrices)) {
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
            $tiers[$minQty] = $price; // unique by min_qty
        }

        if (!empty($tiers) && $supportsTieredPrices) {
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

    try {
        inventory_log('product_created', [
            'product_id' => $productId,
            'name' => $productData[':name'],
            'barcode' => $productData[':barcode'],
            'initial_stock' => $batchData[':stock_in'],
            'batch_id' => $batchId,
            'supplier_id' => $batchData[':supplier_id'],
            'purchase_price' => $batchData[':purchase_price'],
            'sell_price' => $batchData[':sell_price'],
            'user_id' => $user['id'] ?? null,
        ]);
    } catch (Throwable $logError) {
        error_log('Gagal menulis log product_created: ' . $logError->getMessage());
    }

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Gagal menyimpan barang: ' . $e->getMessage());
    if ($imagePath) {
        remove_product_image($imagePath);
    }
    // Tampilkan error jika mode debug aktif
    if (defined('APP_DEBUG') && APP_DEBUG) {
        redirect_with_message('/index.php?page=barang', 'Gagal menyimpan barang: ' . $e->getMessage(), 'error');
    } else {
        redirect_with_message('/index.php?page=barang', 'Terjadi kesalahan saat menyimpan barang: ' . $e->getMessage(), 'error');
    }
}

redirect_with_message('/index.php?page=barang', 'Barang baru berhasil ditambahkan dengan stok awalnya.');
