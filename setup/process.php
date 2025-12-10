<?php

require_once __DIR__ . '/../config/setup_state.php';
require_once __DIR__ . '/schema.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function setup_force_innodb(PDO $pdo, array $tables): void
{
    foreach ($tables as $table) {
        try {
            $pdo->exec("ALTER TABLE `{$table}` ENGINE=InnoDB");
        } catch (Throwable $e) {
            // Ignore if table does not exist or engine already correct.
        }
    }
}

function setup_drop_tables(PDO $pdo, array $tables): void
{
    foreach ($tables as $table) {
        try {
            $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        } catch (Throwable $e) {
            // Ignore and continue; intended for clean bootstrap only.
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$csrf = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['setup_csrf']) || !hash_equals($_SESSION['setup_csrf'], $csrf)) {
    setup_flash('error', 'Sesi tidak valid, silakan ulangi.');
    header('Location: index.php');
    exit;
}

$storeName = trim($_POST['store_name'] ?? '');
$storePhone = trim($_POST['store_phone'] ?? '');
$storeAddress = trim($_POST['store_address'] ?? '');

$baseUrl = trim($_POST['base_url'] ?? '');
$baseUrl = rtrim($baseUrl, '/');

$dbHost = trim($_POST['db_host'] ?? '');
$dbPort = (int) ($_POST['db_port'] ?? 3306);
$dbName = trim($_POST['db_name'] ?? '');
$dbUser = trim($_POST['db_username'] ?? '');
$dbPass = $_POST['db_password'] ?? '';
$dbCharset = trim($_POST['db_charset'] ?? 'utf8mb4');

$adminFullName = trim($_POST['admin_full_name'] ?? '');
$adminUsername = trim($_POST['admin_username'] ?? '');
$adminPassword = $_POST['admin_password'] ?? '';
$adminPasswordConfirm = $_POST['admin_password_confirm'] ?? '';

$oldPayload = [
    'store_name' => $storeName,
    'store_phone' => $storePhone,
    'store_address' => $storeAddress,
    'base_url' => $baseUrl,
    'db_host' => $dbHost,
    'db_port' => $dbPort,
    'db_name' => $dbName,
    'db_username' => $dbUser,
    'db_password' => $dbPass,
    'db_charset' => $dbCharset,
    'admin_full_name' => $adminFullName,
    'admin_username' => $adminUsername,
];

if ($storeName === '' || $dbHost === '' || $dbName === '' || $dbUser === '' || $adminFullName === '' || $adminUsername === '' || $adminPassword === '') {
    setup_set_old_form($oldPayload);
    setup_flash('error', 'Lengkapi semua data wajib.');
    header('Location: index.php');
    exit;
}

if ($adminPassword !== $adminPasswordConfirm) {
    setup_set_old_form($oldPayload);
    setup_flash('error', 'Kata sandi admin tidak sama.');
    header('Location: index.php');
    exit;
}

if (strlen($adminPassword) < 6) {
    setup_set_old_form($oldPayload);
    setup_flash('error', 'Kata sandi admin minimal 6 karakter.');
    header('Location: index.php');
    exit;
}

if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
    setup_set_old_form($oldPayload);
    setup_flash('error', 'Nama database hanya boleh huruf, angka, dan underscore.');
    header('Location: index.php');
    exit;
}

if (!preg_match('/^[A-Za-z0-9_\-]+$/', $adminUsername)) {
    setup_set_old_form($oldPayload);
    setup_flash('error', 'Username admin hanya boleh huruf, angka, strip, atau underscore.');
    header('Location: index.php');
    exit;
}

$dbCharset = preg_match('/^[A-Za-z0-9_]+$/', $dbCharset) ? $dbCharset : 'utf8mb4';
$collation = $dbCharset === 'utf8mb4' ? 'utf8mb4_unicode_ci' : $dbCharset . '_general_ci';

