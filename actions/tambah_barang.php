<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';
require_once __DIR__ . '/../includes/stock_utils.php';

require_role(ROLE_KASIR);
guard_post();
verify_csrf_token($_POST['csrf_token'] ?? '');

$pdo = get_db_connection();
ensure_product_image_support($pdo);

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

$rawBarcode = isset($_POST['barcode']) ? trim((string) $_POST['barcode']) : '';
$barcode = $rawBarcode === '' ? null : $rawBarcode;

if ($barcode !== null && !ctype_digit($barcode)) {
    redirect_with_message('/index.php?page=barang', 'Barcode hanya boleh berisi angka.', 'error');
}

$imagePath = null;
if (!empty($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
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
    ':image_path' => $imagePath,
];

// Data untuk tabel product_batches. Nama input di form adalah 'stock_initial'.
$stockInitial = (int) ($_POST['stock_initial'] ?? 0);
$expiryParsed = parse_short_date($_POST['expiry_date'] ?? null);

if (isset($_POST['expiry_date']) && $_POST['expiry_date'] !== '' && $expiryParsed === null) {
    redirect_with_message('/index.php?page=barang', 'Format tanggal kadaluarsa tidak valid. Gunakan format ddmmyy.', 'error');
}

$batchData = [
    ':supplier_id' => (int) ($_POST['supplier_id'] ?? 0),
    ':batch_code' => trim($_POST['batch_code'] ?? ''),
    ':stock_in' => $stockInitial,
    ':purchase_price' => (float) ($_POST['purchase_price'] ?? 0),
    ':sell_price' => (float) ($_POST['sell_price'] ?? 0),
    ':expiry_date' => $expiryParsed,
    ':received_at' => !empty($_POST['received_at']) ? $_POST['received_at'] : date('Y-m-d'),
];

if ($batchData[':batch_code'] === '') {
    $batchData[':batch_code'] = 'BATCH-' . date('ymdHis');
}

// Validasi dasar
if (empty($productData[':name']) || empty($batchData[':batch_code']) || $stockInitial <= 0) {
    redirect_with_message('/index.php?page=barang', 'Nama barang, kode batch, dan jumlah masuk wajib diisi dengan benar.', 'error');
}

if ($productData[':barcode'] !== null) {
    $stmt = $pdo->prepare("SELECT id FROM products WHERE barcode = :barcode");
    $stmt->execute([':barcode' => $productData[':barcode']]);
    if ($stmt->fetch()) {
        redirect_with_message('/index.php?page=barang', 'Barcode sudah digunakan oleh produk lain.', 'error');
    }
}

$user = current_user();
$conversionEnabled = ($_POST['conversion_enabled'] ?? '0') === '1';
$conversionParentId = $conversionEnabled ? (int) ($_POST['conversion_parent_id'] ?? 0) : 0;
$conversionChildQty = $conversionEnabled ? (float) ($_POST['conversion_child_qty'] ?? 0) : 0.0;
$conversionAutoBreak = $conversionEnabled ? (($_POST['conversion_auto_break'] ?? '0') === '1') : false;

if ($conversionEnabled) {
    if (!$conversionParentId) {
        redirect_with_message('/index.php?page=barang', 'Pilih barang sumber untuk konversi stok.', 'error');
    }
    if ($conversionChildQty <= 0) {
        redirect_with_message('/index.php?page=barang', 'Jumlah konversi harus lebih dari 0.', 'error');
    }
}

try {
    $pdo->beginTransaction();

    // 1. Simpan ke tabel products
    $stmt = $pdo->prepare("
        INSERT INTO products (barcode, name, category_id, unit, stock_minimum, description, image_path, points_reward, is_active, created_at, updated_at)
        VALUES (:barcode, :name, :category_id, :unit, :stock_minimum, :description, :image_path, :points_reward, 1, NOW(), NOW())
    ");
    $stmt->execute($productData);
    $productId = (int) $pdo->lastInsertId();

    // 2. Simpan ke tabel product_batches
    $stmt = $pdo->prepare("
        INSERT INTO product_batches (product_id, supplier_id, batch_code, stock_in, stock_remaining, purchase_price, sell_price, expiry_date, received_at, created_at, updated_at)
        VALUES (:product_id, :supplier_id, :batch_code, :stock_in, :stock_remaining, :purchase_price, :sell_price, :expiry_date, :received_at, NOW(), NOW())
    ");
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
        VALUES (:product_id, :batch_id, 'in', :quantity, :reason, :created_by, NOW())
    ");
    $stmt->execute([
        ':product_id' => $productId,
        ':batch_id' => $batchId,
        ':quantity' => $batchData[':stock_in'],
        ':reason' => 'Stok awal barang baru',
        ':created_by' => $user['id'],
    ]);

    if ($conversionEnabled) {
        upsert_product_conversion(
            $pdo,
            $productId,
            $conversionParentId ?: null,
            $conversionChildQty ?: null,
            $conversionAutoBreak,
            $user['id'] ?? null
        );
    }

    $pdo->commit();

    inventory_log('product_created', [
        'product_id' => $productId,
        'name' => $productData[':name'],
        'barcode' => $productData[':barcode'],
        'initial_stock' => $batchData[':stock_in'],
        'batch_id' => $batchId,
        'supplier_id' => $batchData[':supplier_id'],
        'purchase_price' => $batchData[':purchase_price'],
        'sell_price' => $batchData[':sell_price'],
        'conversion_enabled' => $conversionEnabled ? 1 : 0,
        'user_id' => $user['id'] ?? null,
    ]);

} catch (Throwable $e) {
    $pdo->rollBack();
    if ($imagePath) {
        remove_product_image($imagePath);
    }
    // Tampilkan error jika mode debug aktif
    if (defined('APP_DEBUG') && APP_DEBUG) {
        redirect_with_message('/index.php?page=barang', 'Gagal menyimpan barang: ' . $e->getMessage(), 'error');
    } else {
        redirect_with_message('/index.php?page=barang', 'Terjadi kesalahan saat menyimpan barang.', 'error');
    }
}

redirect_with_message('/index.php?page=barang', 'Barang baru berhasil ditambahkan dengan stok awalnya.');
