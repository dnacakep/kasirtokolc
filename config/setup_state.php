<?php

const SETUP_CONFIG_FILE = __DIR__ . '/local_config.php';

function setup_load_config(): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $cache = [];

    if (file_exists(SETUP_CONFIG_FILE)) {
        $data = include SETUP_CONFIG_FILE;
        if (is_array($data)) {
            $cache = $data;
        }
    }

    return $cache;
}

function setup_save_config(array $config): void
{
    $export = var_export($config, true);
    $contents = "<?php\nreturn " . $export . ";\n";
    file_put_contents(SETUP_CONFIG_FILE, $contents, LOCK_EX);
}

function setup_is_completed(): bool
{
    $config = setup_load_config();

    return !empty($config['installed'])
        && !empty($config['db']['database'])
        && !empty($config['db']['host']);
}

function setup_is_setup_request(): bool
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $uri = $_SERVER['REQUEST_URI'] ?? '';

    return str_contains($script, '/setup/') || str_contains($uri, '/setup/');
}

function setup_guess_base_url(): string
{
    $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $appRoot = realpath(__DIR__ . '/..') ?: '';

    $documentRoot = rtrim(str_replace('\\', '/', $documentRoot), '/');
    $appRoot = rtrim(str_replace('\\', '/', $appRoot), '/');

    if ($documentRoot && str_starts_with($appRoot, $documentRoot)) {
        $base = substr($appRoot, strlen($documentRoot));
        $base = $base === '' ? '' : '/' . ltrim($base, '/');
    } else {
        $base = '/kasirtokolc';
    }

    return rtrim($base, '/') ?: '';
}

function setup_base_url(): string
{
    $config = setup_load_config();
    $base = $config['base_url'] ?? getenv('BASE_URL') ?: setup_guess_base_url();
    $base = rtrim((string) $base, '/');

    return $base === '' ? '' : $base;
}

function setup_build_url(string $path = 'index.php'): string
{
    $base = setup_base_url();
    $trimmed = ltrim($path, '/');

    return $base . '/setup/' . $trimmed;
}

function setup_requires_wizard(): bool
{
    return !setup_is_completed();
}

function setup_redirect_if_needed(): void
{
    if (setup_is_setup_request()) {
        return;
    }

    if (!setup_requires_wizard()) {
        return;
    }

    header('Location: ' . setup_build_url());
    exit;
}

function setup_flash(string $type, string $message): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $_SESSION['setup_flash'] = [
        'type' => $type,
        'text' => $message,
    ];
}

function setup_consume_flash(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (!isset($_SESSION['setup_flash'])) {
        return null;
    }

    $flash = $_SESSION['setup_flash'];
    unset($_SESSION['setup_flash']);

    return $flash;
}

function setup_set_old_form(array $data): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $_SESSION['setup_old'] = $data;
}

function setup_old_form(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $old = $_SESSION['setup_old'] ?? [];
    unset($_SESSION['setup_old']);

    return is_array($old) ? $old : [];
}
