<?php

require_once __DIR__ . '/../config/db.php';

function format_rupiah(float $value): string
{
    return 'Rp' . number_format($value, 0, ',', '.');
}

function format_date(?string $date, bool $withTime = false): string
{
    if (!$date) {
        return '-';
    }

    $format = $withTime ? 'd M Y H:i' : 'd M Y';
    return date($format, strtotime($date));
}

function redirect_with_message(string $path, string $message, string $type = 'success'): void
{
    $_SESSION['flash_message'] = [
        'type' => $type,
        'text' => $message,
    ];
    header('Location: ' . BASE_URL . $path);
    exit;
}

function consume_flash_message(): ?array
{
    if (!isset($_SESSION['flash_message'])) {
        return null;
    }

    $message = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);

    return $message;
}

function get_role_label(string $role): string
{
    switch ($role) {
        case ROLE_ADMIN:
            return 'Admin Super';
        case ROLE_MANAJER:
            return 'Manajer';
        default:
            return 'Kasir';
    }
}

function fetch_dashboard_summary(PDO $pdo): array
{
    $result = [
        'total_sales_today' => 0,
        'total_transactions_today' => 0,
        'active_members' => 0,
        'stock_value' => 0,
    ];

    $statement = $pdo->query("SELECT COALESCE(SUM(grand_total),0) AS total, COUNT(*) AS jumlah FROM sales WHERE DATE(created_at) = CURDATE()");
    if ($row = $statement->fetch()) {
        $result['total_sales_today'] = (float) $row['total'];
        $result['total_transactions_today'] = (int) $row['jumlah'];
    }

    $statement = $pdo->query("SELECT COUNT(*) AS jumlah FROM members WHERE status = 'active'");
    if ($row = $statement->fetch()) {
        $result['active_members'] = (int) $row['jumlah'];
    }

    $statement = $pdo->query("SELECT COALESCE(SUM(stock_remaining * purchase_price),0) AS total FROM product_batches");
    if ($row = $statement->fetch()) {
        $result['stock_value'] = (float) $row['total'];
    }

    return $result;
}

function fetch_low_stock_items_v2(PDO $pdo): array
{
    $sql = "SELECT p.id, p.name, p.stock_minimum, COALESCE(SUM(b.stock_remaining), 0) AS stock_total, c.name AS category_name
            FROM products p
            LEFT JOIN product_batches b ON b.product_id = p.id
            LEFT JOIN categories c ON c.id = p.category_id
            WHERE p.is_active = 1
            GROUP BY p.id, p.name, p.stock_minimum, c.name
            HAVING stock_total <= p.stock_minimum
            ORDER BY c.name ASC, p.name ASC";
    return $pdo->query($sql)->fetchAll();
}

function fetch_expiring_items(PDO $pdo, ?int $limit = null): array
{
    $sql = "SELECT p.name, b.batch_code, b.expiry_date, b.stock_remaining
            FROM product_batches b
            INNER JOIN products p ON p.id = b.product_id
            WHERE b.expiry_date IS NOT NULL
              AND b.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
              AND b.stock_remaining > 0
            ORDER BY b.expiry_date ASC";

    if ($limit !== null && $limit > 0) {
        $sql .= ' LIMIT ' . (int) $limit;
    }

    return $pdo->query($sql)->fetchAll();
}

function fetch_pending_labels(PDO $pdo): array
{
    $sql = "SELECT DISTINCT p.id, p.name, b.sell_price
            FROM product_batches b
            INNER JOIN products p ON p.id = b.product_id
            WHERE b.label_printed = 0
            ORDER BY p.name ASC
            LIMIT 5";
    return $pdo->query($sql)->fetchAll();
}

function active_nav(string $page, string $target): string
{
    return $page === $target ? 'active' : '';
}

function guard_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo 'Method Not Allowed';
        exit;
    }
}

