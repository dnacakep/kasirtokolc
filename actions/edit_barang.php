<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';
require_once __DIR__ . '/../includes/activity_logger.php';

require_role(ROLE_KASIR);
guard_post();
verify_csrf_token($_POST['csrf_token'] ?? '');

$id = (int) ($_POST['id'] ?? 0);
if (!$id) {
    redirect_with_message('/index.php?page=barang', 'Barang tidak ditemukan.', 'error');
}

$pdo = get_db_connection();
ensure_product_image_support($pdo);
$supportsProductImage = db_column_exists($pdo, 'products', 'image_path');
$user = current_user();
$rawBarcode = isset($_POST['barcode']) ? trim((string) $_POST['barcode']) : '';
$barcode = $rawBarcode === '' ? null : $rawBarcode;

if ($barcode !== null && !ctype_digit($barcode)) {
    redirect_with_message('/index.php?page=barang&edit=' . $id, 'Barcode hanya boleh berisi angka.', 'error');
}

$data = [
    ':id' => $id,
    ':barcode' => $barcode,
    ':name' => trim($_POST['name'] ?? ''),
    ':category_id' => $_POST['category_id'] ? (int) $_POST['category_id'] : null,
    ':unit' => trim($_POST['unit'] ?? ''),
    ':stock_minimum' => (int) ($_POST['stock_minimum'] ?? 0),
    ':description' => trim($_POST['description'] ?? ''),
    ':points_reward' => (int) ($_POST['points_reward'] ?? 0),
];

$tierMinQtys = $_POST['tier_min_qty'] ?? [];
$tierPrices = $_POST['tier_price'] ?? [];

if ($data[':name'] === '') {
    redirect_with_message('/index.php?page=barang&edit=' . $id, 'Nama wajib diisi.', 'error');
}

$stmtCurrent = $pdo->prepare(($supportsProductImage ? 'SELECT image_path' : 'SELECT id') . ' FROM products WHERE id = :id LIMIT 1');
$stmtCurrent->execute([':id' => $id]);
$currentProduct = $stmtCurrent->fetch();

if (!$currentProduct) {
    redirect_with_message('/index.php?page=barang', 'Barang tidak ditemukan.', 'error');
}

$previousImage = $supportsProductImage ? ($currentProduct['image_path'] ?? null) : null;
$newImagePath = null;
$imagePath = $previousImage;
$removeImageRequested = ($_POST['remove_image'] ?? '0') === '1';

if (!empty($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    if (!$supportsProductImage) {
        redirect_with_message('/index.php?page=barang&edit=' . $id, 'Database server belum memiliki kolom foto barang. Jalankan update struktur database sebelum mengunggah foto.', 'error');
    }

    if (($_FILES['image']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        redirect_with_message('/index.php?page=barang&edit=' . $id, 'Gagal mengunggah foto barang.', 'error');
    }

    if (($_FILES['image']['size'] ?? 0) > 10 * 1024 * 1024) {
        redirect_with_message('/index.php?page=barang&edit=' . $id, 'Ukuran foto maksimal 10 MB.', 'error');
    }

    try {
        $newImagePath = store_product_image($_FILES['image']);
        $imagePath = $newImagePath;
        $removeImageRequested = false;
    } catch (Throwable $e) {
        redirect_with_message('/index.php?page=barang&edit=' . $id, $e->getMessage(), 'error');
    }
}

if ($removeImageRequested) {
    $imagePath = null;
}

if ($supportsProductImage) {
    $data[':image_path'] = $imagePath;
}

try {
    ensure_tiered_prices_schema($pdo);
    $supportsTieredPrices = db_table_exists($pdo, 'tiered_prices');

    $pdo->beginTransaction();

    $setParts = [
        'barcode = :barcode',
        'name = :name',
        'category_id = :category_id',
        'unit = :unit',
        'stock_minimum = :stock_minimum',
        'description = :description',
        'points_reward = :points_reward',
        'updated_at = NOW()',
    ];
    if ($supportsProductImage) {
        array_splice($setParts, 6, 0, 'image_path = :image_path');
    }

    $stmt = $pdo->prepare("
        UPDATE products
        SET " . implode(",\n            ", $setParts) . "
        WHERE id = :id
    ");
    $stmt->execute($data);

    // Simpan ulang tiered prices
    try {
        $pdo->prepare("DELETE FROM tiered_prices WHERE product_id = :id")->execute([':id' => $id]);
    } catch (Throwable $e) {
        // Ignore jika tabel belum ada
    }

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
            $tiers[$minQty] = $price;
        }

        if (!empty($tiers) && $supportsTieredPrices) {
            ksort($tiers);
            $insertTier = $pdo->prepare("
                INSERT INTO tiered_prices (product_id, min_qty, price, created_at, updated_at)
                VALUES (:product_id, :min_qty, :price, NOW(), NOW())
            ");
            foreach ($tiers as $minQty => $price) {
                $insertTier->execute([
                    ':product_id' => $id,
                    ':min_qty' => (int) $minQty,
                    ':price' => (float) $price,
                ]);
            }
        } elseif (!empty($tiers)) {
            error_log('Harga grosir tidak disimpan karena tabel tiered_prices belum tersedia.');
        }
    }

    $pdo->commit();

    if ($newImagePath && $previousImage && $previousImage !== $newImagePath) {
        remove_product_image($previousImage);
    }

    if ($removeImageRequested && $previousImage && !$newImagePath) {
        remove_product_image($previousImage);
    }

    try {
        inventory_log('product_updated', [
            'product_id' => $id,
            'barcode' => $data[':barcode'],
            'name' => $data[':name'],
            'category_id' => $data[':category_id'],
            'unit' => $data[':unit'],
            'stock_minimum' => $data[':stock_minimum'],
            'points_reward' => $data[':points_reward'],
            'user_id' => $user['id'] ?? null,
        ]);
    } catch (Throwable $logError) {
        error_log('Gagal menulis log product_updated: ' . $logError->getMessage());
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Gagal memperbarui barang: ' . $e->getMessage());
    if ($newImagePath) {
        remove_product_image($newImagePath);
    }
    redirect_with_message('/index.php?page=barang&edit=' . $id, 'Gagal memperbarui barang: ' . $e->getMessage(), 'error');
}

redirect_with_message('/index.php?page=barang', 'Barang diperbarui.');
