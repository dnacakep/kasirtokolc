<?php

declare(strict_types=1);

const APP_ERROR_LOG_DIR = __DIR__ . '/../storage/logs';
const APP_ERROR_LOG_FILE = APP_ERROR_LOG_DIR . '/app.log';

/**
 * Bootstraps the custom error handling stack once per request.
 */
function app_initialize_error_handling(): void
{
    if (defined('APP_ERROR_HANDLING_READY')) {
        return;
    }

    error_reporting(E_ALL);
    ini_set('display_errors', APP_DEBUG ? '1' : '0');

    if (!is_dir(APP_ERROR_LOG_DIR)) {
        mkdir(APP_ERROR_LOG_DIR, 0775, true);
    }

    set_error_handler('app_handle_php_error');
    set_exception_handler('app_handle_uncaught_exception');
    register_shutdown_function('app_handle_shutdown');

    define('APP_ERROR_HANDLING_READY', true);
}

/**
 * Converts PHP errors into ErrorException instances.
 */
function app_handle_php_error(int $severity, string $message, string $file, int $line): bool
{
    if (!(error_reporting() & $severity)) {
        return false;
    }

    $exception = new ErrorException($message, 0, $severity, $file, $line);
    app_handle_uncaught_exception($exception);
    return true;
}

/**
 * Handles uncaught exceptions with logging and friendly output.
 *
 * @param Throwable $throwable The uncaught error or exception.
 */
function app_handle_uncaught_exception(Throwable $throwable): void
{
    static $handling = false;
    if ($handling) {
        // Avoid infinite recursion if the handler itself fails.
        exit(1);
    }
    $handling = true;

    $errorId = app_generate_error_id();

    try {
        app_log_error($throwable, $errorId);
    } catch (Throwable $loggingError) {
        // Last resort: if logging to file fails, use the system logger.
        error_log('CRITICAL: Application logger failed. Reason: ' . $loggingError->getMessage());
        error_log('Original Error (' . $errorId . '): ' . $throwable->getMessage());
    }

    app_render_error_response($throwable, $errorId);

    exit(1);
}

/**
 * Handles fatal errors that are only available during shutdown.
 */
function app_handle_shutdown(): void
{
    $error = error_get_last();
    if (
        $error !== null
        && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)
    ) {
        $exception = new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
        app_handle_uncaught_exception($exception);
    }
}

/**
 * Writes an error entry to the log file.
 */
function app_log_error(Throwable $throwable, string $errorId): void
{
    $context = app_collect_error_context($errorId);

    $entry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'error_id' => $errorId,
        'type' => get_class($throwable),
        'message' => $throwable->getMessage(),
        'file' => $throwable->getFile(),
        'line' => $throwable->getLine(),
        'trace' => explode("\n", $throwable->getTraceAsString()),
        'context' => $context,
    ];

    $payload = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        $payload = sprintf(
            '{"timestamp":"%s","error_id":"%s","type":"%s","message":"%s"}',
            $entry['timestamp'],
            $errorId,
            $entry['type'],
            $entry['message']
        );
    }

    file_put_contents(APP_ERROR_LOG_FILE, $payload . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/**
 * Collects useful request/session context while redacting sensitive data.
 */
function app_collect_error_context(string $errorId): array
{
    $server = [
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? null,
        'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
        'referer' => $_SERVER['HTTP_REFERER'] ?? null,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        'script' => $_SERVER['SCRIPT_NAME'] ?? null,
    ];

    $data = [
        'error_id' => $errorId,
        'server' => array_filter($server),
        'get' => app_redact_sensitive($_GET ?? []),
        'post' => app_redact_sensitive($_POST ?? []),
        'session' => [],
        'user' => null,
    ];

    if (session_status() === PHP_SESSION_ACTIVE) {
        $data['session'] = app_redact_sensitive($_SESSION);
        $data['user'] = $data['session']['user'] ?? null;
    }

    return $data;
}

/**
 * Redacts sensitive fields from an array recursively.
 *
 * @param mixed $value
 * @return mixed
 */
function app_redact_sensitive($value)
{
    $sensitiveKeys = ['password', 'password_confirmation', 'current_password', 'token', 'secret'];

    if (is_array($value)) {
        $sanitized = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array(strtolower($key), $sensitiveKeys, true)) {
                $sanitized[$key] = '[redacted]';
                continue;
            }
            $sanitized[$key] = app_redact_sensitive($item);
        }
        return $sanitized;
    }

    if (is_string($value) && strlen($value) > 256) {
        return substr($value, 0, 256) . '…';
    }

    return $value;
}

/**
 * Generates a short error identifier to share with support.
 */
function app_generate_error_id(): string
{
    try {
        $random = strtoupper(bin2hex(random_bytes(4)));
    } catch (Throwable $e) {
        $random = strtoupper(substr(uniqid('', true), -8));
    }

    return date('YmdHis') . '-' . $random;
}

/**
 * Renders a useful error response for CLI or browser usage.
 */
function app_render_error_response(Throwable $throwable, string $errorId): void
{
    if (PHP_SAPI === 'cli') {
        $message = APP_DEBUG
            ? sprintf("[%s] %s in %s:%d\n%s\n\n", $errorId, $throwable->getMessage(), $throwable->getFile(), $throwable->getLine(), $throwable->getTraceAsString())
            : sprintf("Terjadi kesalahan tak terduga. Kode error: %s\n", $errorId);
        file_put_contents('php://stderr', $message);
        return;
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }

    if (APP_DEBUG) {
        echo '<h1>Terjadi Kesalahan</h1>';
        echo '<p><strong>Kode Error:</strong> ' . htmlspecialchars($errorId, ENT_QUOTES, 'UTF-8') . '</p>';
        echo '<p><strong>Pesan:</strong> ' . htmlspecialchars($throwable->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
        echo '<p><strong>File:</strong> ' . htmlspecialchars($throwable->getFile(), ENT_QUOTES, 'UTF-8') . '</p>';
        echo '<p><strong>Baris:</strong> ' . (int) $throwable->getLine() . '</p>';
        echo '<h2>Trace:</h2>';
        echo '<pre>' . htmlspecialchars($throwable->getTraceAsString(), ENT_QUOTES, 'UTF-8') . '</pre>';
        return;
    }

    echo '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><title>Terjadi Kesalahan</title>';
    echo '<style>body{font-family:system-ui, sans-serif;background:#f6f8fb;color:#1f2937;padding:3rem;}';
    echo '.card{max-width:420px;margin:auto;background:#fff;border-radius:12px;padding:2rem;box-shadow:0 15px 35px rgba(15,23,42,0.12);}';
    echo '.card h1{margin-top:0;font-size:1.5rem;}';
    echo '.card p{margin:0.75rem 0;}';
    echo '.chip{display:inline-block;padding:0.35rem 0.6rem;border-radius:999px;background:#eef2ff;color:#3730a3;font-weight:600;font-size:0.9rem;letter-spacing:0.03em;}';
    echo '</style></head><body><div class="card">';
    echo '<h1>Oops! Terjadi Kesalahan</h1>';
    echo '<p>Aplikasi mengalami gangguan tak terduga. Silakan catat kode error berikut untuk bantuan lebih lanjut:</p>';
    echo '<p class="chip">' . htmlspecialchars($errorId, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<p>Bagikan kode error ini saat meminta bantuan agar kami dapat melacak masalahnya.</p>';
    echo '</div></body></html>';
}

