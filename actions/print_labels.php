<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';
require_once __DIR__ . '/../includes/activity_logger.php';

require_role(ROLE_KASIR);
guard_post();
verify_csrf_token($_POST['csrf_token'] ?? '');

$batchIdsRaw = $_POST['batch_ids'] ?? [];
$quantitiesRaw = $_POST['quantities'] ?? [];

if (!is_array($batchIdsRaw) || count($batchIdsRaw) === 0) {
    redirect_with_message('/index.php?page=label_harga', 'Pilih minimal satu batch untuk dicetak.', 'error');
}

$batchIds = array_map('intval', $batchIdsRaw);
$batchIds = array_filter($batchIds, fn($id) => $id > 0);

if (!$batchIds) {
    redirect_with_message('/index.php?page=label_harga', 'Data batch tidak valid.', 'error');
}

$placeholders = implode(',', array_fill(0, count($batchIds), '?'));
$pdo = get_db_connection();
$user = current_user();
$stmt = $pdo->prepare("
    SELECT b.id, p.name AS product_name, b.sell_price, b.batch_code
    FROM product_batches b
    INNER JOIN products p ON p.id = b.product_id
    WHERE b.id IN ($placeholders)
    ORDER BY p.name ASC
");
$stmt->execute($batchIds);
$rows = $stmt->fetchAll();

if (!$rows) {
    redirect_with_message('/index.php?page=label_harga', 'Batch tidak ditemukan.', 'error');
}

$store = get_store_settings();
$printedAt = date('Y-m-d H:i:s');

$labels = [];
$batchQuantities = [];
foreach ($rows as $row) {
    $quantity = isset($quantitiesRaw[$row['id']]) ? (int) $quantitiesRaw[$row['id']] : 1;
    if ($quantity < 1) {
        $quantity = 1;
    }
    if ($quantity > 40) {
        $quantity = 40;
    }
    $batchQuantities[$row['id']] = ($batchQuantities[$row['id']] ?? 0) + $quantity;
    for ($i = 0; $i < $quantity; $i++) {
        $labels[] = $row;
    }
}

if (!$labels) {
    redirect_with_message('/index.php?page=label_harga', 'Jumlah label tidak valid.', 'error');
}

$sheets = array_chunk($labels, 40);

inventory_log('label_printed', [
    'mode' => 'batch_selection',
    'batch_quantities' => $batchQuantities,
    'total_labels' => count($labels),
    'printed_at' => $printedAt,
    'user_id' => $user['id'] ?? null,
]);

?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Cetak Label Harga</title>
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
