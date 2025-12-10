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

$normalizeDateTime = static function (?string $value) use ($jakartaTz, $defaultTz): string {
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

$summaryStmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(s.grand_total), 0) AS total_penjualan,
        COALESCE(SUM(s.subtotal), 0) AS total_subtotal,
        COALESCE(SUM(s.discount_amount), 0) AS total_diskon,
        COALESCE(SUM(s.cash_paid), 0) AS total_dibayar,
        COALESCE(SUM(s.change_returned), 0) AS total_kembali,
        COUNT(*) AS jumlah_transaksi
    FROM sales s
    WHERE DATE(s.created_at) BETWEEN :start AND :end
");
$summaryStmt->execute([':start' => $start, ':end' => $end]);
$summaryData = $summaryStmt->fetch() ?: [
    'total_penjualan' => 0,
    'total_subtotal' => 0,
    'total_diskon' => 0,
    'total_dibayar' => 0,
    'total_kembali' => 0,
    'jumlah_transaksi' => 0,
];

$modalStmt = $pdo->prepare("
    SELECT COALESCE(SUM(CASE WHEN si.quantity > 0 THEN si.quantity * pb.purchase_price ELSE 0 END), 0) AS total_modal
    FROM sales s
    LEFT JOIN sale_items si ON si.sale_id = s.id
    LEFT JOIN product_batches pb ON pb.id = si.batch_id
    WHERE DATE(s.created_at) BETWEEN :start AND :end
");
$modalStmt->execute([':start' => $start, ':end' => $end]);
$totalModal = (float) $modalStmt->fetchColumn();

$expenseStmt = $pdo->prepare("
    SELECT COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) AS total_pengeluaran
    FROM expenses
    WHERE expense_date BETWEEN :start AND :end
");
$expenseStmt->execute([':start' => $start, ':end' => $end]);
$totalExpenses = (float) $expenseStmt->fetchColumn();

$pointsSummaryStmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN points_change > 0 THEN points_change ELSE 0 END), 0) AS total_poin_dapat,
        COALESCE(SUM(CASE WHEN points_change < 0 THEN ABS(points_change) ELSE 0 END), 0) AS total_poin_pakai
    FROM member_points
    WHERE created_at BETWEEN :start_dt AND :end_dt
");
$pointsSummaryStmt->execute([
    ':start_dt' => $start . ' 00:00:00',
    ':end_dt' => $end . ' 23:59:59',
]);
$pointsSummary = $pointsSummaryStmt->fetch() ?: [
    'total_poin_dapat' => 0,
    'total_poin_pakai' => 0,
];

$labaKotor = (float) $summaryData['total_penjualan'] - $totalModal;
$labaBersih = $labaKotor - $totalExpenses;

