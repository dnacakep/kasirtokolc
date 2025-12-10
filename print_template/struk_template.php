<?php

/** @var array $sale */
/** @var array $items */

$store = get_store_settings();
$storeName = $store['store_name'] ?? APP_NAME;
$storeAddress = $store['address'] ?? '';
$storePhone = $store['phone'] ?? '';
$storeNotes = $store['notes'] ?? '';
$logoPath = $store['logo_path'] ?? '';
$logoUrl = $logoPath ? BASE_URL . '/' . ltrim($logoPath, '/') : '';
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Struk <?= sanitize($sale['invoice_code']) ?></title>
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
        .totals td {
            border-top: 1px dashed #000;
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
<script>
function printViaApp() {
    try {
        const PAPER_WIDTH = 40; // Lebar karakter untuk kertas 58mm (disesuaikan)

        // --- Fungsi Helper untuk Perataan ---
        const centerText = (text) => {
            const trimText = text.trim();
            if (trimText.length >= PAPER_WIDTH) return trimText;
            const padding = Math.floor((PAPER_WIDTH - trimText.length) / 2);
            return ' '.repeat(Math.max(0, padding)) + trimText;
        };

        const alignLeftRight = (left, right) => {
            const leftTrim = left.trim();
            const rightTrim = right.trim();
            if (leftTrim.length + rightTrim.length >= PAPER_WIDTH) {
                return `${leftTrim}\n` + rightTrim.padStart(PAPER_WIDTH);
            }
            return leftTrim + rightTrim.padStart(PAPER_WIDTH - leftTrim.length);
        }

        const getText = (selector) => {
            const element = document.querySelector(selector);
            return element ? element.innerText.trim() : '';
        };

        // --- Mulai Mengumpulkan Teks Struk ---
        let receiptText = '\n';
        receiptText += centerText(getText('header h1')) + '\n';
        const contact = getText('header .store-contact');
        if (contact) {
            contact.split('\n').forEach(line => {
                receiptText += centerText(line.trim()) + '\n';
            });
        }
        receiptText += '\n';

        const meta = getText('header .meta');
        if (meta) {
             meta.split('\n').forEach(line => {
                const colonIndex = line.indexOf(':');
                if (colonIndex > -1) {
                    const label = line.substring(0, colonIndex + 1);
                    const value = line.substring(colonIndex + 1);
                    receiptText += alignLeftRight(label, value) + '\n';
                }
            });
        }

        receiptText += '-'.repeat(PAPER_WIDTH) + '\n';

        const rows = document.querySelectorAll('table:first-of-type tr');
        for (let i = 0; i < rows.length; i++) {
            const row = rows[i];
            if (row.children.length === 1 && row.children[0].hasAttribute('colspan')) {
                const itemName = row.children[0].innerText.trim();
                receiptText += `${itemName}\n`;

                if (i + 1 < rows.length) {
                    const nextRow = rows[i + 1];
                    if (nextRow.children.length === 2) {
                        const qtyPrice = nextRow.children[0].innerText.trim();
                        const total = nextRow.children[1].innerText.trim();
                        receiptText += alignLeftRight(qtyPrice, total) + '\n';
                        i++;

                        if (i + 1 < rows.length) {
                            const discountRow = rows[i + 1];
                            if (discountRow.children.length === 2 && discountRow.children[0].innerText.toLowerCase().includes('diskon')) {
                                const discountLabel = discountRow.children[0].innerText.trim();
                                const discountValue = discountRow.children[1].innerText.trim();
                                receiptText += alignLeftRight(discountLabel, discountValue) + '\n';
                                i++;
                            }
                        }
                    }
                }
            }
        }

        receiptText += '-'.repeat(PAPER_WIDTH) + '\n';

        document.querySelectorAll('.totals tr').forEach(row => {
            if (row.children.length > 1) {
                const label = row.children[0].innerText.trim();
                const value = row.children[1].innerText.trim();
                receiptText += alignLeftRight(label, value) + '\n';
            }
        });

        const memberInfo = document.querySelector('.member-info');
        if (memberInfo) {
            receiptText += '\n';
            const memberName = memberInfo.querySelector('p').innerText.trim();
            receiptText += centerText(memberName) + '\n';
            memberInfo.querySelectorAll('table tr').forEach(row => {
                 if (row.children.length > 1) {
                    const label = row.children[0].innerText.trim();
                    const value = row.children[1].innerText.trim();
                    receiptText += alignLeftRight(label, value) + '\n';
                }
            });
        }
        
        receiptText += '\n';
        const closing = getText('#closing-notes');
        if(closing) {
            closing.split('\n').forEach(line => {
                receiptText += centerText(line.trim()) + '\n';
            });
        }

        receiptText += '\n\n\n\n'; // Tambah margin bawah

        return receiptText;

    } catch (e) {
        // Fallback jika terjadi error, tampilkan errornya
        alert(`Terjadi error JavaScript:\n\nNama Error: ${e.name}\nPesan: ${e.message}\n\nStack Trace:\n${e.stack}`);
        return null;
    }
}

function triggerPrintViaApp() {
    const receiptText = printViaApp();
    if (!receiptText) {
        alert('Tidak ada data yang bisa dikirim ke aplikasi kasir.');
        return;
    }
    const senderInCurrent = typeof window.sendToKasirPrinter === 'function' && window.sendToKasirPrinter(receiptText);
    if (senderInCurrent) {
        return;
    }

    const senderInOpener = window.opener && typeof window.opener.sendToKasirPrinter === 'function'
        ? window.opener.sendToKasirPrinter(receiptText)
        : false;
    if (senderInOpener) {
        return;
    }

    try {
        const encodedText = encodeURIComponent(receiptText);
        const targetWindow = (window.top && window.top !== window) ? window.top : window;
        targetWindow.location.href = `kasirprinter://print?text=${encodedText}`;
    } catch (error) {
        console.error('Gagal mengirim data struk ke aplikasi kasir:', error);
        alert('Gagal mengirim data struk ke aplikasi kasir.');
    }
}
</script>
</head>
<body>
    <div class="controls">
        <a href="<?= BASE_URL ?>/index.php?page=transaksi">&#8592; Kembali ke Kasir</a>
        <button type="button" onclick="triggerPrintViaApp()">Cetak via App</button>
        <button type="button" onclick="window.print()">Cetak (PC/PDF)</button>
    </div>

    <header>
        <?php if ($logoUrl): ?>
            <img src="<?= $logoUrl ?>" alt="<?= sanitize($storeName) ?>" style="max-height:45px;margin-bottom:4px;">
        <?php endif; ?>
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
            <span>Invoice: <?= sanitize($sale['invoice_code']) ?></span>
            <span>Tanggal: <?= format_date($sale['created_at'], true) ?></span>
            <span>Kasir: <?= sanitize($sale['kasir'] ?? '-') ?></span>
        </div>
    </header>

    <table>
        <?php foreach ($items as $item): ?>
            <tr>
                <td colspan="2"><?= sanitize($item['name']) ?></td>
            </tr>
            <tr>
                <td><?= $item['quantity'] ?> x <?= format_rupiah($item['price']) ?></td>
                <td style="text-align:right;"><?= format_rupiah($item['total']) ?></td>
            </tr>
            <?php if ($item['discount'] > 0): ?>
                <tr>
                    <td>Diskon</td>
                    <td style="text-align:right;">-<?= format_rupiah($item['discount']) ?></td>
                </tr>
            <?php endif; ?>
        <?php endforeach; ?>
    </table>

    <?php
    $debtPrincipalAmount = null;
    $debtAdminFee = null;
    $debtTotalAmount = null;
    $debtPaidAmount = 0.0;
    $debtOutstandingAmount = null;
    $debtStatusLabel = null;

    $cashPaidNow = isset($sale['cash_paid']) ? (float) $sale['cash_paid'] : 0.0;
    $isCreditSale = strtolower((string) ($sale['payment_method'] ?? '')) === 'hutang';

    if ($isCreditSale) {
        $transactionSubtotal = isset($sale['subtotal']) ? (float) $sale['subtotal'] : 0.0;
        $pointsUsedValue = isset($sale['points_used']) ? (float) $sale['points_used'] : 0.0;
        $netAfterPoints = max(0, $transactionSubtotal - $pointsUsedValue);

        $fallbackPrincipal = max(0, $netAfterPoints - $cashPaidNow);

        $debtPrincipalAmount = isset($memberDebt['principal_amount'])
            ? (float) $memberDebt['principal_amount']
            : $fallbackPrincipal;

        if ($debtPrincipalAmount < 0) {
            $debtPrincipalAmount = 0.0;
        }

        // Hitung admin fallback secara langsung: 10% dari sisa pokok hutang.
        $fallbackAdmin = max(0, round($fallbackPrincipal * 0.10, 2));

        $debtAdminFee = isset($memberDebt['admin_fee'])
            ? (float) $memberDebt['admin_fee']
            : $fallbackAdmin;

        $debtTotalAmount = isset($memberDebt['total_amount'])
            ? (float) $memberDebt['total_amount']
            : max(0, $debtPrincipalAmount + $debtAdminFee);

        $debtPaidAmount = isset($memberDebt['paid_amount'])
            ? (float) $memberDebt['paid_amount']
            : 0.0;

        $debtOutstandingAmount = max(0, round($debtTotalAmount - $debtPaidAmount, 2));

        $statusMap = [
            'open' => 'Belum lunas',
            'partial' => 'Sebagian dibayar',
            'paid' => 'Lunas',
        ];
        $rawStatus = $memberDebt['status'] ?? 'open';
        $debtStatusLabel = $statusMap[strtolower((string) $rawStatus)] ?? strtoupper((string) $rawStatus);
    }
    ?>

    <table class="totals">
        <tr>
            <td>Subtotal</td>
            <td style="text-align:right;"><?= format_rupiah($sale['subtotal']) ?></td>
        </tr>
        <tr>
            <td>Diskon</td>
            <td style="text-align:right;">-<?= format_rupiah($sale['discount_amount']) ?></td>
        </tr>
        <?php if ($sale['points_used'] > 0): ?>
            <tr>
                <td>Poin Digunakan</td>
                <td style="text-align:right;">-<?= format_rupiah($sale['points_used']) ?></td>
            </tr>
        <?php endif; ?>
        <?php if ($isCreditSale): ?>
            <tr>
                <td>Total</td>
                <td style="text-align:right;"><?= format_rupiah($sale['grand_total']) ?></td>
            </tr>
            <tr>
                <td>Dibayar Saat Ini</td>
                <td style="text-align:right;"><?= format_rupiah($cashPaidNow) ?></td>
            </tr>
            <tr>
                <td>Admin Hutang (10%)</td>
                <td style="text-align:right;"><?= format_rupiah($debtAdminFee ?? 0) ?></td>
            </tr>
            <tr>
                <td>Total Hutang</td>
                <td style="text-align:right;"><?= format_rupiah($debtTotalAmount ?? max(0, ($debtPrincipalAmount ?? 0) + ($debtAdminFee ?? 0))) ?></td>
            </tr>
            <tr>
                <td>Sudah Dibayar</td>
                <td style="text-align:right;"><?= format_rupiah($debtPaidAmount ?? 0) ?></td>
            </tr>
            <tr>
                <td>Sisa Hutang</td>
                <td style="text-align:right;"><?= format_rupiah($debtOutstandingAmount ?? 0) ?></td>
            </tr>
            <tr>
                <td>Status</td>
                <td style="text-align:right;"><?= sanitize($debtStatusLabel ?? 'Belum lunas') ?></td>
            </tr>
        <?php else: ?>
            <tr>
                <td>Total</td>
                <td style="text-align:right;"><?= format_rupiah($sale['grand_total']) ?></td>
            </tr>
            <tr>
                <td>Bayar</td>
                <td style="text-align:right;"><?= format_rupiah($sale['cash_paid']) ?></td>
            </tr>
            <tr>
                <td>Kembali</td>
                <td style="text-align:right;"><?= format_rupiah($sale['change_returned']) ?></td>
            </tr>
        <?php endif; ?>
    </table>

    <?php if ($isCreditSale): ?>
        <p class="muted" style="margin: 10px 0 0;">Catatan: Sisa hutang tercatat pada akun member. Mohon informasikan pelanggan untuk melunasi <?= format_rupiah($debtOutstandingAmount ?? ($debtTotalAmount ?? max(0, ($debtPrincipalAmount ?? 0) + ($debtAdminFee ?? 0)))) ?>.</p>
    <?php endif; ?>

    <?php if ($sale['member_id']):
        /** @var int $member_total_points */
        ?>
        <div class="member-info" style="text-align: center; margin-top: 10px; border-top: 1px dashed #000; padding-top: 8px;">
            <p style="margin: 0 0 4px; font-weight: bold;">Member: <?= sanitize($sale['member_name']) ?></p>
            <table style="width: 80%; margin: 0 auto; text-align: left;">
                <tr>
                    <td>Poin Didapat</td>
                    <td style="text-align: right;"><?= (int) $sale['points_earned'] ?></td>
                </tr>
                <tr>
                    <td>Total Poin</td>
                    <td style="text-align: right;"><?= $member_total_points ?></td>
                </tr>
            </table>
        </div>
    <?php endif; ?>

    <p class="muted" id="closing-notes">
        Terima kasih atas kunjungan Anda
        <?php if ($storeNotes): ?>
            <br><?= sanitize($storeNotes) ?>
        <?php endif; ?>
    </p>

</body>
</html>
