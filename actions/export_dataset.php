<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';

require_role(ROLE_MANAJER);
guard_post();
verify_csrf_token($_POST['csrf_token'] ?? '');

$start = $_POST['start'] ?? date('Y-m-01');
$end = $_POST['end'] ?? date('Y-m-d');

$pdo = get_db_connection();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$jakartaTz = new DateTimeZone('Asia/Jakarta');
$defaultTz = new DateTimeZone(date_default_timezone_get());

$normalizeDate = static function (?string $value) use ($jakartaTz, $defaultTz): string {
    if (!$value) {
        return '';
    }

    try {
        $dt = new DateTimeImmutable($value, $defaultTz);
    } catch (Exception $e) {
        return '';
    }

    return $dt->setTimezone($jakartaTz)->format('Y-m-d H:i:s');
};

$normalizeAmount = static function ($value): string {
    if ($value === null || $value === '') {
        return '';
    }

    $number = (float) $value;
    $normalized = number_format($number, 2, '.', '');
    return rtrim(rtrim($normalized, '0'), '.');
};

$normalizeString = static function ($value): string {
    if ($value === null) {
        return '';
    }

    return (string) $value;
};

$fetchSalesStmt = $pdo->prepare("
    SELECT
        s.id AS sale_id,
        s.created_at,
        s.member_id,
        m.member_code,
        s.cashier_id,
        s.subtotal,
        s.discount_amount,
        s.grand_total,
        s.payment_method,
        s.cash_paid,
        s.change_returned,
        s.invoice_code
    FROM sales s
    LEFT JOIN members m ON m.id = s.member_id
    WHERE DATE(s.created_at) BETWEEN :start AND :end
    ORDER BY s.created_at ASC, s.id ASC
");
$fetchSalesStmt->execute([
    ':start' => $start,
    ':end' => $end,
]);
$salesRows = $fetchSalesStmt->fetchAll();

$invoiceToSaleId = [];
foreach ($salesRows as $row) {
    if (!empty($row['invoice_code'])) {
        $invoiceToSaleId[$row['invoice_code']] = (int) $row['sale_id'];
    }
}

$createCsv = static function (array $header, iterable $rows): string {
    $stream = fopen('php://temp', 'w+');
    fputcsv($stream, $header);
    foreach ($rows as $row) {
        fputcsv($stream, $row);
    }
    rewind($stream);
    $csv = stream_get_contents($stream);
    fclose($stream);

    return $csv;
};

$penjualanCsvRows = [];
foreach ($salesRows as $sale) {
    $penjualanCsvRows[] = [
        $sale['sale_id'],
        $normalizeDate($sale['created_at']),
        $normalizeString($sale['member_code'] ?? $sale['member_id']),
        $normalizeString($sale['cashier_id']),
        $normalizeAmount($sale['subtotal']),
        $normalizeAmount($sale['discount_amount']),
        '0', // pajak tidak tersedia di skema saat ini
        $normalizeAmount($sale['grand_total']),
        $normalizeString($sale['payment_method']),
        $normalizeAmount($sale['cash_paid']),
        $normalizeAmount($sale['change_returned']),
        'toko', // channel default
    ];
}
$penjualanCsv = $createCsv([
    'sale_id',
    'tanggal_waktu',
    'customer_id',
    'cashier_id',
    'subtotal',
    'diskon_transaksi',
    'pajak',
    'grand_total',
    'metode_bayar',
    'dibayar',
    'kembali',
    'channel',
], $penjualanCsvRows);

$detailStmt = $pdo->prepare("
    SELECT
        si.sale_id,
        s.created_at,
        p.id AS product_id,
        p.barcode,
        p.name AS product_name,
        c.name AS category_name,
        si.quantity,
        si.price,
        si.discount,
        si.total,
        pb.purchase_price,
        pb.supplier_id
    FROM sale_items si
    INNER JOIN sales s ON s.id = si.sale_id
    LEFT JOIN products p ON p.id = si.product_id
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN product_batches pb ON pb.id = si.batch_id
    WHERE DATE(s.created_at) BETWEEN :start AND :end
    ORDER BY s.created_at ASC, si.sale_id ASC, p.name ASC
");
$detailStmt->execute([
    ':start' => $start,
    ':end' => $end,
]);
$detailRows = $detailStmt->fetchAll();

$penjualanDetailCsvRows = [];
foreach ($detailRows as $detail) {
    $sku = $normalizeString($detail['barcode']);
    if ($sku === '') {
        $sku = 'SKU-' . $detail['product_id'];
    }

    $cogsPerUnit = $detail['purchase_price'] !== null && (float) $detail['quantity'] > 0
        ? (float) $detail['purchase_price']
        : 0.0;

    $penjualanDetailCsvRows[] = [
        $detail['sale_id'],
        $sku,
        $normalizeString($detail['product_name']),
        $normalizeAmount($detail['quantity']),
        $normalizeAmount($detail['price']),
        $normalizeAmount($detail['discount']),
        $normalizeAmount($cogsPerUnit),
        $normalizeString($detail['category_name']),
        $normalizeString($detail['supplier_id']),
    ];
}
$penjualanDetailCsv = $createCsv([
    'sale_id',
    'sku',
    'nama_item',
    'qty',
    'harga_satuan',
    'diskon_item',
    'cogs_per_unit',
    'kategori',
    'supplier_id',
], $penjualanDetailCsvRows);

$returCsv = $createCsv([
    'retur_id',
    'sale_id',
    'sku',
    'qty_retur',
    'alasan',
    'refund_amount',
    'tanggal_waktu',
    'cashier_id',
], []);

$expensesStmt = $pdo->prepare("
    SELECT
        e.id,
        e.expense_date,
        e.category,
        e.description,
        e.amount,
        e.created_by,
        e.created_at
    FROM expenses e
    WHERE e.expense_date BETWEEN :start AND :end
    ORDER BY e.expense_date ASC, e.id ASC
");
$expensesStmt->execute([
    ':start' => $start,
    ':end' => $end,
]);
$expenseRows = $expensesStmt->fetchAll();

$pengeluaranCsvRows = [];
foreach ($expenseRows as $expense) {
    $pengeluaranCsvRows[] = [
        $expense['id'],
        $normalizeDate($expense['created_at']),
        $normalizeString($expense['category']),
        $normalizeString($expense['description']),
        $normalizeAmount($expense['amount']),
        'cash', // metode bayar belum tercatat, default cash
    ];
}
$pengeluaranCsv = $createCsv([
    'expense_id',
    'tanggal_waktu',
    'kategori',
    'deskripsi',
    'jumlah',
    'metode_bayar',
], $pengeluaranCsvRows);

$purchaseHeaderStmt = $pdo->prepare("
    SELECT
        pb.id AS purchase_id,
        pb.batch_code,
        pb.supplier_id,
        pb.received_at,
        pb.purchase_price,
        pb.stock_in,
        pb.created_at,
        pb.updated_at
    FROM product_batches pb
    WHERE DATE(pb.received_at) BETWEEN :start AND :end
       OR DATE(pb.created_at) BETWEEN :start AND :end
    ORDER BY pb.received_at ASC, pb.id ASC
");
$purchaseHeaderStmt->execute([
    ':start' => $start,
    ':end' => $end,
]);
$purchaseBatches = $purchaseHeaderStmt->fetchAll();

$pembelianHeaderRows = [];
$pembelianDetailRows = [];
foreach ($purchaseBatches as $batch) {
    $purchaseId = $batch['purchase_id'];
    $total = (float) $batch['purchase_price'] * (float) $batch['stock_in'];

    $pembelianHeaderRows[$purchaseId] = [
        $purchaseId,
        $normalizeString($batch['supplier_id']),
        $normalizeDate($batch['received_at'] ?: $batch['created_at']),
        $normalizeAmount($total),
        '0',
        '0',
    ];
}

if (!empty($purchaseBatches)) {
    $purchaseDetailStmt = $pdo->prepare("
        SELECT
            pb.id AS purchase_id,
            pb.batch_code,
            pb.product_id,
            p.name AS product_name,
            p.barcode,
            pb.stock_in,
            pb.purchase_price,
            pb.expiry_date
        FROM product_batches pb
        LEFT JOIN products p ON p.id = pb.product_id
        WHERE pb.id IN (" . implode(',', array_map('intval', array_column($purchaseBatches, 'purchase_id'))) . ")
        ORDER BY pb.received_at ASC, pb.id ASC
    ");
    $purchaseDetailStmt->execute();
    $purchaseDetailRows = $purchaseDetailStmt->fetchAll();

    foreach ($purchaseDetailRows as $detail) {
        $sku = $normalizeString($detail['barcode']);
        if ($sku === '') {
            $sku = 'SKU-' . $detail['product_id'];
        }

        $pembelianDetailRows[] = [
            $detail['purchase_id'],
            $sku,
            $normalizeAmount($detail['stock_in']),
            $normalizeAmount($detail['purchase_price']),
            $normalizeString($detail['expiry_date']),
        ];
    }
}

$pembelianCsv = $createCsv([
    'purchase_id',
    'supplier_id',
    'tanggal_waktu',
    'total',
    'diskon',
    'pajak',
], array_values($pembelianHeaderRows));

$pembelianDetailCsv = $createCsv([
    'purchase_id',
    'sku',
    'qty',
    'harga_beli_per_unit',
    'expired_date',
], $pembelianDetailRows);

$produkStmt = $pdo->query("
    SELECT
        p.id,
        p.barcode,
        p.name,
        c.name AS category_name,
        p.unit,
        p.points_reward,
        (
            SELECT pb.sell_price
            FROM product_batches pb
            WHERE pb.product_id = p.id
            ORDER BY pb.received_at DESC, pb.id DESC
            LIMIT 1
        ) AS default_sell_price,
        p.created_at,
        p.updated_at
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    ORDER BY p.name ASC
");
$produkRows = $produkStmt->fetchAll();

$produkCsvRows = [];
foreach ($produkRows as $product) {
    $sku = $normalizeString($product['barcode']);
    if ($sku === '') {
        $sku = 'SKU-' . $product['id'];
    }

    $produkCsvRows[] = [
        $sku,
        $normalizeString($product['name']),
        $normalizeString($product['category_name']),
        $normalizeString($product['unit']),
        $normalizeAmount($product['default_sell_price']),
        '', // harga_minimum belum tersedia
        $normalizeString($product['barcode']),
    ];
}
$produkCsv = $createCsv([
    'sku',
    'nama_item',
    'kategori',
    'satuan',
    'harga_jual_default',
    'harga_minimum',
    'barcode',
], $produkCsvRows);

$stockStmt = $pdo->prepare("
    SELECT
        sa.id,
        sa.product_id,
        sa.batch_id,
        sa.adjustment_type,
        sa.quantity,
        sa.reason,
        sa.created_by,
        sa.created_at,
        p.name AS product_name,
        p.barcode
    FROM stock_adjustments sa
    LEFT JOIN products p ON p.id = sa.product_id
    WHERE DATE(sa.created_at) BETWEEN :start AND :end
    ORDER BY sa.created_at ASC, sa.id ASC
");
$stockStmt->execute([
    ':start' => $start,
    ':end' => $end,
]);
$stockRows = $stockStmt->fetchAll();

$kartuStokRows = [];
foreach ($stockRows as $stock) {
    $sku = $normalizeString($stock['barcode']);
    if ($sku === '') {
        $sku = 'SKU-' . $stock['product_id'];
    }

    $refId = '';
    if (!empty($stock['reason']) && preg_match('/(INV-[A-Z0-9-]+)/', $stock['reason'], $matches)) {
        $invoice = $matches[1];
        if (isset($invoiceToSaleId[$invoice])) {
            $refId = (string) $invoiceToSaleId[$invoice];
        }
    }

    $kartuStokRows[] = [
        $sku,
        $normalizeDate($stock['created_at']),
        $normalizeString($stock['adjustment_type']),
        $normalizeAmount($stock['quantity']),
        $normalizeString($stock['reason']),
        $refId,
        $normalizeString($stock['created_by']),
    ];
}
$kartuStokCsv = $createCsv([
    'sku',
    'tanggal_waktu',
    'tipe',
    'qty',
    'keterangan',
    'ref_id',
    'user_id',
], $kartuStokRows);

$promoAppliedCsv = $createCsv([
    'sale_id',
    'promo_code',
    'nilai',
    'level',
], []);

$pointsStmt = $pdo->prepare("
    SELECT
        mp.id,
        mp.sale_id,
        mp.member_id,
        m.member_code,
        mp.points_change,
        mp.created_at
    FROM member_points mp
    LEFT JOIN members m ON m.id = mp.member_id
    WHERE mp.created_at BETWEEN :start_dt AND :end_dt
    ORDER BY mp.created_at ASC, mp.id ASC
");
$pointsStmt->execute([
    ':start_dt' => $start . ' 00:00:00',
    ':end_dt' => $end . ' 23:59:59',
]);
$pointsRows = $pointsStmt->fetchAll();

$pointsCsvRows = [];
$memberRunningBalance = [];
foreach ($pointsRows as $pointRow) {
    $memberKey = (string) ($pointRow['member_code'] ?? $pointRow['member_id']);
    if (!array_key_exists($memberKey, $memberRunningBalance)) {
        $memberRunningBalance[$memberKey] = 0;
    }

    $memberRunningBalance[$memberKey] += (int) $pointRow['points_change'];

    $pointsCsvRows[] = [
        $normalizeString($pointRow['sale_id']),
        $memberKey,
        $pointRow['points_change'] > 0 ? $pointRow['points_change'] : 0,
        $pointRow['points_change'] < 0 ? abs($pointRow['points_change']) : 0,
        $memberRunningBalance[$memberKey],
        $normalizeDate($pointRow['created_at']),
    ];
}
$poinCsv = $createCsv([
    'sale_id',
    'customer_id',
    'poin_dapat',
    'poin_pakai',
    'saldo_poin',
    'tanggal_waktu',
], $pointsCsvRows);

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('Ekstensi ZipArchive tidak tersedia di server.');
}
$zip = new ZipArchive();
$tempZipPath = tempnam(sys_get_temp_dir(), 'dataset_');
if ($zip->open($tempZipPath, ZipArchive::OVERWRITE) !== true) {
    unlink($tempZipPath);
    http_response_code(500);
    exit('Gagal menyiapkan arsip ekspor.');
}

$zip->addFromString('penjualan.csv', $penjualanCsv);
$zip->addFromString('penjualan_detail.csv', $penjualanDetailCsv);
$zip->addFromString('retur_penjualan.csv', $returCsv);
$zip->addFromString('pengeluaran.csv', $pengeluaranCsv);
$zip->addFromString('pembelian.csv', $pembelianCsv);
$zip->addFromString('pembelian_detail.csv', $pembelianDetailCsv);
$zip->addFromString('produk.csv', $produkCsv);
$zip->addFromString('kartu_stok.csv', $kartuStokCsv);
$zip->addFromString('promo_applied.csv', $promoAppliedCsv);
$zip->addFromString('poin.csv', $poinCsv);

$zip->close();

$filename = sprintf('dataset_lengkap_%s_sd_%s.zip', $start, $end);
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tempZipPath));

$stream = fopen($tempZipPath, 'rb');
if ($stream !== false) {
    fpassthru($stream);
    fclose($stream);
}

unlink($tempZipPath);
exit;