try {
    $pdoOptions = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    $dsnServer = sprintf('mysql:host=%s;port=%d;charset=%s', $dbHost, $dbPort, $dbCharset);
    if ($dbPass === '') {
        $pdoServer = new PDO($dsnServer, $dbUser);
        foreach ($pdoOptions as $opt => $val) {
            $pdoServer->setAttribute($opt, $val);
        }
    } else {
        $pdoServer = new PDO($dsnServer, $dbUser, $dbPass, $pdoOptions);
    }

    $pdoServer->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET {$dbCharset} COLLATE {$collation}");

    $dsnDb = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $dbHost, $dbPort, $dbName, $dbCharset);
    if ($dbPass === '') {
        $pdo = new PDO($dsnDb, $dbUser);
        foreach ($pdoOptions as $opt => $val) {
            $pdo->setAttribute($opt, $val);
        }
    } else {
        $pdo = new PDO($dsnDb, $dbUser, $dbPass, $pdoOptions);
    }

    // Ensure key tables are InnoDB before applying foreign keys (handles existing installs with MyISAM).
    setup_force_innodb($pdo, [
        'users',
        'members',
        'categories',
        'suppliers',
        'products',
        'product_batches',
    ]);

    // Temporarily disable FK checks to allow clean rebuild.
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

    // Drop potential existing tables that might conflict (fresh install path).
    setup_drop_tables($pdo, [
        'sale_items', 'sales', 'member_debt_payments', 'member_debts', 'member_points',
        'stock_adjustments', 'stock_adjustment_requests', 'expense_requests', 'cash_drawer_sessions',
        'promotions', 'expenses', 'product_conversions', 'product_batches', 'products',
        'categories', 'suppliers', 'members', 'auth_tokens', 'users', 'store_settings'
    ]);

    $created = false;
    try {
        foreach (setup_schema_statements() as $sql) {
            $pdo->exec($sql);
        }
        $created = true;
    } catch (Throwable $schemaError) {
        $msg = (string) $schemaError->getMessage();
        $isFkError = str_contains($msg, 'errno: 150') || str_contains($msg, 'foreign key') || str_contains($msg, 'FK') || str_contains($msg, '1005');

        // Retry with relaxed schema (no FKs) if FK errors occur.
        if ($isFkError) {
            setup_drop_tables($pdo, [
                'sale_items', 'sales', 'member_debt_payments', 'member_debts', 'member_points',
                'stock_adjustments', 'stock_adjustment_requests', 'expense_requests', 'cash_drawer_sessions',
                'promotions', 'expenses', 'product_conversions', 'product_batches', 'products',
                'categories', 'suppliers', 'members', 'auth_tokens', 'users', 'store_settings'
            ]);

            foreach (setup_schema_statements_relaxed() as $sql) {
                $pdo->exec($sql);
            }
            $created = true;
        } else {
            throw $schemaError;
        }
    }

    // Re-enable FK checks after creation.
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

    // Re-ensure InnoDB for all important tables post-creation.
    setup_force_innodb($pdo, [
        'users', 'members', 'categories', 'suppliers', 'products', 'product_batches',
        'product_conversions', 'stock_adjustments', 'sales', 'sale_items', 'member_debts', 'member_debt_payments',
        'member_points', 'expenses', 'promotions', 'store_settings', 'cash_drawer_sessions',
        'expense_requests', 'stock_adjustment_requests'
    ]);

    $pdo->exec("SET time_zone = '+07:00'");

    $adminHash = password_hash($adminPassword, PASSWORD_BCRYPT);

    $existingAdmin = $pdo->prepare("SELECT id FROM users WHERE username = :username LIMIT 1");
    $existingAdmin->execute([':username' => $adminUsername]);
    $targetAdminId = $existingAdmin->fetchColumn();

    if (!$targetAdminId) {
        $anyAdmin = $pdo->query("SELECT id FROM users WHERE role = 'adminsuper' LIMIT 1")->fetchColumn();
        if ($anyAdmin) {
            $targetAdminId = (int) $anyAdmin;
        }
    }

    if ($targetAdminId) {
        $pdo->prepare("UPDATE users SET username = :username, full_name = :full_name, role = 'adminsuper', password_hash = :password_hash, is_active = 1, updated_at = NOW() WHERE id = :id")
            ->execute([
                ':username' => $adminUsername,
                ':full_name' => $adminFullName,
                ':password_hash' => $adminHash,
                ':id' => $targetAdminId,
            ]);
    } else {
        $pdo->prepare("INSERT INTO users (username, full_name, role, password_hash, is_active, created_at, updated_at) VALUES (:username, :full_name, 'adminsuper', :password_hash, 1, NOW(), NOW())")
            ->execute([
                ':username' => $adminUsername,
                ':full_name' => $adminFullName,
                ':password_hash' => $adminHash,
            ]);
    }

    $stmt = $pdo->query("SELECT COUNT(*) FROM store_settings");
    $hasStore = (int) $stmt->fetchColumn() > 0;
    if (!$hasStore) {
        $pdo->prepare("INSERT INTO store_settings (store_name, address, phone, logo_path, notes, updated_at) VALUES (:name, :address, :phone, '', '', NOW())")->execute([
            ':name' => $storeName,
            ':address' => $storeAddress,
            ':phone' => $storePhone,
        ]);
    }

    $config = setup_load_config();
    $config['base_url'] = $baseUrl;
    $config['db'] = [
        'host' => $dbHost,
        'port' => $dbPort,
        'database' => $dbName,
        'username' => $dbUser,
        'password' => $dbPass,
        'charset' => $dbCharset,
    ];
    $config['store'] = [
        'name' => $storeName,
        'phone' => $storePhone,
        'address' => $storeAddress,
    ];
    $config['admin_username'] = $adminUsername;
    $config['installed'] = true;
    $config['installed_at'] = date(DATE_ATOM);

    setup_save_config($config);

    unset($_SESSION['setup_csrf']);
    setup_set_old_form([]);

    setup_flash('success', 'Instalasi selesai. Silakan masuk dengan akun admin.');
    $_SESSION['flash_message'] = [
        'type' => 'success',
        'text' => 'Instalasi selesai. Masuk dengan akun admin yang baru dibuat.',
    ];
    $target = ($baseUrl === '' ? '' : $baseUrl) . '/pages/login.php';
    header('Location: ' . $target);
    exit;
} catch (Throwable $e) {
    setup_set_old_form($oldPayload);
    setup_flash('error', 'Gagal menyimpan konfigurasi: ' . $e->getMessage());
    header('Location: index.php');
    exit;
}
