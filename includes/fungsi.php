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

function store_product_image(array $file, ?string $previousPath = null): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return $previousPath;
    }

    if (empty($file['tmp_name']) || !is_readable($file['tmp_name'])) {
        throw new RuntimeException('File foto tidak valid atau tidak dapat dibaca.');
    }

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

    $squareImage = crop_to_square($sourceImage, $cropX, $cropY, $cropSize, $mimeType);
    imagedestroy($sourceImage);

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

    imagecopyresampled(
        $resizedImage,
        $squareImage,
        0,
        0,
        0,
        0,
        $targetSize,
        $targetSize,
        imagesx($squareImage),
        imagesy($squareImage)
    );
    imagedestroy($squareImage);

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
