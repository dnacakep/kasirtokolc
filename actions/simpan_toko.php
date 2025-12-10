<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';

require_role(ROLE_MANAJER);
guard_post();
verify_csrf_token($_POST['csrf_token'] ?? '');

$pdo = get_db_connection();

$pdo->exec("CREATE TABLE IF NOT EXISTS store_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    store_name VARCHAR(150) NOT NULL,
    address TEXT NULL,
    phone VARCHAR(40) NULL,
    logo_path VARCHAR(255) NULL,
    notes TEXT NULL,
    updated_at DATETIME NOT NULL
)");

$storeName = trim($_POST['store_name'] ?? '');
$address = trim($_POST['address'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$notes = trim($_POST['notes'] ?? '');
$removeLogo = isset($_POST['remove_logo']) && $_POST['remove_logo'] === '1';

if ($storeName === '') {
    redirect_with_message('/index.php?page=toko', 'Nama toko wajib diisi.', 'error');
}

$settings = get_store_settings();
$logoPath = $settings['logo_path'] ?? '';

if (!empty($_FILES['logo']['tmp_name']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
    $extension = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, ['png', 'jpg', 'jpeg'])) {
        redirect_with_message('/index.php?page=toko', 'Format logo harus PNG atau JPG.', 'error');
    }

    $filename = 'store-logo-' . time() . '.' . ($extension === 'jpeg' ? 'jpg' : $extension);
    $destinationDir = realpath(__DIR__ . '/../assets/images');
    if ($destinationDir === false) {
        redirect_with_message('/index.php?page=toko', 'Folder logo tidak ditemukan.', 'error');
    }

    $destination = $destinationDir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($_FILES['logo']['tmp_name'], $destination)) {
        redirect_with_message('/index.php?page=toko', 'Gagal mengunggah logo.', 'error');
    }

    if ($logoPath) {
        $oldPath = realpath(__DIR__ . '/../' . $logoPath);
        if ($oldPath && file_exists($oldPath)) {
            @unlink($oldPath);
        }
    }

    $logoPath = 'assets/images/' . $filename;
}

if ($removeLogo && $logoPath) {
    $oldPath = realpath(__DIR__ . '/../' . $logoPath);
    if ($oldPath && file_exists($oldPath)) {
        @unlink($oldPath);
    }
    $logoPath = '';
}

$exists = !empty($settings['id']);

if ($exists) {
    $stmt = $pdo->prepare("
        UPDATE store_settings
        SET store_name = :store_name,
            address = :address,
            phone = :phone,
            logo_path = :logo_path,
            notes = :notes,
            updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':store_name' => $storeName,
        ':address' => $address,
        ':phone' => $phone,
        ':logo_path' => $logoPath,
        ':notes' => $notes,
        ':id' => $settings['id'],
    ]);
} else {
    $stmt = $pdo->prepare("
        INSERT INTO store_settings (store_name, address, phone, logo_path, notes, updated_at)
        VALUES (:store_name, :address, :phone, :logo_path, :notes, NOW())
    ");
    $stmt->execute([
        ':store_name' => $storeName,
        ':address' => $address,
        ':phone' => $phone,
        ':logo_path' => $logoPath,
        ':notes' => $notes,
    ]);
}

redirect_with_message('/index.php?page=toko', 'Pengaturan toko berhasil disimpan.');