$salesStmt = $pdo->prepare("
    SELECT
        s.id AS sale_id,
        s.invoice_code,
        s.created_at,
        s.member_id,
        m.member_code,
        m.name AS member_name,
        s.cashier_id,
        s.subtotal,
        s.discount_amount,
        s.grand_total,
        s.payment_method,
        s.cash_paid,
        s.change_returned,
        s.points_used,
        s.points_earned,
        u.full_name AS cashier_name
    FROM sales s
    LEFT JOIN members m ON m.id = s.member_id
    LEFT JOIN users u ON u.id = s.cashier_id
    WHERE DATE(s.created_at) BETWEEN :start AND :end
    ORDER BY s.created_at ASC, s.id ASC
");
$salesStmt->execute([':start' => $start, ':end' => $end]);
$salesRows = $salesStmt->fetchAll();

$paymentSummaryStmt = $pdo->prepare("
    SELECT
        s.payment_method,
        COUNT(*) AS jumlah_transaksi,
        COALESCE(SUM(s.grand_total), 0) AS total_penjualan,
        COALESCE(SUM(s.discount_amount), 0) AS total_diskon,
        COALESCE(SUM(s.cash_paid), 0) AS total_dibayar,
        COALESCE(SUM(s.change_returned), 0) AS total_kembali
    FROM sales s
    WHERE DATE(s.created_at) BETWEEN :start AND :end
    GROUP BY s.payment_method
    ORDER BY total_penjualan DESC
");
$paymentSummaryStmt->execute([':start' => $start, ':end' => $end]);
$paymentRows = $paymentSummaryStmt->fetchAll();

$detailStmt = $pdo->prepare("
    SELECT
        si.sale_id,
        s.invoice_code,
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
$detailStmt->execute([':start' => $start, ':end' => $end]);
$detailRows = $detailStmt->fetchAll();

$perProductAggregation = [];
$perCategoryAggregation = [];

foreach ($detailRows as $detail) {
    $sku = $normalizeString($detail['barcode']);
    if ($sku === '') {
        $sku = 'SKU-' . $detail['product_id'];
    }
    $categoryName = $normalizeString($detail['category_name']) ?: 'Tanpa Kategori';
    $modal = ($detail['purchase_price'] !== null ? (float) $detail['purchase_price'] : 0.0) * (float) $detail['quantity'];

    if (!isset($perProductAggregation[$sku])) {
        $perProductAggregation[$sku] = [
            'sku' => $sku,
            'nama' => $normalizeString($detail['product_name']),
            'kategori' => $categoryName,
            'qty' => 0,
            'penjualan' => 0.0,
            'modal' => 0.0,
        ];
    }

    $perProductAggregation[$sku]['qty'] += (float) $detail['quantity'];
    $perProductAggregation[$sku]['penjualan'] += (float) $detail['total'];
    $perProductAggregation[$sku]['modal'] += $modal;

    if (!isset($perCategoryAggregation[$categoryName])) {
        $perCategoryAggregation[$categoryName] = [
            'kategori' => $categoryName,
            'qty' => 0,
            'penjualan' => 0.0,
            'modal' => 0.0,
        ];
    }

    $perCategoryAggregation[$categoryName]['qty'] += (float) $detail['quantity'];
    $perCategoryAggregation[$categoryName]['penjualan'] += (float) $detail['total'];
    $perCategoryAggregation[$categoryName]['modal'] += $modal;
}

$topByRevenue = $perProductAggregation;
usort($topByRevenue, static function ($a, $b) {
    return $b['penjualan'] <=> $a['penjualan'];
});
$topByRevenue = array_slice($topByRevenue, 0, 20);

$topByProfit = $perProductAggregation;
usort($topByProfit, static function ($a, $b) {
    $profitA = $a['penjualan'] - $a['modal'];
    $profitB = $b['penjualan'] - $b['modal'];
    return $profitB <=> $profitA;
});
$topByProfit = array_slice($topByProfit, 0, 20);

$categoryRows = array_values($perCategoryAggregation);
usort($categoryRows, static function ($a, $b) {
    return $b['penjualan'] <=> $a['penjualan'];
});

$dailyStmt = $pdo->prepare("
    SELECT
        dates.dt AS tanggal,
        COALESCE(revenueAgg.total_penjualan, 0) AS total_penjualan,
        COALESCE(cogsAgg.total_modal, 0) AS total_modal,
        COALESCE(expenseAgg.total_pengeluaran, 0) AS total_pengeluaran
    FROM (
        SELECT DATE(created_at) AS dt FROM sales
        WHERE DATE(created_at) BETWEEN :start AND :end
        UNION
        SELECT DATE(expense_date) AS dt FROM expenses
        WHERE expense_date BETWEEN :start AND :end
    ) AS dates
    LEFT JOIN (
        SELECT DATE(created_at) AS tanggal, SUM(grand_total) AS total_penjualan
        FROM sales
        WHERE DATE(created_at) BETWEEN :start AND :end
        GROUP BY DATE(created_at)
    ) AS revenueAgg ON revenueAgg.tanggal = dates.dt
    LEFT JOIN (
        SELECT DATE(s.created_at) AS tanggal, SUM(CASE WHEN si.quantity > 0 THEN si.quantity * pb.purchase_price ELSE 0 END) AS total_modal
        FROM sales s
        LEFT JOIN sale_items si ON si.sale_id = s.id
        LEFT JOIN product_batches pb ON pb.id = si.batch_id
        WHERE DATE(s.created_at) BETWEEN :start AND :end
        GROUP BY DATE(s.created_at)
    ) AS cogsAgg ON cogsAgg.tanggal = dates.dt
    LEFT JOIN (
        SELECT expense_date AS tanggal, SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) AS total_pengeluaran
        FROM expenses
        WHERE expense_date BETWEEN :start AND :end
        GROUP BY expense_date
    ) AS expenseAgg ON expenseAgg.tanggal = dates.dt
    ORDER BY dates.dt ASC
");
$dailyStmt->execute([':start' => $start, ':end' => $end]);
$dailyRows = $dailyStmt->fetchAll();

$pengeluaranStmt = $pdo->prepare("
    SELECT
        e.id,
        e.created_at,
        e.category,
        e.description,
        e.amount,
        e.created_by,
        u.full_name AS user_name
    FROM expenses e
    LEFT JOIN users u ON u.id = e.created_by
    WHERE e.expense_date BETWEEN :start AND :end
    ORDER BY e.created_at ASC, e.id ASC
");
$pengeluaranStmt->execute([':start' => $start, ':end' => $end]);
$pengeluaranRows = $pengeluaranStmt->fetchAll();

$pembelianStmt = $pdo->prepare("
    SELECT
        pb.id AS purchase_id,
        pb.product_id,
        pb.supplier_id,
        pb.batch_code,
        pb.stock_in,
        pb.purchase_price,
        pb.sell_price,
        pb.expiry_date,
        pb.received_at,
        pb.created_at,
        pb.updated_at,
        p.name AS product_name,
        p.barcode AS product_barcode,
        s.name AS supplier_name
    FROM product_batches pb
    LEFT JOIN products p ON p.id = pb.product_id
    LEFT JOIN suppliers s ON s.id = pb.supplier_id
    WHERE DATE(pb.received_at) BETWEEN :start AND :end
       OR DATE(pb.created_at) BETWEEN :start AND :end
    ORDER BY pb.received_at ASC, pb.id ASC
");
$pembelianStmt->execute([':start' => $start, ':end' => $end]);
$pembelianRows = $pembelianStmt->fetchAll();

$pembelianHeaderMap = [];
$pembelianDetailRows = [];
foreach ($pembelianRows as $row) {
    $purchaseId = $row['purchase_id'];

    if (!isset($pembelianHeaderMap[$purchaseId])) {
        $total = (float) $row['purchase_price'] * (float) $row['stock_in'];
        $pembelianHeaderMap[$purchaseId] = [
            $purchaseId,
            $normalizeString($row['supplier_id']),
            $normalizeDateTime($row['received_at'] ?: $row['created_at']),
            $normalizeAmount($total),
            '0',
            '0',
        ];
    } else {
        $existingTotal = (float) ($pembelianHeaderMap[$purchaseId][3] !== '' ? $pembelianHeaderMap[$purchaseId][3] : 0);
        $additional = (float) $row['purchase_price'] * (float) $row['stock_in'];
        $pembelianHeaderMap[$purchaseId][3] = $normalizeAmount($existingTotal + $additional);
    }

    $sku = $normalizeString($row['product_barcode']);
    if ($sku === '') {
        $sku = 'SKU-' . $row['product_id'];
    }

    $pembelianDetailRows[] = [
        $purchaseId,
        $sku,
        $normalizeAmount($row['stock_in']),
        $normalizeAmount($row['purchase_price']),
        $normalizeString($row['expiry_date']),
    ];
}
$pembelianHeaderRows = array_values($pembelianHeaderMap);

$produkStmt = $pdo->query("
    SELECT
        p.id,
        p.barcode,
        p.name,
        c.name AS category_name,
        p.unit,
        (
            SELECT pb.sell_price
            FROM product_batches pb
            WHERE pb.product_id = p.id
            ORDER BY pb.received_at DESC, pb.id DESC
            LIMIT 1
        ) AS default_sell_price,
        p.created_at,
        p.updated_at,
        p.points_reward
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    ORDER BY p.name ASC
");
$produkRows = $produkStmt->fetchAll();

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
        p.barcode AS product_barcode
    FROM stock_adjustments sa
    LEFT JOIN products p ON p.id = sa.product_id
    WHERE DATE(sa.created_at) BETWEEN :start AND :end
    ORDER BY sa.created_at ASC, sa.id ASC
");
$stockStmt->execute([':start' => $start, ':end' => $end]);
$stockRows = $stockStmt->fetchAll();

$invoiceToSaleId = [];
foreach ($salesRows as $sale) {
    if (!empty($sale['invoice_code'])) {
        $invoiceToSaleId[$sale['invoice_code']] = $sale['sale_id'];
    }
}

$kartuStokRows = [];
foreach ($stockRows as $stock) {
    $sku = $normalizeString($stock['product_barcode']);
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
        $normalizeDateTime($stock['created_at']),
        $normalizeString($stock['adjustment_type']),
        $normalizeAmount($stock['quantity']),
        $normalizeString($stock['reason']),
        $refId,
        $normalizeString($stock['created_by']),
    ];
}

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

$memberRunningBalance = [];
$poinRows = [];
foreach ($pointsRows as $point) {
    $memberKey = (string) ($point['member_code'] ?? $point['member_id']);
    if (!array_key_exists($memberKey, $memberRunningBalance)) {
        $memberRunningBalance[$memberKey] = 0;
    }

    $memberRunningBalance[$memberKey] += (int) $point['points_change'];

    $poinRows[] = [
        $normalizeString($point['sale_id']),
        $memberKey,
        $point['points_change'] > 0 ? $point['points_change'] : 0,
        $point['points_change'] < 0 ? abs($point['points_change']) : 0,
        $memberRunningBalance[$memberKey],
        $normalizeDateTime($point['created_at']),
    ];
}

$escapeXml = static function (string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');
};

$columnLetter = static function (int $index): string {
    $letters = '';
    while ($index > 0) {
        $index--;
        $letters = chr(($index % 26) + 65) . $letters;
        $index = intdiv($index, 26);
    }

    return $letters;
};

$buildWorksheetXml = static function (array $rows, array $columnTypes = []) use ($escapeXml, $columnLetter): string {
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>';

    $rowNumber = 1;
    foreach ($rows as $row) {
        $xml .= '<row r="' . $rowNumber . '">';
        $colIndex = 1;
        foreach ($row as $value) {
            $cellRef = $columnLetter($colIndex) . $rowNumber;
            $type = $columnTypes[$colIndex - 1] ?? 'string';

            if ($value === '' || $value === null) {
                $xml .= '<c r="' . $cellRef . '"/>';
            } elseif ($type === 'number' && is_numeric($value)) {
                $xml .= '<c r="' . $cellRef . '"><v>' . (0 + $value) . '</v></c>';
            } else {
                $xml .= '<c r="' . $cellRef . '" t="inlineStr"><is><t>' . $escapeXml((string) $value) . '</t></is></c>';
            }

            $colIndex++;
        }
        $xml .= '</row>';
        $rowNumber++;
    }

    $xml .= '</sheetData></worksheet>';
    return $xml;
};

$sheets = [];

$sheets[] = [
    'name' => 'Summary',
    'data' => [
        ['Metric', 'Nilai'],
        ['Periode Mulai', $start],
        ['Periode Selesai', $end],
        ['Jumlah Transaksi', (int) $summaryData['jumlah_transaksi']],
        ['Total Subtotal', $normalizeAmount($summaryData['total_subtotal'])],
        ['Total Diskon', $normalizeAmount($summaryData['total_diskon'])],
        ['Total Penjualan', $normalizeAmount($summaryData['total_penjualan'])],
        ['Total Modal', $normalizeAmount($totalModal)],
        ['Total Pengeluaran', $normalizeAmount($totalExpenses)],
        ['Laba Kotor', $normalizeAmount($labaKotor)],
        ['Laba Bersih', $normalizeAmount($labaBersih)],
        ['Total Dibayar', $normalizeAmount($summaryData['total_dibayar'])],
        ['Total Kembalian', $normalizeAmount($summaryData['total_kembali'])],
        ['Total Poin Dapat', (int) $pointsSummary['total_poin_dapat']],
        ['Total Poin Pakai', (int) $pointsSummary['total_poin_pakai']],
    ],
    'types' => ['string', 'number'],
];

$topRevenueRows = [['SKU', 'Nama', 'Kategori', 'Qty', 'Total Penjualan', 'Total Modal', 'Laba']];
foreach ($topByRevenue as $item) {
    $topRevenueRows[] = [
        $item['sku'],
        $item['nama'],
        $item['kategori'],
        $normalizeAmount($item['qty']),
        $normalizeAmount($item['penjualan']),
        $normalizeAmount($item['modal']),
        $normalizeAmount($item['penjualan'] - $item['modal']),
    ];
}
$sheets[] = [
    'name' => 'Top_by_Revenue',
    'data' => $topRevenueRows,
    'types' => ['string', 'string', 'string', 'number', 'number', 'number', 'number'],
];

$topProfitRows = [['SKU', 'Nama', 'Kategori', 'Qty', 'Total Penjualan', 'Total Modal', 'Laba']];
foreach ($topByProfit as $item) {
    $topProfitRows[] = [
        $item['sku'],
        $item['nama'],
        $item['kategori'],
        $normalizeAmount($item['qty']),
        $normalizeAmount($item['penjualan']),
        $normalizeAmount($item['modal']),
        $normalizeAmount($item['penjualan'] - $item['modal']),
    ];
}
$sheets[] = [
    'name' => 'Top_by_Profit',
    'data' => $topProfitRows,
    'types' => ['string', 'string', 'string', 'number', 'number', 'number', 'number'],
];

$categorySheetRows = [['Kategori', 'Qty', 'Total Penjualan', 'Total Modal', 'Laba']];
foreach ($categoryRows as $catRow) {
    $categorySheetRows[] = [
        $catRow['kategori'],
        $normalizeAmount($catRow['qty']),
        $normalizeAmount($catRow['penjualan']),
        $normalizeAmount($catRow['modal']),
        $normalizeAmount($catRow['penjualan'] - $catRow['modal']),
    ];
}
$sheets[] = [
    'name' => 'By_Category',
    'data' => $categorySheetRows,
    'types' => ['string', 'number', 'number', 'number', 'number'],
];

$harianRows = [['Tanggal', 'Total Penjualan', 'Total Modal', 'Total Pengeluaran', 'Laba Kotor', 'Laba Bersih']];
foreach ($dailyRows as $day) {
    $gross = (float) $day['total_penjualan'] - (float) $day['total_modal'];
    $net = $gross - (float) $day['total_pengeluaran'];
    $harianRows[] = [
        $day['tanggal'],
        $normalizeAmount($day['total_penjualan']),
        $normalizeAmount($day['total_modal']),
        $normalizeAmount($day['total_pengeluaran']),
        $normalizeAmount($gross),
        $normalizeAmount($net),
    ];
}
$sheets[] = [
    'name' => 'Omzet_Harian',
    'data' => $harianRows,
    'types' => ['string', 'number', 'number', 'number', 'number', 'number'],
];

$paymentSheetRows = [['Metode', 'Jumlah Transaksi', 'Total Penjualan', 'Total Diskon', 'Total Dibayar', 'Total Kembali']];
foreach ($paymentRows as $payment) {
    $paymentSheetRows[] = [
        $normalizeString($payment['payment_method']),
        (int) $payment['jumlah_transaksi'],
        $normalizeAmount($payment['total_penjualan']),
        $normalizeAmount($payment['total_diskon']),
        $normalizeAmount($payment['total_dibayar']),
        $normalizeAmount($payment['total_kembali']),
    ];
}
$sheets[] = [
    'name' => 'By_Payment',
    'data' => $paymentSheetRows,
    'types' => ['string', 'number', 'number', 'number', 'number', 'number'],
];

$pengeluaranSheetRows = [['Expense ID', 'Tanggal Waktu', 'Kategori', 'Deskripsi', 'Jumlah', 'Metode Bayar', 'User']];
foreach ($pengeluaranRows as $expense) {
    $pengeluaranSheetRows[] = [
        $expense['id'],
        $normalizeDateTime($expense['created_at']),
        $normalizeString($expense['category']),
        $normalizeString($expense['description']),
        $normalizeAmount($expense['amount']),
        'cash',
        $normalizeString($expense['user_name']),
    ];
}
$sheets[] = [
    'name' => 'Pengeluaran_Raw',
    'data' => $pengeluaranSheetRows,
    'types' => ['number', 'string', 'string', 'string', 'number', 'string', 'string'],
];

$penjualanHeaderRows = [[
    'sale_id',
    'invoice_code',
    'tanggal_waktu',
    'customer_id',
    'customer_name',
    'cashier_id',
    'cashier_name',
    'subtotal',
    'diskon_transaksi',
    'pajak',
    'grand_total',
    'metode_bayar',
    'dibayar',
    'kembali',
    'points_used',
    'points_earned',
]];
foreach ($salesRows as $sale) {
    $customerKey = $sale['member_code'] ?? $sale['member_id'];
    $penjualanHeaderRows[] = [
        $sale['sale_id'],
        $normalizeString($sale['invoice_code']),
        $normalizeDateTime($sale['created_at']),
        $normalizeString($customerKey),
        $normalizeString($sale['member_name']),
        $normalizeString($sale['cashier_id']),
        $normalizeString($sale['cashier_name']),
        $normalizeAmount($sale['subtotal']),
        $normalizeAmount($sale['discount_amount']),
        '0',
        $normalizeAmount($sale['grand_total']),
        $normalizeString($sale['payment_method']),
        $normalizeAmount($sale['cash_paid']),
        $normalizeAmount($sale['change_returned']),
        (int) $sale['points_used'],
        (int) $sale['points_earned'],
    ];
}
$sheets[] = [
    'name' => 'Penjualan_Header',
    'data' => $penjualanHeaderRows,
    'types' => [
        'number', 'string', 'string', 'string', 'string', 'string', 'string',
        'number', 'number', 'number', 'number', 'string', 'number', 'number', 'number', 'number',
    ],
];

$penjualanDetailRows = [[
    'sale_id',
    'invoice_code',
    'sku',
    'nama_item',
    'kategori',
    'qty',
    'harga_satuan',
    'diskon_item',
    'subtotal',
    'cogs_per_unit',
    'supplier_id',
]];
foreach ($detailRows as $detail) {
    $sku = $normalizeString($detail['barcode']);
    if ($sku === '') {
        $sku = 'SKU-' . $detail['product_id'];
    }

    $penjualanDetailRows[] = [
        $detail['sale_id'],
        $normalizeString($detail['invoice_code']),
        $sku,
        $normalizeString($detail['product_name']),
        $normalizeString($detail['category_name']),
        $normalizeAmount($detail['quantity']),
        $normalizeAmount($detail['price']),
        $normalizeAmount($detail['discount']),
        $normalizeAmount($detail['total']),
        $normalizeAmount($detail['purchase_price']),
        $normalizeString($detail['supplier_id']),
    ];
}
$sheets[] = [
    'name' => 'Penjualan_Detail',
    'data' => $penjualanDetailRows,
    'types' => ['number', 'string', 'string', 'string', 'string', 'number', 'number', 'number', 'number', 'number', 'string'],
];

$sheets[] = [
    'name' => 'Pembelian_Header',
    'data' => array_merge([['purchase_id', 'supplier_id', 'tanggal_waktu', 'total', 'diskon', 'pajak']], $pembelianHeaderRows),
    'types' => ['number', 'string', 'string', 'number', 'number', 'number'],
];

$sheets[] = [
    'name' => 'Pembelian_Detail',
    'data' => array_merge([['purchase_id', 'sku', 'qty', 'harga_beli_per_unit', 'expired_date']], $pembelianDetailRows),
    'types' => ['number', 'string', 'number', 'number', 'string'],
];

$produkSheetRows = [[
    'sku',
    'nama_item',
    'kategori',
    'satuan',
    'harga_jual_default',
    'harga_minimum',
    'barcode',
    'points_reward',
]];
foreach ($produkRows as $product) {
    $sku = $normalizeString($product['barcode']);
    if ($sku === '') {
        $sku = 'SKU-' . $product['id'];
    }

    $produkSheetRows[] = [
        $sku,
        $normalizeString($product['name']),
        $normalizeString($product['category_name']),
        $normalizeString($product['unit']),
        $normalizeAmount($product['default_sell_price']),
        '',
        $normalizeString($product['barcode']),
        (int) $product['points_reward'],
    ];
}
$sheets[] = [
    'name' => 'Produk_Master',
    'data' => $produkSheetRows,
    'types' => ['string', 'string', 'string', 'string', 'number', 'number', 'string', 'number'],
];

$sheets[] = [
    'name' => 'Kartu_Stok',
    'data' => array_merge([['sku', 'tanggal_waktu', 'tipe', 'qty', 'keterangan', 'ref_id', 'user_id']], $kartuStokRows),
    'types' => ['string', 'string', 'string', 'number', 'string', 'string', 'string'],
];

$sheets[] = [
    'name' => 'Poin',
    'data' => array_merge([['sale_id', 'customer_id', 'poin_dapat', 'poin_pakai', 'saldo_poin', 'tanggal_waktu']], $poinRows),
    'types' => ['string', 'string', 'number', 'number', 'number', 'string'],
];

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('Ekstensi ZipArchive tidak tersedia di server.');
}

$zip = new ZipArchive();
$tempZipPath = tempnam(sys_get_temp_dir(), 'xlsx_');
if ($zip->open($tempZipPath, ZipArchive::OVERWRITE) !== true) {
    unlink($tempZipPath);
    http_response_code(500);
    exit('Gagal menyiapkan workbook Excel.');
}

$coreProps = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
    . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
    . 'xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
    . '<dc:title>Dataset Laporan ' . $escapeXml($start) . ' - ' . $escapeXml($end) . '</dc:title>'
    . '<dc:creator>KASIR TokoLC</dc:creator>'
    . '<cp:lastModifiedBy>Export Otomatis</cp:lastModifiedBy>'
    . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created>'
    . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:modified>'
    . '</cp:coreProperties>';
$zip->addFromString('docProps/core.xml', $coreProps);

$appProps = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" '
    . 'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
    . '<Application>KASIR TokoLC</Application>'
    . '<DocSecurity>0</DocSecurity>'
    . '<ScaleCrop>false</ScaleCrop>'
    . '<HeadingPairs><vt:vector size="2" baseType="variant">'
    . '<vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant>'
    . '<vt:variant><vt:i4>' . count($sheets) . '</vt:i4></vt:variant>'
    . '</vt:vector></HeadingPairs>'
    . '<TitlesOfParts><vt:vector size="' . count($sheets) . '" baseType="lpstr">';
foreach ($sheets as $sheet) {
    $appProps .= '<vt:lpstr>' . $escapeXml($sheet['name']) . '</vt:lpstr>';
}
$appProps .= '</vt:vector></TitlesOfParts>'
    . '<Company></Company>'
    . '<LinksUpToDate>false</LinksUpToDate>'
    . '<SharedDoc>false</SharedDoc>'
    . '<HyperlinksChanged>false</HyperlinksChanged>'
    . '<AppVersion>16.0300</AppVersion>'
    . '</Properties>';
$zip->addFromString('docProps/app.xml', $appProps);

$rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
    . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
    . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
    . '</Relationships>';
$zip->addFromString('_rels/.rels', $rels);

$workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
$sheetIndex = 1;
foreach ($sheets as $sheet) {
    $workbookRels .= '<Relationship Id="rId' . $sheetIndex . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $sheetIndex . '.xml"/>';
    $sheetIndex++;
}
$workbookRels .= '<Relationship Id="rId' . $sheetIndex . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
$workbookRels .= '</Relationships>';
$zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);

$workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
    . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<fileVersion appName="xl" lastEdited="7" lowestEdited="7" rupBuild="22626"/>'
    . '<workbookPr date1904="false" defaultThemeVersion="166925"/>'
    . '<bookViews><workbookView xWindow="0" yWindow="0" windowWidth="23040" windowHeight="11520"/></bookViews>'
    . '<sheets>';
$sheetIndex = 1;
foreach ($sheets as $sheet) {
    $workbookXml .= '<sheet name="' . $escapeXml($sheet['name']) . '" sheetId="' . $sheetIndex . '" r:id="rId' . $sheetIndex . '"/>';
    $sheetIndex++;
}
$workbookXml .= '</sheets><calcPr calcId="171027"/></workbook>';
$zip->addFromString('xl/workbook.xml', $workbookXml);

$stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<fonts count="1"><font><sz val="11"/><color theme="1"/><name val="Calibri"/><family val="2"/></font></fonts>'
    . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
    . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
    . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
    . '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
    . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
    . '</styleSheet>';
$zip->addFromString('xl/styles.xml', $stylesXml);

$sheetIndex = 1;
foreach ($sheets as $sheet) {
    $sheetXml = $buildWorksheetXml($sheet['data'], $sheet['types'] ?? []);
    $zip->addFromString('xl/worksheets/sheet' . $sheetIndex . '.xml', $sheetXml);
    $sheetIndex++;
}

$contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
    . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
    . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
    . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
$sheetIndex = 1;
foreach ($sheets as $sheet) {
    $contentTypes .= '<Override PartName="/xl/worksheets/sheet' . $sheetIndex . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    $sheetIndex++;
}
$contentTypes .= '</Types>';
$zip->addFromString('[Content_Types].xml', $contentTypes);

$zip->close();

$filename = sprintf('Analisis_%s_sd_%s.xlsx', $start, $end);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tempZipPath));

$stream = fopen($tempZipPath, 'rb');
if ($stream !== false) {
    fpassthru($stream);
    fclose($stream);
}

unlink($tempZipPath);
exit;
