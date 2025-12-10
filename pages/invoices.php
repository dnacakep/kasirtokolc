<?php

if (!function_exists('ensure_csrf_token')) {
    require_once __DIR__ . '/../config/auth.php';
    require_once __DIR__ . '/../includes/fungsi.php';
}

ensure_csrf_token();

$pdo = get_db_connection();

$searchTerm = trim($_GET['search'] ?? '');
$dateFromRaw = $_GET['date_from'] ?? '';
$dateToRaw = $_GET['date_to'] ?? '';
$pageNumber = max(1, (int) ($_GET['p'] ?? 1));
$perPage = 50;
$offset = ($pageNumber - 1) * $perPage;

$conditions = [];
$params = [];

if ($searchTerm !== '') {
    $conditions[] = "(
        s.invoice_code LIKE :search
        OR m.name LIKE :search
        OR m.member_code LIKE :search
        OR s.invoice_code = :search_exact_invoice
        OR m.member_code = :search_exact_member
    )";
    $params[':search'] = '%' . $searchTerm . '%';
    $params[':search_exact_invoice'] = $searchTerm;
    $params[':search_exact_member'] = $searchTerm;
}

$dateFrom = null;
$dateTo = null;
$dateFromForInput = '';
$dateToForInput = '';

if ($dateFromRaw !== '') {
    $dateFromObj = DateTime::createFromFormat('Y-m-d', $dateFromRaw);
    if ($dateFromObj !== false) {
        $dateFromForInput = $dateFromObj->format('Y-m-d');
        $dateFrom = $dateFromObj->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $conditions[] = "s.created_at >= :date_from";
        $params[':date_from'] = $dateFrom;
    }
}

if ($dateToRaw !== '') {
    $dateToObj = DateTime::createFromFormat('Y-m-d', $dateToRaw);
    if ($dateToObj !== false) {
        $dateToForInput = $dateToObj->format('Y-m-d');
        $dateTo = $dateToObj->setTime(0, 0, 0)->modify('+1 day')->format('Y-m-d H:i:s');
        $conditions[] = "s.created_at < :date_to";
        $params[':date_to'] = $dateTo;
    }
}

$baseQuery = "
    FROM sales s
    LEFT JOIN members m ON m.id = s.member_id
";

if ($conditions) {
    $baseQuery .= ' WHERE ' . implode(' AND ', $conditions);
}

$countStmt = $pdo->prepare("SELECT COUNT(*) " . $baseQuery);
foreach ($params as $key => $value) {
    $countStmt->bindValue($key, $value);
}
$countStmt->execute();
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));

if ($pageNumber > $totalPages) {
    $pageNumber = $totalPages;
    $offset = ($pageNumber - 1) * $perPage;
}

$listQuery = "
    SELECT s.id, s.invoice_code, s.grand_total, s.payment_method, s.created_at,
           m.name AS member_name, m.member_code
    " . $baseQuery . "
    ORDER BY s.created_at DESC
    LIMIT :limit OFFSET :offset
";

$listStmt = $pdo->prepare($listQuery);
foreach ($params as $key => $value) {
    $listStmt->bindValue($key, $value);
}
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$invoices = $listStmt->fetchAll();

$firstRow = $totalRows === 0 ? 0 : $offset + 1;
$lastRow = min($offset + $perPage, $totalRows);

?>

<section class="card">
    <h2>Pencarian Invoice</h2>
    <p class="muted">Gunakan filter berikut untuk menemukan invoice dengan cepat tanpa membebani halaman kasir.</p>

    <form class="filter-form" method="get" action="<?= BASE_URL ?>/index.php" style="margin-bottom:1rem;">
        <input type="hidden" name="page" value="invoices">
        <div class="grid-3">
            <div class="form-group">
                <label for="invoice_search">Cari Invoice / Member</label>
                <input type="text" id="invoice_search" name="search" value="<?= sanitize($searchTerm) ?>" placeholder="kode invoice / nama member">
            </div>
            <div class="form-group">
                <label for="invoice_date_from">Dari Tanggal</label>
                <input type="date" id="invoice_date_from" name="date_from" value="<?= sanitize($dateFromForInput) ?>">
            </div>
            <div class="form-group">
                <label for="invoice_date_to">Sampai Tanggal</label>
                <input type="date" id="invoice_date_to" name="date_to" value="<?= sanitize($dateToForInput) ?>">
            </div>
        </div>
        <div class="form-actions" style="display:flex; gap:0.75rem; flex-wrap:wrap;">
            <button class="button" type="submit">Terapkan Filter</button>
            <a class="button secondary" href="<?= BASE_URL ?>/index.php?page=invoices">Reset</a>
        </div>
    </form>

    <div class="muted" style="margin-bottom:1rem;">
        Menampilkan <?= $firstRow ?> - <?= $lastRow ?> dari <?= $totalRows ?> invoice.
    </div>

    <table class="table table-stack table-stack--compact">
        <thead>
        <tr>
            <th>Invoice</th>
            <th>Tanggal</th>
            <th>Member / Pelanggan</th>
            <th>Metode</th>
            <th>Total</th>
            <th>Aksi</th>
        </tr>
        </thead>
        <tbody>
        <?php if (!$invoices): ?>
            <tr class="empty-row">
                <td colspan="6" class="muted" style="text-align:center;">Tidak ada data yang sesuai.</td>
            </tr>
        <?php else: ?>
            <?php foreach ($invoices as $invoice): ?>
                <tr>
                    <td data-label="Invoice"><?= sanitize($invoice['invoice_code']) ?></td>
                    <td data-label="Tanggal"><?= format_date($invoice['created_at'], true) ?></td>
                    <td data-label="Member / Pelanggan">
                        <?php if (!empty($invoice['member_name'])): ?>
                            <?= sanitize($invoice['member_name']) ?> (<?= sanitize($invoice['member_code']) ?>)
                        <?php else: ?>
                            <span class="muted">Umum</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Metode"><?= strtoupper($invoice['payment_method']) ?></td>
                    <td data-label="Total"><?= format_rupiah((float) $invoice['grand_total']) ?></td>
                    <td class="table-actions" data-label="Aksi">
                        <a class="button secondary" rel="noopener" href="<?= BASE_URL ?>/actions/print_struk.php?sale_id=<?= (int) $invoice['id'] ?>">Cetak / Detail</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ($totalPages > 1): ?>
        <nav class="pagination" aria-label="Navigasi halaman" style="margin-top:1rem; display:flex; gap:0.5rem; flex-wrap:wrap;">
            <?php
            $queryBase = $_GET;
            $queryBase['page'] = 'invoices';
            ?>
            <?php if ($pageNumber > 1): ?>
                <?php $queryBase['p'] = $pageNumber - 1; ?>
                <a class="button secondary" href="<?= BASE_URL ?>/index.php?<?= http_build_query($queryBase) ?>">Sebelumnya</a>
            <?php endif; ?>
            <span class="muted" style="align-self:center;">Halaman <?= $pageNumber ?> dari <?= $totalPages ?></span>
            <?php if ($pageNumber < $totalPages): ?>
                <?php $queryBase['p'] = $pageNumber + 1; ?>
                <a class="button secondary" href="<?= BASE_URL ?>/index.php?<?= http_build_query($queryBase) ?>">Berikutnya</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</section>
