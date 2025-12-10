<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';

require_role(ROLE_KASIR);
guard_post();
verify_csrf_token($_POST['csrf_token'] ?? '');

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    redirect_with_message('/index.php?page=barang_list', 'Barang tidak ditemukan.', 'error');
}

$pdo = get_db_connection();
ensure_product_image_support($pdo);

$rawBarcode = isset($_POST['barcode']) ? trim((string) $_POST['barcode']) : '';
$barcode = $rawBarcode === '' ? null : $rawBarcode;

if ($barcode !== null && !ctype_digit($barcode)) {
    redirect_with_message('/index.php?page=barang_list', 'Barcode hanya boleh berisi angka.', 'error');
}

$name = isset($_POST['name']) ? trim((string) $_POST['name']) : '';
if ($name === '') {
    redirect_with_message('/index.php?page=barang_list', 'Nama barang wajib diisi.', 'error');
}

$categoryId = isset($_POST['category_id']) && $_POST['category_id'] !== '' ? (int) $_POST['category_id'] : null;
$unit = isset($_POST['unit']) ? trim((string) $_POST['unit']) : '';
$stockMinimum = isset($_POST['stock_minimum']) ? max(0, (int) $_POST['stock_minimum']) : 0;
$pointsReward = isset($_POST['points_reward']) ? max(0, (int) $_POST['points_reward']) : 0;
$isActive = isset($_POST['is_active']) ? 1 : 0;
$description = isset($_POST['description']) ? trim((string) $_POST['description']) : '';
$removeImageRequested = isset($_POST['remove_image']) && $_POST['remove_image'] === '1';

$stmtProduct = $pdo->prepare('SELECT image_path FROM products WHERE id = :id LIMIT 1');
$stmtProduct->execute([':id' => $id]);
$currentProduct = $stmtProduct->fetch();

if (!$currentProduct) {
    redirect_with_message('/index.php?page=barang_list', 'Barang tidak ditemukan.', 'error');
}

$previousImage = $currentProduct['image_path'] ?? null;
$newImagePath = null;
$imagePath = $previousImage;

if (!empty($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    if (($_FILES['image']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        redirect_with_message('/index.php?page=barang_list', 'Gagal mengunggah foto barang.', 'error');
    }

    if (($_FILES['image']['size'] ?? 0) > 10 * 1024 * 1024) {
        redirect_with_message('/index.php?page=barang_list', 'Ukuran foto maksimal 10 MB.', 'error');
    }

    try {
        $newImagePath = store_product_image($_FILES['image']);
        $imagePath = $newImagePath;
        $removeImageRequested = false;
    } catch (Throwable $e) {
        redirect_with_message('/index.php?page=barang_list', $e->getMessage(), 'error');
    }
}

if ($removeImageRequested) {
    $imagePath = null;
}

try {
    if ($barcode !== null) {
        $stmtCheck = $pdo->prepare('SELECT id FROM products WHERE barcode = :barcode AND id != :id LIMIT 1');
        $stmtCheck->execute([
            ':barcode' => $barcode,
            ':id' => $id,
        ]);
        if ($stmtCheck->fetch()) {
            throw new RuntimeException('Barcode sudah digunakan oleh produk lain.');
        }
    }

    $stmtUpdate = $pdo->prepare("
        UPDATE products
        SET barcode = :barcode,
            name = :name,
            category_id = :category_id,
            unit = :unit,
            stock_minimum = :stock_minimum,
            description = :description,
            image_path = :image_path,
            points_reward = :points_reward,
            is_active = :is_active,
            updated_at = NOW()
        WHERE id = :id
        LIMIT 1
    ");

    $stmtUpdate->execute([
        ':barcode' => $barcode,
        ':name' => $name,
        ':category_id' => $categoryId,
        ':unit' => $unit,
        ':stock_minimum' => $stockMinimum,
        ':description' => $description,
        ':image_path' => $imagePath,
        ':points_reward' => $pointsReward,
        ':is_active' => $isActive,
        ':id' => $id,
    ]);

    if ($newImagePath && $previousImage && $previousImage !== $newImagePath) {
        remove_product_image($previousImage);
    }

    if ($removeImageRequested && $previousImage && !$newImagePath) {
        remove_product_image($previousImage);
    }
} catch (RuntimeException $runtime) {
    if ($newImagePath) {
        remove_product_image($newImagePath);
    }
    redirect_with_message('/index.php?page=barang_list', $runtime->getMessage(), 'error');
} catch (Throwable $e) {
    if ($newImagePath) {
        remove_product_image($newImagePath);
    }
    redirect_with_message('/index.php?page=barang_list', 'Terjadi kesalahan saat menyimpan barang.', 'error');
}

redirect_with_message('/index.php?page=barang_list', 'Barang berhasil diperbarui.');
