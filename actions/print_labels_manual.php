<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';
require_once __DIR__ . '/../includes/activity_logger.php';

require_role(ROLE_KASIR);
guard_post();
verify_csrf_token($_POST['csrf_token'] ?? '');

$categoryId = (int) ($_POST['category_id'] ?? 0);
$quantityPerProduct = (int) ($_POST['quantity_per_product'] ?? 1);

if ($categoryId <= 0) {
    redirect_with_message('/index.php?page=label_harga', 'Pilih kategori yang ingin dicetak.', 'error');
}

if ($quantityPerProduct < 1) {
    $quantityPerProduct = 1;
} elseif ($quantityPerProduct > 40) {
    $quantityPerProduct = 40;
}

$pdo = get_db_connection();
$user = current_user();

$latestBatchSql = "
    SELECT b_latest.id, b_latest.product_id, b_latest.sell_price, b_latest.batch_code
    FROM product_batches b_latest
    INNER JOIN (
        SELECT product_id, MAX(id) AS max_id
        FROM product_batches
        GROUP BY product_id
    ) latest ON latest.product_id = b_latest.product_id AND latest.max_id = b_latest.id
";

$stmt = $pdo->prepare("
    SELECT p.id AS product_id, p.name AS product_name, p.barcode, b.sell_price, b.batch_code
    FROM products p
    INNER JOIN ($latestBatchSql) b ON b.product_id = p.id
    WHERE p.category_id = :category_id
    ORDER BY p.name ASC
");
$stmt->execute([':category_id' => $categoryId]);
$rows = $stmt->fetchAll();

if (!$rows) {
    redirect_with_message('/index.php?page=label_harga', 'Tidak ditemukan produk dengan batch terbaru pada kategori tersebut.', 'error');
}

$store = get_store_settings();
$printedAt = date('Y-m-d H:i:s');

$labels = [];
foreach ($rows as $index => $row) {
    $rows[$index]['product_id'] = (int) $row['product_id'];
}
foreach ($rows as $row) {
    for ($i = 0; $i < $quantityPerProduct; $i++) {
        $labels[] = $row;
    }
}

if (!$labels) {
    redirect_with_message('/index.php?page=label_harga', 'Tidak ada label yang bisa dicetak.', 'error');
}

$sheets = array_chunk($labels, 40);

inventory_log('label_printed', [
    'mode' => 'category_latest',
    'category_id' => $categoryId,
    'product_ids' => array_values(array_unique(array_column($rows, 'product_id'))),
    'quantity_per_product' => $quantityPerProduct,
    'total_labels' => count($labels),
    'printed_at' => $printedAt,
    'user_id' => $user['id'] ?? null,
]);

?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Cetak Label Harga Manual</title>
    <style>
        :root {
            color-scheme: light;
        }
        body {
            margin: 12px;
            font-family: "Segoe UI", Arial, sans-serif;
            font-size: 12px;
        }
        .store-name {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .toolbar button,
        .toolbar a {
            padding: 6px 12px;
            border: 1px solid #111;
            background: #fff;
            color: #111;
            text-decoration: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .toolbar button:hover,
        .toolbar a:hover {
            background: #f3f4f6;
        }
        .sheet {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            page-break-after: always;
        }
        .sheet:last-of-type {
            page-break-after: auto;
        }
        .label-card {
            border: 1px dashed #1f2937;
            border-radius: 6px;
            padding: 8px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 70px;
        }
        .label-card header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 4px;
        }
        .label-card h3 {
            margin: 0 0 4px;
            font-size: 13px;
            line-height: 1.2;
            text-align: center;
        }
        .label-card .price {
            font-size: 18px;
            font-weight: 700;
            margin: 4px 0;
            text-align: center;
        }
        .label-card .meta {
            font-size: 10px;
            color: #4b5563;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .sheet-number {
            font-size: 10px;
            color: #6b7280;
        }
        @media print {
            body {
                margin: 0;
            }
            .toolbar {
                display: none !important;
            }
            .sheet {
                page-break-after: always;
            }
            .sheet:last-of-type {
                page-break-after: auto;
            }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <div>
            <strong><?= sanitize($store['store_name']) ?></strong><br>
            Dicetak: <?= format_date($printedAt, true) ?><br>
            Total label: <?= count($labels) ?>
        </div>
        <div>
            <a href="<?= BASE_URL ?>/index.php?page=label_harga">Kembali ke Label</a>
            <button type="button" onclick="window.print()">Cetak</button>
        </div>
    </div>

    <?php foreach ($sheets as $index => $sheet): ?>
        <div class="sheet" data-sheet="<?= $index + 1 ?>">
            <?php foreach ($sheet as $label): ?>
                <div class="label-card">
                    <header>
                        <span class="store-name"><?= sanitize($store['store_name']) ?></span>
                        <span class="sheet-number">#<?= $index + 1 ?></span>
                    </header>
                    <h3><?= sanitize($label['product_name']) ?></h3>
                    <div class="price"><?= format_rupiah($label['sell_price']) ?></div>
                    <div class="meta">
                        <span>Batch: <?= sanitize($label['batch_code']) ?></span>
                        <span><?= date('d/m/Y', strtotime($printedAt)) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (count($sheet) < 40): ?>
                <?php for ($i = count($sheet); $i < 40; $i++): ?>
                    <div class="label-card"></div>
                <?php endfor; ?>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</body>
</html>
