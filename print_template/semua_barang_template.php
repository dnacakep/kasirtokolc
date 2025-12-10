<?php

/** @var array $items */
/** @var string|null $selectedCategoryName */

$selectedCategoryName = $selectedCategoryName ?? null;
$store = get_store_settings();
$storeName = $store['store_name'] ?? APP_NAME;
$storeAddress = $store['address'] ?? '';
$storePhone = $store['phone'] ?? '';

?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Daftar Stok Barang</title>
    <style>
        :root {
            color-scheme: light;
        }
        body {
            font-family: "Courier New", monospace;
            max-width: 300px;
            margin: 8px auto;
            font-size: 11px;
            line-height: 1.35;
        }
        header {
            text-align: center;
            margin-bottom: 8px;
        }
        header h1 {
            font-size: 16px;
            margin: 0;
            letter-spacing: 0.4px;
        }
        header .store-contact {
            font-size: 10px;
            margin-top: 2px;
        }
        header .store-contact span {
            display: block;
        }
        header .meta {
            font-size: 10px;
            margin-top: 4px;
        }
        header .meta span {
            display: block;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        td {
            padding: 2px 0;
        }
        .muted {
            color: #777;
            font-size: 11px;
            text-align: center;
            margin-top: 12px;
        }
        .controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
        }
        .controls a,
        .controls button {
            font-family: inherit;
            font-size: 12px;
            padding: 6px 10px;
            border: 1px solid #111;
            background: #fff;
            cursor: pointer;
            text-decoration: none;
            color: #111;
            border-radius: 4px;
        }
        .controls a:hover,
        .controls button:hover {
            background: #f3f4f6;
        }
        @media print {
            body {
                margin: 0;
            }
            .controls {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <div class="controls">
        <a href="<?= BASE_URL ?>/index.php?page=stok">&#8592; Kembali</a>
        <button type="button" onclick="window.print()">Cetak</button>
    </div>

    <header>
        <h1><?= sanitize($storeName) ?></h1>
        <?php if ($storeAddress || $storePhone): ?>
            <div class="store-contact">
                <?php if ($storeAddress): ?>
                    <span><?= nl2br(sanitize($storeAddress)) ?></span>
                <?php endif; ?>
                <?php if ($storePhone): ?>
                    <span>Telp: <?= sanitize($storePhone) ?></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <div class="meta">
            <span>Daftar Stok Barang</span>
            <span>Tanggal: <?= date('d M Y H:i') ?></span>
            <span>Kategori: <?= sanitize($selectedCategoryName ?? 'Semua Kategori') ?></span>
        </div>
    </header>

    <table>
        <thead>
            <tr>
                <td><b>Nama Barang</b></td>
                <td style="text-align:right;"><b>Stok</b></td>
            </tr>
        </thead>
                <tbody>
                <?php if (empty($items)):
                    ?>
                    <tr>
                        <td colspan="2" style="text-align:center;">Tidak ada barang.</td>
                    </tr>
                <?php else:
                    $currentCategory = null;
                    foreach ($items as $item):
                        $category = $item['nama_kategori'] ?? null;
                        if ($category !== $currentCategory):
                            $currentCategory = $category;
                    ?>
                        <tr>
                            <td colspan="2" style="background-color: #f0f0f0;"><strong><?= sanitize($currentCategory ?: 'Tanpa Kategori') ?></strong></td>
                        </tr>
                    <?php endif; ?>
                        <tr>
                            <td style="padding-left: 15px;"><?= sanitize($item['nama_barang']) ?></td>
                            <td style="text-align:right;"><?= (int) $item['stok'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
    </table>

    <p class="muted">
        Catatan: Daftar ini dibuat untuk keperluan pemesanan stok.
    </p>

    <script>
        function printViaApp() {
            try {
                const PAPER_WIDTH = 40;

                const centerText = (text) => {
                    const trimmed = (text || '').trim();
                    if (trimmed.length >= PAPER_WIDTH) {
                        return trimmed;
                    }
                    const padding = Math.floor((PAPER_WIDTH - trimmed.length) / 2);
                    return ' '.repeat(Math.max(padding, 0)) + trimmed;
                };

                const alignLeftRight = (left, right) => {
                    const leftText = (left || '').trim();
                    const rightText = (right || '').trim();
                    if (leftText.length + rightText.length >= PAPER_WIDTH) {
                        return `${leftText}\n${rightText.padStart(PAPER_WIDTH)}`;
                    }
                    return leftText + rightText.padStart(Math.max(PAPER_WIDTH - leftText.length, rightText.length));
                };

                const getLinesFromSelector = (selector) => {
                    const element = document.querySelector(selector);
                    if (!element) {
                        return [];
                    }
                    return element.innerText.split('\n').map((line) => line.trim()).filter(Boolean);
                };

                let receiptText = '\n';

                const title = document.querySelector('header h1')?.innerText || 'Daftar Stok';
                receiptText += centerText(title) + '\n';

                const contactLines = getLinesFromSelector('header .store-contact');
                contactLines.forEach((line) => {
                    receiptText += centerText(line) + '\n';
                });

                const metaLines = getLinesFromSelector('header .meta');
                metaLines.forEach((line) => {
                    const [label, value] = line.split(':').map((segment) => segment.trim());
                    if (value !== undefined) {
                        receiptText += alignLeftRight(`${label}:`, value) + '\n';
                    } else {
                        receiptText += centerText(line) + '\n';
                    }
                });

                receiptText += '-'.repeat(PAPER_WIDTH) + '\n';

                const rows = Array.from(document.querySelectorAll('table tbody tr'));
                if (rows.length === 0 || (rows.length === 1 && rows[0].children[0].colSpan === 2)) {
                    receiptText += centerText('Tidak ada barang.') + '\n';
                } else {
                    const header = ['Barang', 'Stok'];
                    receiptText += header[0].padEnd(32) + header[1].padStart(8) + '\n';
                    receiptText += '-'.repeat(PAPER_WIDTH) + '\n';

                    rows.forEach((row, index) => {
                        const cells = Array.from(row.children).map((cell) => cell.innerText.trim());
                        
                        // Category row
                        if (row.children.length === 1 && row.children[0].colSpan === 2) {
                            receiptText += '\n' + cells[0].toUpperCase() + '\n';
                        } 
                        // Item row
                        else if (row.children.length === 2) { 
                            const name = (cells[0] || '-');
                            const stock = cells[1] || '0';

                            receiptText += name.padEnd(32).substring(0, 32);
                            receiptText += stock.padStart(8).substring(0, 8) + '\n';
                        }
                    });
                }

                receiptText += '-'.repeat(PAPER_WIDTH) + '\n\n';
                receiptText += centerText('Dicetak via Kasir App') + '\n';
                receiptText += '\n';

                return receiptText;
            } catch (error) {
                console.error('printViaApp error', error);
                return '';
            }
        }
    </script>
</body>
</html>
