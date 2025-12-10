<?php

$actor = current_user();
if ($actor['role'] === ROLE_KASIR) {
    http_response_code(403);
    ?>
    <section class="card">
        <h2>Akses Terbatas</h2>
        <p class="muted">Hanya manajer atau admin yang dapat melihat analitik.</p>
    </section>
    <?php
    return;
}

$pdo = get_db_connection();

$period = $_GET['period'] ?? 'month';
$requestedSort = $_GET['sort'] ?? 'jumlah_terjual';
$requestedDirection = strtolower($_GET['direction'] ?? 'desc');

$sortableColumns = [
    'name' => 'p.name',
    'jumlah_terjual' => 'jumlah_terjual',
    'total_penjualan' => 'total_penjualan',
    'total_modal' => 'total_modal',
    'laba' => 'laba',
];

$sort = array_key_exists($requestedSort, $sortableColumns) ? $requestedSort : 'jumlah_terjual';
$direction = in_array($requestedDirection, ['asc', 'desc'], true) ? $requestedDirection : 'desc';

$rangeStart = date('Y-m-01');

if ($period === 'week') {
    $rangeStart = date('Y-m-d', strtotime('-6 day'));
} elseif ($period === 'year') {
    $rangeStart = date('Y-01-01');
}

$orderBy = $sortableColumns[$sort] . ' ' . strtoupper($direction);

$stmt = $pdo->prepare("
    SELECT p.name,
           SUM(si.quantity) AS jumlah_terjual,
           SUM(si.total) AS total_penjualan,
           SUM(si.quantity * pb.purchase_price) AS total_modal,
           SUM(si.total) - SUM(si.quantity * pb.purchase_price) AS laba
    FROM sale_items si
    INNER JOIN sales s ON s.id = si.sale_id
    INNER JOIN products p ON p.id = si.product_id
    INNER JOIN product_batches pb ON pb.id = si.batch_id
    WHERE DATE(s.created_at) BETWEEN :start AND CURDATE()
    GROUP BY p.id
    ORDER BY {$orderBy}
    LIMIT 20
");
$stmt->execute([':start' => $rangeStart]);
$items = $stmt->fetchAll();

$chartLabels = array_map(static fn($item) => $item['name'], $items);
$chartValues = array_map(static fn($item) => (int) $item['jumlah_terjual'], $items);

$buildSortUrl = static function (string $column) use ($period, $sort, $direction) {
    $nextDirection = ($sort === $column && $direction === 'asc') ? 'desc' : 'asc';
    $query = http_build_query([
        'page' => 'performa_barang',
        'period' => $period,
        'sort' => $column,
        'direction' => $nextDirection,
    ]);

    return BASE_URL . '/index.php?' . $query;
};

$renderSortIndicator = static function (string $column) use ($sort, $direction) {
    if ($sort !== $column) {
        return '';
    }

    return $direction === 'asc' ? ' ^' : ' v';
};

?>

<section class="card">
    <h2>Performa Barang</h2>
    <form method="get" action="<?= BASE_URL ?>/index.php">
        <input type="hidden" name="page" value="performa_barang">
        <input type="hidden" name="sort" value="<?= sanitize($sort) ?>">
        <input type="hidden" name="direction" value="<?= sanitize($direction) ?>">
        <div class="form-group">
            <label for="period">Periode</label>
            <select id="period" name="period" onchange="this.form.submit()">
                <option value="week" <?= $period === 'week' ? 'selected' : '' ?>>7 hari terakhir</option>
                <option value="month" <?= $period === 'month' ? 'selected' : '' ?>>Bulan berjalan</option>
                <option value="year" <?= $period === 'year' ? 'selected' : '' ?>>Tahun berjalan</option>
            </select>
        </div>
    </form>

    <?php if (!empty($items)): ?>
        <div class="chart-wrapper" style="height: 320px; margin-bottom: 1.5rem;">
            <canvas id="itemSalesChart"></canvas>
        </div>
    <?php else: ?>
        <p class="muted">Belum ada data penjualan untuk periode ini.</p>
    <?php endif; ?>

    <?php if (!empty($items)): ?>
        <table class="table">
            <thead>
            <tr>
                <th>
                    <a class="sort-link" href="<?= sanitize($buildSortUrl('name')) ?>">
                        Nama <?= sanitize($renderSortIndicator('name')) ?>
                    </a>
                </th>
                <th>
                    <a class="sort-link" href="<?= sanitize($buildSortUrl('jumlah_terjual')) ?>">
                        Jumlah Terjual <?= sanitize($renderSortIndicator('jumlah_terjual')) ?>
                    </a>
                </th>
                <th>
                    <a class="sort-link" href="<?= sanitize($buildSortUrl('total_penjualan')) ?>">
                        Total Penjualan <?= sanitize($renderSortIndicator('total_penjualan')) ?>
                    </a>
                </th>
                <th>
                    <a class="sort-link" href="<?= sanitize($buildSortUrl('total_modal')) ?>">
                        Total Modal <?= sanitize($renderSortIndicator('total_modal')) ?>
                    </a>
                </th>
                <th>
                    <a class="sort-link" href="<?= sanitize($buildSortUrl('laba')) ?>">
                        Laba <?= sanitize($renderSortIndicator('laba')) ?>
                    </a>
                </th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= sanitize($item['name']) ?></td>
                    <td><?= (int) $item['jumlah_terjual'] ?></td>
                    <td><?= format_rupiah($item['total_penjualan']) ?></td>
                    <td><?= format_rupiah($item['total_modal']) ?></td>
                    <td><?= format_rupiah($item['laba']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<?php if (!empty($items)): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const ctx = document.getElementById('itemSalesChart');
    if (!ctx) {
        return;
    }

    const chartInstance = Chart.getChart(ctx);
    if (chartInstance) {
        chartInstance.destroy();
    }

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>,
            datasets: [{
                label: 'Jumlah Terjual',
                data: <?= json_encode($chartValues, JSON_UNESCAPED_UNICODE) ?>,
                backgroundColor: 'rgba(54, 162, 235, 0.6)',
                borderColor: 'rgba(54, 162, 235, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    }
                },
                x: {
                    ticks: {
                        maxRotation: 45,
                        minRotation: 0
                    }
                }
            },
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function (context) {
                            const value = context.parsed.y ?? 0;
                            return value.toLocaleString('id-ID') + ' unit';
                        }
                    }
                }
            }
        }
    });
});
</script>
<?php endif; ?>
