<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';

require_role(ROLE_KASIR);
guard_post();
verify_csrf_token($_POST['csrf_token'] ?? '');

if (!isset($_FILES['barang_csv']) || $_FILES['barang_csv']['error'] !== UPLOAD_ERR_OK) {
    redirect_with_message('/index.php?page=barang', 'Gagal mengunggah file CSV.', 'error');
}

$tmpPath = $_FILES['barang_csv']['tmp_name'];
if (!is_uploaded_file($tmpPath)) {
    redirect_with_message('/index.php?page=barang', 'File upload tidak valid.', 'error');
}

$delimiter = $_POST['delimiter'] ?? ',';
if (!in_array($delimiter, [',', ';'], true)) {
    $delimiter = ',';
}

$fp = fopen($tmpPath, 'r');
if (!$fp) {
    redirect_with_message('/index.php?page=barang', 'File CSV tidak bisa dibaca.', 'error');
}

$header = fgetcsv($fp, 0, $delimiter);
if ($header === false) {
    fclose($fp);
    redirect_with_message('/index.php?page=barang', 'File CSV kosong.', 'error');
}

$normalizedHeader = array_map(static function ($value) {
    return strtolower(trim((string) $value));
}, $header);

$requiredColumns = ['barcode', 'nama'];
foreach ($requiredColumns as $column) {
    if (!in_array($column, $normalizedHeader, true)) {
        fclose($fp);
        redirect_with_message('/index.php?page=barang', 'Kolom wajib (barcode, nama) tidak ditemukan.', 'error');
    }
}

$columnIndex = array_flip($normalizedHeader);

$pdo = get_db_connection();
$pdo->beginTransaction();

try {
    $categoryCache = [];
    $categoryStmt = $pdo->query('SELECT id, name FROM categories');
    foreach ($categoryStmt->fetchAll() as $category) {
        $categoryCache[strtolower($category['name'])] = (int) $category['id'];
    }

    $insertCategoryStmt = $pdo->prepare("
        INSERT INTO categories (name, created_at, updated_at)
        VALUES (:name, NOW(), NOW())
    ");

    $insertProductStmt = $pdo->prepare("
        INSERT INTO products (barcode, name, category_id, unit, stock_minimum, description, points_reward, created_at, updated_at)
        VALUES (:barcode, :name, :category_id, :unit, :stock_minimum, :description, :points_reward, NOW(), NOW())
    ");

    $updateProductStmt = $pdo->prepare("
        UPDATE products
        SET name = :name,
            category_id = :category_id,
            unit = :unit,
            stock_minimum = :stock_minimum,
            description = :description,
            points_reward = :points_reward,
            updated_at = NOW()
        WHERE barcode = :barcode
    ");

    $findProductStmt = $pdo->prepare('SELECT id FROM products WHERE barcode = :barcode LIMIT 1');

    $inserted = 0;
    $updated = 0;
    $skipped = 0;
    $rowNumber = 1;
    $warnings = [];

    while (($row = fgetcsv($fp, 0, $delimiter)) !== false) {
        $rowNumber++;

        if (count(array_filter($row, static fn($value) => trim((string) $value) !== '')) === 0) {
            continue;
        }

        $barcode = trim((string) ($row[$columnIndex['barcode']] ?? ''));
        $name = trim((string) ($row[$columnIndex['nama']] ?? ''));

        if ($barcode === '' || $name === '') {
            $skipped++;
            $warnings[] = "Baris {$rowNumber}: barcode atau nama kosong, dilewati.";
            continue;
        }

        $categoryName = '';
        if (isset($columnIndex['kategori'])) {
            $categoryName = trim((string) ($row[$columnIndex['kategori']] ?? ''));
        }

        $categoryId = null;
        if ($categoryName !== '') {
            $normalizedCategory = strtolower($categoryName);
            if (!isset($categoryCache[$normalizedCategory])) {
                $insertCategoryStmt->execute([':name' => $categoryName]);
                $categoryId = (int) $pdo->lastInsertId();
                $categoryCache[$normalizedCategory] = $categoryId;
            } else {
                $categoryId = $categoryCache[$normalizedCategory];
            }
        }

        $unit = isset($columnIndex['satuan']) ? trim((string) ($row[$columnIndex['satuan']] ?? '')) : '';
        $stockMinimumRaw = isset($columnIndex['stok_minimum']) ? trim((string) ($row[$columnIndex['stok_minimum']] ?? '0')) : '0';
        $pointsRewardRaw = isset($columnIndex['poin']) ? trim((string) ($row[$columnIndex['poin']] ?? '0')) : '0';
        $description = isset($columnIndex['deskripsi']) ? trim((string) ($row[$columnIndex['deskripsi']] ?? '')) : '';

        $stockMinimum = (int) filter_var($stockMinimumRaw, FILTER_SANITIZE_NUMBER_INT);
        $pointsReward = (int) filter_var($pointsRewardRaw, FILTER_SANITIZE_NUMBER_INT);

        $data = [
            ':barcode' => $barcode,
            ':name' => $name,
            ':category_id' => $categoryId,
            ':unit' => $unit,
            ':stock_minimum' => max(0, $stockMinimum),
            ':description' => $description,
            ':points_reward' => max(0, $pointsReward),
        ];

        $findProductStmt->execute([':barcode' => $barcode]);
        if ($findProductStmt->fetch()) {
            $updateProductStmt->execute($data);
            $updated++;
        } else {
            $insertProductStmt->execute($data);
            $inserted++;
        }
    }

    fclose($fp);
    $pdo->commit();

    $message = "Import selesai: {$inserted} ditambahkan, {$updated} diperbarui.";
    if ($skipped > 0) {
        $message .= " {$skipped} baris dilewati.";
    }

    if ($warnings) {
        $_SESSION['import_barang_warnings'] = $warnings;
    }

    redirect_with_message('/index.php?page=barang', $message);
} catch (Throwable $e) {
    fclose($fp);
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $errorId = app_generate_error_id();
    app_log_error($e, $errorId);
    redirect_with_message('/index.php?page=barang', 'Import gagal. Kode: ' . $errorId, 'error');
}
