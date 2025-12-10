<?php

$pdo = get_db_connection();
$summary = fetch_dashboard_summary($pdo);
$lowStocks = fetch_low_stock_items_v2($pdo);
$expiring = fetch_expiring_items($pdo, 5);
$pendingLabels = fetch_pending_labels($pdo);

$latestSales = $pdo->query("
    SELECT s.invoice_code, s.grand_total, s.payment_method, s.created_at, u.full_name AS kasir
    FROM sales s
    LEFT JOIN users u ON u.id = s.cashier_id
    ORDER BY s.created_at DESC
    LIMIT 5
")->fetchAll();

?>

<section class="quick-access-menu">
    <a href="index.php?page=transaksi" class="quick-access-button">
        <span class="icon">💰</span>
        <span>Kasir</span>
    </a>
    <a href="index.php?page=barang" class="quick-access-button">
        <span class="icon">📦</span>
        <span>Data Barang</span>
    </a>
    <a href="index.php?page=stok" class="quick-access-button">
        <span class="icon">➕</span>
        <span>Tambah Stok</span>
    </a>
</section>

<section class="card-grid">
    <div class="card">
        <h3>Total Penjualan Hari Ini</h3>
        <p><?= format_rupiah($summary['total_sales_today']) ?></p>
    </div>
    <div class="card">
        <h3>Jumlah Transaksi</h3>
        <p><?= (int) $summary['total_transactions_today'] ?></p>
    </div>
    <div class="card">
        <h3>Member Aktif</h3>
        <p><?= (int) $summary['active_members'] ?></p>
    </div>
    <div class="card">
        <h3>Nilai Stok</h3>
        <p><?= format_rupiah($summary['stock_value']) ?></p>
    </div>
</section>

<div class="grid-2" style="margin-top:1.5rem;">
    <section class="card">
        <h3>Stok Menipis</h3>
        <?php if (!$lowStocks): ?>
            <p class="muted">Semua stok aman.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Produk</th>
                        <th>Stok</th>
                        <th>Minimal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lowStocks as $item): ?>
                        <tr>
                            <td><?= sanitize($item['name']) ?></td>
                            <td><?= (int) $item['stock_total'] ?></td>
                            <td><?= (int) $item['stock_minimum'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="card">
        <h3>Kadaluarsa 7 Hari</h3>
        <?php if (!$expiring): ?>
            <p class="muted">Tidak ada batch mendekati kadaluarsa.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Produk</th>
                        <th>Batch</th>
                        <th>Kadaluarsa</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($expiring as $item): ?>
                        <tr>
                            <td><?= sanitize($item['name']) ?></td>
                            <td><?= sanitize($item['batch_code']) ?></td>
                            <td><?= format_date($item['expiry_date']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</div>

<div class="grid-2" style="margin-top:1.5rem;">
    <section class="card">
        <h3>Label Harga Belum Dicetak</h3>
        <?php if (!$pendingLabels): ?>
            <p class="muted">Tidak ada label yang menunggu pencetakan.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Produk</th>
                        <th>Harga</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingLabels as $item): ?>
                        <tr>
                            <td><?= sanitize($item['name']) ?></td>
                            <td><?= format_rupiah($item['sell_price']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="card">
        <h3>Transaksi Terbaru</h3>
        <?php if (!$latestSales): ?>
            <p class="muted">Belum ada transaksi.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                <tr>
                    <th>Invoice</th>
                    <th>Kasir</th>
                    <th>Total</th>
                    <th>Waktu</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($latestSales as $sale): ?>
                    <tr>
                        <td><?= sanitize($sale['invoice_code']) ?></td>
                        <td><?= sanitize($sale['kasir'] ?? '-') ?></td>
                        <td><?= format_rupiah($sale['grand_total']) ?></td>
                        <td><?= format_date($sale['created_at'], true) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</div>
