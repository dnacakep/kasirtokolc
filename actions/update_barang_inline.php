<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';

require_role(ROLE_KASIR);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Metode tidak diizinkan.'
    ]);
    exit;
}

$rawInput = file_get_contents('php://input');
$decoded = json_decode($rawInput, true);

if (!is_array($decoded)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Payload tidak valid.'
    ]);
    exit;
}

$csrfToken = $decoded['csrf_token'] ?? '';

try {
    verify_csrf_token($csrfToken);
} catch (Throwable $e) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Sesi kedaluwarsa. Muat ulang halaman.'
    ]);
    exit;
}

$productPayload = $decoded['product'] ?? [];

$id = (int) ($productPayload['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Barang tidak ditemukan.'
    ]);
    exit;
}

$name = isset($productPayload['name']) ? trim((string) $productPayload['name']) : '';
if ($name === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Nama barang wajib diisi.'
    ]);
    exit;
}

$rawBarcode = isset($productPayload['barcode']) ? trim((string) $productPayload['barcode']) : '';
$barcode = $rawBarcode === '' ? null : $rawBarcode;

if ($barcode !== null && !ctype_digit($barcode)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Barcode hanya boleh berisi angka.'
    ]);
    exit;
}

$categoryId = $productPayload['category_id'] ?? null;
$categoryId = $categoryId === null ? null : (int) $categoryId;
$unit = isset($productPayload['unit']) ? trim((string) $productPayload['unit']) : '';
$stockMinimum = isset($productPayload['stock_minimum']) ? max(0, (int) $productPayload['stock_minimum']) : 0;
$pointsReward = isset($productPayload['points_reward']) ? max(0, (int) $productPayload['points_reward']) : 0;
$isActive = isset($productPayload['is_active']) ? (int) $productPayload['is_active'] : 1;
$isActive = $isActive === 0 ? 0 : 1;

$pdo = get_db_connection();

try {
    if ($barcode !== null) {
        $stmtCheck = $pdo->prepare('SELECT id, name FROM products WHERE barcode = :barcode AND id != :id LIMIT 1');
        $stmtCheck->execute([
            ':barcode' => $barcode,
            ':id' => $id,
        ]);
        $existing = $stmtCheck->fetch();
        if ($existing) {
            throw new RuntimeException('Barcode sudah digunakan oleh "' . ($existing['name'] ?? 'produk lain') . '".');
        }
    }

    $stmt = $pdo->prepare("
        UPDATE products
        SET barcode = :barcode,
            name = :name,
            category_id = :category_id,
            unit = :unit,
            stock_minimum = :stock_minimum,
            points_reward = :points_reward,
            is_active = :is_active,
            updated_at = NOW()
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute([
        ':barcode' => $barcode,
        ':name' => $name,
        ':category_id' => $categoryId,
        ':unit' => $unit,
        ':stock_minimum' => $stockMinimum,
        ':points_reward' => $pointsReward,
        ':is_active' => $isActive,
        ':id' => $id,
    ]);

    $categoryName = null;
    if ($categoryId !== null) {
        $stmtCategory = $pdo->prepare('SELECT name FROM categories WHERE id = :id LIMIT 1');
        $stmtCategory->execute([':id' => $categoryId]);
        $categoryName = $stmtCategory->fetchColumn();
    }

    echo json_encode([
        'success' => true,
        'message' => 'Barang berhasil diperbarui.',
        'product' => [
            'id' => $id,
            'barcode' => $barcode,
            'name' => $name,
            'category_id' => $categoryId,
            'category_name' => $categoryName,
            'unit' => $unit,
            'stock_minimum' => $stockMinimum,
            'points_reward' => $pointsReward,
            'is_active' => $isActive,
        ],
    ]);
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan pada server.',
    ]);
}