function sanitize(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function get_store_settings(): array
{
    static $settings = null;

    if ($settings !== null) {
        return $settings;
    }

    try {
        $pdo = get_db_connection();
        $stmt = $pdo->query("SELECT * FROM store_settings ORDER BY id ASC LIMIT 1");
        $settings = $stmt->fetch();
    } catch (Throwable $e) {
        $settings = null;
    }

    if (!$settings) {
        $settings = [
            'id' => null,
            'store_name' => APP_NAME,
            'address' => '',
            'phone' => '',
            'logo_path' => '',
            'notes' => '',
            'updated_at' => null,
        ];
    }

    return $settings;
}

function set_last_sale_summary(array $sale): void
{
    $_SESSION['last_sale_summary'] = $sale;
}

function consume_last_sale_summary(): ?array
{
    if (!isset($_SESSION['last_sale_summary'])) {
        return null;
    }

    $summary = $_SESSION['last_sale_summary'];
    unset($_SESSION['last_sale_summary']);

    return $summary;
}

function ensure_product_image_support(PDO $pdo): void
{
    static $checked = false;

    if ($checked) {
        return;
    }

    $checked = true;

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'products'
              AND COLUMN_NAME = 'image_path'
        ");
        $stmt->execute();
        $exists = (int) $stmt->fetchColumn() > 0;

        if (!$exists) {
            $pdo->exec("ALTER TABLE products ADD COLUMN image_path VARCHAR(255) DEFAULT NULL AFTER description");
        }
    } catch (Throwable $e) {
        // Jika gagal menambah kolom, biarkan tanpa melempar error agar fitur lain tetap berjalan.
    }
}

