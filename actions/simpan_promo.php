<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';
require_once __DIR__ . '/../includes/activity_logger.php';

require_role(ROLE_KASIR);
guard_post();
verify_csrf_token($_POST['csrf_token'] ?? '');

$pdo = get_db_connection();
$user = current_user();

$mode = $_POST['mode'] ?? 'save';

if ($mode === 'toggle') {
    $id = (int) ($_POST['id'] ?? 0);
    if (!$id) {
        redirect_with_message('/index.php?page=promo', 'Promo tidak ditemukan.', 'error');
    }

    $stmt = $pdo->prepare("SELECT is_active FROM promotions WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $currentStatus = $stmt->fetchColumn();
    if ($currentStatus === false) {
        redirect_with_message('/index.php?page=promo', 'Promo tidak ditemukan.', 'error');
    }

    $pdo->prepare("UPDATE promotions SET is_active = NOT is_active, updated_at = NOW() WHERE id = :id")->execute([':id' => $id]);
    $newStatus = ((int) $currentStatus) ? 0 : 1;

    inventory_log('promo_status_toggled', [
        'promo_id' => $id,
        'previous_status' => (int) $currentStatus,
        'new_status' => $newStatus,
        'user_id' => $user['id'] ?? null,
    ]);

    redirect_with_message('/index.php?page=promo', 'Status promo diperbarui.');
}

$productId = (int) ($_POST['product_id'] ?? 0);
$categoryId = (int) ($_POST['category_id'] ?? 0);
$promoName = trim($_POST['promo_name'] ?? '');
$promoType = $_POST['promo_type'] ?? 'item';
$discountValue = (float) ($_POST['discount_value'] ?? 0);
$startDate = $_POST['start_date'] ?? '';
$endDate = $_POST['end_date'] ?? '';
$minQty = (int) ($_POST['min_qty'] ?? 1);

if (($promoType === 'item' && !$productId) || ($promoType === 'category' && !$categoryId) || $promoName === '' || $discountValue <= 0) {
    redirect_with_message('/index.php?page=promo', 'Lengkapi data promo dengan benar.', 'error');
}

if ($promoType === 'category') {
    $stmt = $pdo->prepare("SELECT id FROM products WHERE category_id = :category_id");
    $stmt->execute([':category_id' => $categoryId]);
    $productIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($productIds)) {
        redirect_with_message('/index.php?page=promo', 'Tidak ada produk di kategori ini.', 'error');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO promotions (product_id, promo_name, promo_type, discount_value, start_date, end_date, min_qty, is_active, created_at, updated_at)
            VALUES (:product_id, :promo_name, :promo_type, :discount_value, :start_date, :end_date, :min_qty, 1, NOW(), NOW())
        ");

        $createdIds = [];
        foreach ($productIds as $pid) {
            $stmt->execute([
                ':product_id' => $pid,
                ':promo_name' => $promoName,
                ':promo_type' => 'item', // Simpan sebagai promo per item
                ':discount_value' => $discountValue,
                ':start_date' => $startDate,
                ':end_date' => $endDate,
                ':min_qty' => $minQty,
            ]);
            $createdIds[] = (int) $pdo->lastInsertId();
        }
        $pdo->commit();

        inventory_log('promo_created', [
            'scope' => 'category',
            'category_id' => $categoryId,
            'product_ids' => array_map('intval', $productIds),
            'promo_name' => $promoName,
            'promo_type' => $promoType,
            'discount_value' => $discountValue,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'min_qty' => $minQty,
            'created_ids' => $createdIds,
            'user_id' => $user['id'] ?? null,
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        redirect_with_message('/index.php?page=promo', 'Gagal menyimpan promo: ' . $e->getMessage(), 'error');
    }

} else {
    $stmt = $pdo->prepare("
        INSERT INTO promotions (product_id, promo_name, promo_type, discount_value, start_date, end_date, min_qty, is_active, created_at, updated_at)
        VALUES (:product_id, :promo_name, :promo_type, :discount_value, :start_date, :end_date, :min_qty, 1, NOW(), NOW())
    ");
    $stmt->execute([
        ':product_id' => $promoType === 'item' ? $productId : null,
        ':promo_name' => $promoName,
        ':promo_type' => $promoType,
        ':discount_value' => $discountValue,
        ':start_date' => $startDate,
        ':end_date' => $endDate,
        ':min_qty' => $minQty,
    ]);

    $promoId = (int) $pdo->lastInsertId();
    inventory_log('promo_created', [
        'scope' => $promoType === 'item' ? 'product' : 'global',
        'product_id' => $promoType === 'item' ? $productId : null,
        'promo_name' => $promoName,
        'promo_type' => $promoType,
        'discount_value' => $discountValue,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'min_qty' => $minQty,
        'promo_id' => $promoId,
        'user_id' => $user['id'] ?? null,
    ]);
}

redirect_with_message('/index.php?page=promo', 'Promo ditambahkan.');
