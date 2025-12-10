<?php

$user = current_user();

$navigation = [
    [
        'label' => 'Dashboard',
        'page' => 'dashboard',
    ],
    [
        'label' => 'Kasir & Penjualan',
        'children' => [
            ['label' => 'Transaksi Kasir', 'page' => 'transaksi'],
            ['label' => 'Pencarian Invoice', 'page' => 'invoices'],
            ['label' => 'Member', 'page' => 'member'],
        ],
    ],
    [
        'label' => 'Barang & Stok',
        'children' => [
            ['label' => 'Tambah Barang', 'page' => 'barang'],
            ['label' => 'Daftar Barang', 'page' => 'barang_list'],
            ['label' => 'Kategori', 'page' => 'kategori'],
            ['label' => 'Stok Masuk', 'page' => 'stok_masuk'],
            ['label' => 'Penyesuaian Stok', 'page' => 'stok_penyesuaian'],
            ['label' => 'Riwayat Stok', 'page' => 'stok_riwayat'],
            ['label' => 'Label Harga', 'page' => 'label_harga'],
            ['label' => 'Performa Barang', 'page' => 'performa_barang'],
            ['label' => 'Pemasok', 'page' => 'pemasok'],
            ['label' => 'Promo & Diskon', 'page' => 'promo', 'hidden_for' => [ROLE_KASIR]],
        ],
    ],
    [
        'label' => 'Keuangan',
        'children' => [
            ['label' => 'Pengeluaran', 'page' => 'pengeluaran'],
            ['label' => 'Laporan', 'page' => 'laporan'],
            ['label' => 'Hutang Member', 'page' => 'hutang_member'],
        ],
    ],
    [
        'label' => 'Pengaturan',
        'children' => [
            ['label' => 'Notifikasi', 'page' => 'notifikasi'],
            ['label' => 'Pengaturan Toko', 'page' => 'toko', 'hidden_for' => [ROLE_KASIR]],
            ['label' => 'Pengguna', 'page' => 'user', 'hidden_for' => [ROLE_KASIR]],
        ],
    ],
];

$canView = function (array $item) use ($user): bool {
    if (isset($item['hidden_for']) && in_array($user['role'], (array) $item['hidden_for'], true)) {
        return false;
    }
    if (isset($item['roles']) && !in_array($user['role'], (array) $item['roles'], true)) {
        return false;
    }
    return true;
};

$isActive = function (array $item) use (&$isActive, $currentPage): bool {
    if (isset($item['page'])) {
        return $currentPage === $item['page'];
    }
    if (!empty($item['children'])) {
        foreach ($item['children'] as $child) {
            if ($isActive($child)) {
                return true;
            }
        }
    }
    return false;
};

?>
<aside class="sidebar" id="sidebar-nav">
    <h2><?= APP_NAME ?></h2>
    <p class="muted"><?= sanitize($user['full_name']) ?> &middot; <?= get_role_label($user['role']) ?></p>
    <nav class="sidebar-nav">
        <?php foreach ($navigation as $section): ?>
            <?php
                if (!empty($section['children'])) {
                    $visibleChildren = [];
                    foreach ($section['children'] as $child) {
                        if ($canView($child)) {
                            $visibleChildren[] = $child;
                        }
                    }
                    if (!$visibleChildren) {
                        continue;
                    }
                    $sectionActive = $isActive($section);
            ?>
                <div class="sidebar-group">
                    <p class="sidebar-group-title<?= $sectionActive ? ' active' : '' ?>"><?= sanitize($section['label']) ?></p>
                    <ul class="sidebar-group-links">
                        <?php foreach ($visibleChildren as $child): ?>
                            <li>
                                <a class="<?= active_nav($currentPage, $child['page']) ?>"
                                   href="<?= BASE_URL ?>/index.php?page=<?= $child['page'] ?>">
                                    <?= sanitize($child['label']) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php
                    continue;
                }

                if (!$canView($section)) {
                    continue;
                }
            ?>
            <div class="sidebar-group sidebar-group--single">
                <a class="<?= active_nav($currentPage, $section['page']) ?>" href="<?= BASE_URL ?>/index.php?page=<?= $section['page'] ?>">
                    <?= sanitize($section['label']) ?>
                </a>
            </div>
        <?php endforeach; ?>
        <div class="sidebar-group sidebar-group--footer">
            <a class="sidebar-logout" href="<?= BASE_URL ?>/pages/logout.php">Keluar</a>
        </div>
    </nav>
</aside>
