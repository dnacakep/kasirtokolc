        </main>
        <footer>
            <small>&copy; <?= date('Y') ?> <?= APP_NAME ?> &middot; aplikasi ini dibuat tanpa ngoding sama sekali.</small>
        </footer>
    </div>
</div>
<div class="scanner-overlay" id="barcode-scanner" data-zxing-src="<?= BASE_URL ?>/assets/js/vendor/zxing.min.js">
    <div class="scanner-panel">
        <h3>Scan Barcode</h3>
        <video id="barcode-scanner-video" autoplay playsinline muted></video>
        <p class="scanner-status" data-scanner-status>Menyiapkan kamera...</p>
        <div class="scanner-actions">
            <button class="button secondary" type="button" data-scan-close>Tutup</button>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="<?= BASE_URL ?>/assets/js/app.js?v=<?= $assetVersions['assets/js/app.js'] ?? time() ?>"></script>
<script src="<?= BASE_URL ?>/assets/js/scanner.js?v=<?= $assetVersions['assets/js/scanner.js'] ?? time() ?>"></script>
<script>
    (function () {
        const dismissAlerts = () => {
            const alerts = document.querySelectorAll('.alert[data-autodismiss]');
            alerts.forEach((alert) => {
                const timeout = parseInt(alert.getAttribute('data-autodismiss'), 10) || 4000;
                setTimeout(() => {
                    alert.classList.add('alert--hiding');
                    setTimeout(() => {
                        alert.remove();
                    }, 350);
                }, timeout);
            });
        };
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', dismissAlerts);
        } else {
            dismissAlerts();
        }
    })();
</script>
<script>
    (function () {
        const SCHEME_PREFIX = 'kasirprinter://print?text=';
        window.sendToKasirPrinter = function (rawText) {
            if (!rawText || typeof rawText !== 'string') {
                alert('Tidak ada data yang bisa dikirim ke aplikasi kasir.');
                return false;
            }

            try {
                const encoded = encodeURIComponent(rawText);
                const targetWindow = (window.top && window.top !== window) ? window.top : window;
                targetWindow.location.href = SCHEME_PREFIX + encoded;
                return true;
            } catch (error) {
                console.error('Gagal menyiapkan data cetak ke aplikasi kasir:', error);
                alert('Gagal menyiapkan data cetak ke aplikasi kasir.');
                return false;
            }
        };
    })();
</script>
<?php if ($currentPage === 'laporan'): ?>
<script>
    const ctx = document.getElementById('dailyProfitChart').getContext('2d');
    const dailyProfitChart = new Chart(ctx, {
        type: 'bar', /* Diubah dari 'line' menjadi 'bar' */
        data: {
            labels: <?= json_encode($chartLabels) ?>,
            datasets: [{
                label: 'Laba Bersih Harian',
                data: <?= json_encode($chartData) ?>,
                borderColor: 'rgb(75, 192, 192)',
                backgroundColor: 'rgba(75, 192, 192, 0.5)', /* Tambahkan warna latar belakang untuk bar */
                tension: 0.1,
                fill: false
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value, index, ticks) {
                            return 'Rp' + value.toLocaleString('id-ID');
                        }
                    }
                },
                x: {
                    grid: {
                        display: false
                    }
                }
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            if (context.parsed.y !== null) {
                                label += 'Rp' + context.parsed.y.toLocaleString('id-ID');
                            }
                            return label;
                        }
                    }
                }
            }
        }
    });
</script>
<?php endif; ?>
</body>
</html>
