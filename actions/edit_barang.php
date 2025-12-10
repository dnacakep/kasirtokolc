<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';
require_once __DIR__ . '/../includes/stock_utils.php';

require_role(ROLE_KASIR);
guard_post();
verify_csrf_token($_POST['csrf_token'] ?? '');

$id = (int) ($_POST['id'] ?? 0);
if (!$id) {
    redirect_with_message('/index.php?page=barang', 'Barang tidak ditemukan.', 'error');
}

$pdo = get_db_connection();
ensure_product_image_support($pdo);
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

$conversionEnabled = ($_POST['conversion_enabled'] ?? '0') === '1';
$conversionParentId = $conversionEnabled ? (int) ($_POST['conversion_parent_id'] ?? 0) : 0;
$conversionChildQty = $conversionEnabled ? (float) ($_POST['conversion_child_qty'] ?? 0) : 0.0;
$conversionAutoBreak = $conversionEnabled ? (($_POST['conversion_auto_break'] ?? '0') === '1') : false;

if ($data[':name'] === '') {
    redirect_with_message('/index.php?page=barang&edit=' . $id, 'Nama wajib diisi.', 'error');
}

if ($conversionEnabled) {
    if ($conversionParentId && $conversionParentId === $id) {
        redirect_with_message('/index.php?page=barang&edit=' . $id, 'Barang sumber tidak boleh sama dengan barang saat ini.', 'error');
    }

    if (!$conversionParentId) {
        redirect_with_message('/index.php?page=barang&edit=' . $id, 'Pilih barang sumber untuk konversi stok.', 'error');
    }

    if ($conversionChildQty <= 0) {
        redirect_with_message('/index.php?page=barang&edit=' . $id, 'Jumlah konversi harus lebih dari 0.', 'error');
    }
}

$stmtCurrent = $pdo->prepare('SELECT image_path FROM products WHERE id = :id LIMIT 1');
$stmtCurrent->execute([':id' => $id]);
$currentProduct = $stmtCurrent->fetch();

if (!$currentProduct) {
    redirect_with_message('/index.php?page=barang', 'Barang tidak ditemukan.', 'error');
}

$previousImage = $currentProduct['image_path'] ?? null;
$newImagePath = null;
$imagePath = $previousImage;
$removeImageRequested = ($_POST['remove_image'] ?? '0') === '1';

if (!empty($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
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

$data[':image_path'] = $imagePath;

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        UPDATE products
        SET barcode = :barcode,
            name = :name,
            category_id = :category_id,
            unit = :unit,
            stock_minimum = :stock_minimum,
            description = :description,
            image_path = :image_path,
            points_reward = :points_reward,
            updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute($data);

    if ($conversionEnabled) {
        upsert_product_conversion(
            $pdo,
            $id,
            $conversionParentId ?: null,
            $conversionChildQty ?: null,
            $conversionAutoBreak,
            $user['id'] ?? null
        );
    } else {
        upsert_product_conversion(
            $pdo,
            $id,
            null,
            null,
            false,
            $user['id'] ?? null
        );
    }

    $pdo->commit();

    if ($newImagePath && $previousImage && $previousImage !== $newImagePath) {
        remove_product_image($previousImage);
    }

    if ($removeImageRequested && $previousImage && !$newImagePath) {
        remove_product_image($previousImage);
    }

    inventory_log('product_updated', [
        'product_id' => $id,
        'barcode' => $data[':barcode'],
        'name' => $data[':name'],
        'category_id' => $data[':category_id'],
        'unit' => $data[':unit'],
        'stock_minimum' => $data[':stock_minimum'],
        'points_reward' => $data[':points_reward'],
        'conversion_enabled' => $conversionEnabled ? 1 : 0,
        'conversion_parent_id' => $conversionParentId ?: null,
        'conversion_child_qty' => $conversionChildQty ?: null,
        'conversion_auto_break' => $conversionAutoBreak ? 1 : 0,
        'user_id' => $user['id'] ?? null,
    ]);
} catch (Throwable $e) {
    $pdo->rollBack();
    if ($newImagePath) {
        remove_product_image($newImagePath);
    }
    redirect_with_message('/index.php?page=barang&edit=' . $id, 'Gagal memperbarui barang: ' . $e->getMessage(), 'error');
}

redirect_with_message('/index.php?page=barang', 'Barang diperbarui.');