function db_column_exists(PDO $pdo, string $tableName, string $columnName): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND COLUMN_NAME = :column_name
        ");
        $stmt->execute([
            ':table_name' => $tableName,
            ':column_name' => $columnName,
        ]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function db_table_exists(PDO $pdo, string $tableName): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
        ");
        $stmt->execute([':table_name' => $tableName]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function ensure_tiered_prices_schema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'tiered_prices'
        ");
        $stmt->execute();
        $exists = (int) $stmt->fetchColumn() > 0;
        if ($exists) {
            return;
        }

        $pdo->exec("
            CREATE TABLE tiered_prices (
                id INT(11) NOT NULL AUTO_INCREMENT,
                product_id INT(11) NOT NULL,
                min_qty INT(11) NOT NULL,
                price DECIMAL(12,2) NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_tiered_product_qty (product_id, min_qty)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        // Jika gagal (privilege terbatas, dll), biarkan; fitur tiered pricing dianggap nonaktif.
    }
}

function fetch_tiered_prices(PDO $pdo, int $productId): array
{
    ensure_tiered_prices_schema($pdo);

    try {
        $stmt = $pdo->prepare("SELECT min_qty, price FROM tiered_prices WHERE product_id = :id ORDER BY min_qty ASC");
        $stmt->execute([':id' => $productId]);
        $rows = $stmt->fetchAll();
        return array_map(static function ($row) {
            return [
                'min_qty' => (int) ($row['min_qty'] ?? 0),
                'price' => (float) ($row['price'] ?? 0),
            ];
        }, $rows ?: []);
    } catch (Throwable $e) {
        return [];
    }
}

function resolve_tiered_price(array $tiers, float $qty, float $defaultPrice): float
{
    $qty = (float) $qty;
    if ($qty <= 0) {
        return $defaultPrice;
    }

    $best = null;
    foreach ($tiers as $tier) {
        $minQty = (int) ($tier['min_qty'] ?? 0);
        $price = (float) ($tier['price'] ?? 0);
        if ($minQty > 0 && $price > 0 && $qty >= $minQty) {
            $best = $price;
        }
    }

    return $best !== null ? (float) $best : (float) $defaultPrice;
}

function resolve_bundle_pricing(array $tiers, float $qty, float $unitPrice): array
{
    $qty = (int) floor((float) $qty);
    $unitPrice = (float) $unitPrice;
    if ($qty <= 0 || $unitPrice < 0) {
        return [
            'total' => 0.0,
            'bundle_qty' => 0,
            'bundle_price' => 0.0,
            'bundle_count' => 0,
            'remainder_qty' => 0,
            'unit_price' => $unitPrice,
        ];
    }

    // Treat tiers as bundle rules: min_qty = bundle_qty, price = bundle_total_price.
    $bundleQty = 0;
    $bundlePrice = 0.0;
    foreach ($tiers as $tier) {
        $q = (int) ($tier['min_qty'] ?? 0);
        $p = (float) ($tier['price'] ?? 0);
        if ($q > $bundleQty && $p > 0) {
            $bundleQty = $q;
            $bundlePrice = $p;
        }
    }

    if ($bundleQty <= 0 || $bundlePrice <= 0) {
        return [
            'total' => $qty * $unitPrice,
            'bundle_qty' => 0,
            'bundle_price' => 0.0,
            'bundle_count' => 0,
            'remainder_qty' => $qty,
            'unit_price' => $unitPrice,
        ];
    }

    $bundleCount = intdiv($qty, $bundleQty);
    $remainder = $qty % $bundleQty;
    $total = ($bundleCount * $bundlePrice) + ($remainder * $unitPrice);

    return [
        'total' => (float) $total,
        'bundle_qty' => (int) $bundleQty,
        'bundle_price' => (float) $bundlePrice,
        'bundle_count' => (int) $bundleCount,
        'remainder_qty' => (int) $remainder,
        'unit_price' => (float) $unitPrice,
    ];
}

function store_product_image(array $file, ?string $previousPath = null): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return $previousPath;
    }

    if (empty($file['tmp_name']) || !is_readable($file['tmp_name'])) {
        throw new RuntimeException('File foto tidak valid atau tidak dapat dibaca.');
    }

    // Tingkatkan memory limit untuk pengolahan gambar besar
    @ini_set('memory_limit', '256M');

    $maxUploadSize = 10 * 1024 * 1024; // 10 MB, kamera HP sering menghasilkan file besar sebelum dikompresi ulang.
    if (($file['size'] ?? 0) > $maxUploadSize) {
        throw new RuntimeException('Ukuran foto terlalu besar. Maksimal 10 MB.');
    }

    if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor')) {
        throw new RuntimeException('Pengolah gambar GD tidak tersedia di server.');
    }

    $allowedMime = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    $mimeType = null;
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']) ?: null;
    }

    if (!$mimeType) {
        $mimeType = mime_content_type_fallback($file['tmp_name']) ?: $file['type'] ?? null;
    }

    if (!isset($allowedMime[$mimeType])) {
        throw new RuntimeException('Format foto tidak didukung. Gunakan JPG, PNG, atau WEBP.');
    }

    $sourceImage = create_image_from_path($file['tmp_name'], $mimeType);
    if (!$sourceImage) {
        throw new RuntimeException('Gagal memproses foto yang diunggah.');
    }

    $width = imagesx($sourceImage);
    $height = imagesy($sourceImage);

    if ($width <= 0 || $height <= 0) {
        imagedestroy($sourceImage);
        throw new RuntimeException('Dimensi foto tidak valid.');
    }

    $cropSize = min($width, $height);
    $cropX = (int) max(0, floor(($width - $cropSize) / 2));
    $cropY = (int) max(0, floor(($height - $cropSize) / 2));

    $targetSize = 500;
    $resizedImage = imagecreatetruecolor($targetSize, $targetSize);

    if (in_array($mimeType, ['image/png', 'image/webp'], true)) {
        imagealphablending($resizedImage, false);
        imagesavealpha($resizedImage, true);
        $transparent = imagecolorallocatealpha($resizedImage, 0, 0, 0, 127);
        imagefill($resizedImage, 0, 0, $transparent);
    } else {
        $white = imagecolorallocate($resizedImage, 255, 255, 255);
        imagefill($resizedImage, 0, 0, $white);
    }

    // Crop dan resize langsung dari sourceImage untuk hemat memory
    imagecopyresampled(
        $resizedImage,
        $sourceImage,
        0,
        0,
        $cropX,
        $cropY,
        $targetSize,
        $targetSize,
        $cropSize,
        $cropSize
    );
    imagedestroy($sourceImage);

    $destinationDir = __DIR__ . '/../storage/products';
    if (!is_dir($destinationDir)) {
        if (!mkdir($destinationDir, 0775, true) && !is_dir($destinationDir)) {
            imagedestroy($resizedImage);
            throw new RuntimeException('Gagal menyiapkan folder untuk foto produk.');
        }
    }

    $basename = 'product_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
    $extension = $allowedMime[$mimeType];
    $filename = $basename . '.' . $extension;
    $targetPath = $destinationDir . '/' . $filename;

    $saved = save_image_to_path($resizedImage, $targetPath, $mimeType);
    imagedestroy($resizedImage);

    if (!$saved) {
        throw new RuntimeException('Gagal menyimpan foto produk.');
    }

    if ($previousPath) {
        remove_product_image($previousPath);
    }

    return 'storage/products/' . $filename;
}

