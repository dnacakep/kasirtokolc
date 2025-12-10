<?php

$pdo = get_db_connection();
$lowStocks = fetch_low_stock_items_v2($pdo);
$expiring = fetch_expiring_items($pdo);
$pendingLabels = fetch_pending_labels($pdo);

?>

<section class="card">
    <h2>Notifikasi Stok Menipis</h2>
    <div class="print-actions" data-stock-print-container style="margin-bottom: 1rem;">
        <button type="button" class="button secondary" data-stock-print="pc">Cetak di PC</button>
        <button type="button" class="button secondary" data-stock-print="app">Cetak via App</button>
        <a class="button secondary" href="<?= BASE_URL ?>/actions/print_stok_menipis.php" target="_blank" rel="noopener">Buka Halaman Cetak</a>
    </div>
    <?php if (!$lowStocks): ?>
        <p class="muted">Tidak ada stok menipis.</p>
    <?php else: ?>
        <table class="table">
            <thead>
            <tr>
                <th>Produk</th>
                <th>Sisa</th>
                <th>Minimal</th>
            </tr>
            </thead>
            <tbody>
            <?php
            $currentCategory = null;
            foreach ($lowStocks as $item):
                $category = $item['category_name'] ?? null;
                if ($category !== $currentCategory):
                    $currentCategory = $category;
            ?>
                    <tr>
                        <td colspan="3" style="background-color: #f0f0f0;"><strong><?= sanitize($currentCategory ?: 'Tanpa Kategori') ?></strong></td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <td style="padding-left: 20px;"><?= sanitize($item['name']) ?></td>
                    <td><?= (int) $item['stock_total'] ?></td>
                    <td><?= (int) $item['stock_minimum'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section class="card" style="margin-top:1.5rem;">
    <h2>Kadaluarsa Dalam 7 Hari</h2>
    <?php if (!$expiring): ?>
        <p class="muted">Tidak ada batch mendekati kadaluarsa.</p>
    <?php else: ?>
        <table class="table">
            <thead>
            <tr>
                <th>Produk</th>
                <th>Batch</th>
                <th>Tanggal</th>
                <th>Sisa</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($expiring as $item): ?>
                <tr>
                    <td><?= sanitize($item['name']) ?></td>
                    <td><?= sanitize($item['batch_code']) ?></td>
                    <td><?= format_date($item['expiry_date']) ?></td>
                    <td><?= (int) $item['stock_remaining'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section class="card" style="margin-top:1.5rem;">
    <h2>Label Belum Dicetak</h2>
    <?php if (!$pendingLabels): ?>
        <p class="muted">Semua label aman.</p>
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

<script>
    (function() {
        const container = document.querySelector('[data-stock-print-container]');
        if (!container) {
            return;
        }

        const handleStockPrint = (mode) => {
            const existing = document.getElementById('stock-print-frame');
            if (existing) {
                existing.remove();
            }

            const iframe = document.createElement('iframe');
            iframe.id = 'stock-print-frame';
            iframe.style.display = 'none';
            iframe.src = '<?= BASE_URL ?>/actions/print_stok_menipis.php';
            document.body.appendChild(iframe);

            iframe.onload = () => {
                try {
                    setTimeout(() => {
                        if (mode === 'pc') {
                            iframe.contentWindow.print();
                        } else if (mode === 'app') {
                            if (typeof iframe.contentWindow.printViaApp === 'function') {
                                const printableText = iframe.contentWindow.printViaApp();
                                if (printableText) {
                                    const success = typeof window.sendToKasirPrinter === 'function'
                                        ? window.sendToKasirPrinter(printableText)
                                        : (() => {
                                            const encoded = encodeURIComponent(printableText);
                                            window.location.href = `kasirprinter://print?text=${encoded}`;
                                            return true;
                                        })();
                                    if (!success) {
                                        alert('Gagal mengirim data stok ke aplikasi kasir.');
                                    }
                                } else {
                                    alert('Tidak ada data yang bisa dicetak via aplikasi.');
                                }
                            } else {
                                alert('Fungsi printViaApp tidak tersedia pada template cetak.');
                            }
                        }
                    }, 250);
                } catch (error) {
                    console.error(error);
                    alert('Terjadi kesalahan saat memproses pencetakan stok menipis.');
                } finally {
                    setTimeout(() => iframe.remove(), 2000);
                }
            };

            iframe.onerror = () => {
                alert('Gagal memuat halaman cetak stok menipis.');
            };
        };

        container.addEventListener('click', (event) => {
            const button = event.target.closest('[data-stock-print]');
            if (!button) {
                return;
            }
            event.preventDefault();
            handleStockPrint(button.dataset.stockPrint);
        });
    })();

    async function refreshNotifications() {
        try {
            const response = await fetch('<?= BASE_URL ?>/actions/cek_notifikasi.php');
            if (!response.ok) return;
            const data = await response.json();
            // Placeholder for dynamic refresh if needed.
        } catch (error) {
            console.error(error);
        }
    }

    setInterval(refreshNotifications, 60000);
</script>