function create_image_from_path(string $path, string $mimeType)
{
    switch ($mimeType) {
        case 'image/jpeg':
            return imagecreatefromjpeg($path);
        case 'image/png':
            return imagecreatefrompng($path);
        case 'image/webp':
            if (!function_exists('imagecreatefromwebp')) {
                throw new RuntimeException('Server tidak mendukung WEBP.');
            }
            return imagecreatefromwebp($path);
        default:
            return null;
    }
}

function crop_to_square($sourceImage, int $x, int $y, int $size, string $mimeType)
{
    if ($size <= 0) {
        return false;
    }

    if (function_exists('imagecrop')) {
        $cropped = imagecrop($sourceImage, [
            'x' => $x,
            'y' => $y,
            'width' => $size,
            'height' => $size,
        ]);
        if ($cropped !== false) {
            return $cropped;
        }
    }

    $square = imagecreatetruecolor($size, $size);

    if (in_array($mimeType, ['image/png', 'image/webp'], true)) {
        imagealphablending($square, false);
        imagesavealpha($square, true);
        $transparent = imagecolorallocatealpha($square, 0, 0, 0, 127);
        imagefill($square, 0, 0, $transparent);
    }

    imagecopy(
        $square,
        $sourceImage,
        0,
        0,
        $x,
        $y,
        $size,
        $size
    );

    return $square;
}

function mime_content_type_fallback(string $path): ?string
{
    if (function_exists('mime_content_type')) {
        return @mime_content_type($path) ?: null;
    }

    $extensionMap = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return $extensionMap[$extension] ?? null;
}

function save_image_to_path($imageResource, string $path, string $mimeType): bool
{
    switch ($mimeType) {
        case 'image/jpeg':
            return imagejpeg($imageResource, $path, 85);
        case 'image/png':
            return imagepng($imageResource, $path, 6);
        case 'image/webp':
            if (!function_exists('imagewebp')) {
                throw new RuntimeException('Server tidak mendukung penyimpanan WEBP.');
            }
            return imagewebp($imageResource, $path, 85);
        default:
            return false;
    }
}

function remove_product_image(?string $path): void
{
    if (!$path) {
        return;
    }

    $fullPath = realpath(__DIR__ . '/../' . ltrim($path, '/'));
    $storageRoot = realpath(__DIR__ . '/../storage/products');

    if ($fullPath && $storageRoot && strpos($fullPath, $storageRoot) === 0 && is_file($fullPath)) {
        @unlink($fullPath);
    }
}

function ensure_csrf_token(): void
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

function verify_csrf_token(string $token): void
{
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(419);
        redirect_with_message('/pages/login.php', 'Sesi tidak valid, silakan coba lagi.', 'error');
    }
}
