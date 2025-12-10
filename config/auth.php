<?php

require_once __DIR__ . '/app_config.php';
require_once __DIR__ . '/db.php';

setup_redirect_if_needed();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function login_via_cookie(): bool
{
    if (isset($_SESSION['user'])) {
        return true;
    }

    if (!isset($_COOKIE['remember_me'])) {
        return false;
    }

    $cookie = $_COOKIE['remember_me'];
    list($selector, $validator) = explode(':', $cookie, 2);

    if (!$selector || !$validator) {
        return false;
    }

    $pdo = get_db_connection();
    $stmt = $pdo->prepare('SELECT * FROM auth_tokens WHERE selector = :selector AND expires_at >= NOW() LIMIT 1');
    $stmt->execute([':selector' => $selector]);
    $token = $stmt->fetch();

    if (!$token) {
        // Hapus cookie jika selector tidak valid
        setcookie('remember_me', '', time() - 3600, '/');
        return false;
    }

    if (!hash_equals($token['hashed_validator'], hash('sha256', $validator))) {
        // Kemungkinan pencurian token, hapus semua token user ini
        $pdo->prepare('DELETE FROM auth_tokens WHERE user_id = :user_id')->execute([':user_id' => $token['user_id']]);
        setcookie('remember_me', '', time() - 3600, '/');
        return false;
    }

    // Login berhasil, ambil data user
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id AND is_active = 1 LIMIT 1');
    $stmt->execute([':id' => $token['user_id']]);
    $user = $stmt->fetch();

    if ($user) {
        login_user($user);

        // (Opsional tapi sangat disarankan) Perbarui token untuk mencegah pencurian
        $newValidator = bin2hex(random_bytes(32));
        $newHashedValidator = hash('sha256', $newValidator);
        $pdo->prepare('UPDATE auth_tokens SET hashed_validator = :hashed_validator WHERE id = :id')->execute([
            ':hashed_validator' => $newHashedValidator,
            ':id' => $token['id'],
        ]);

        $cookieValue = $selector . ':' . $newValidator;
        setcookie('remember_me', $cookieValue, [
            'expires' => strtotime($token['expires_at']),
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        return true;
    }

    return false;
}

function is_logged_in(): bool
{
    return isset($_SESSION['user']) || login_via_cookie();
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function login_user(array $user): void
{
    $_SESSION['user'] = [
        'id' => $user['id'],
        'username' => $user['username'],
        'full_name' => $user['full_name'],
        'role' => $user['role'],
        'last_login_at' => $user['last_login_at'] ?? null,
    ];
    $_SESSION['last_activity'] = time();
}

function logout_user(): void
{
    if (isset($_COOKIE['remember_me'])) {
        $selector = explode(':', $_COOKIE['remember_me'], 2)[0];
        if ($selector) {
            $pdo = get_db_connection();
            $pdo->prepare('DELETE FROM auth_tokens WHERE selector = :selector')->execute([':selector' => $selector]);
        }
        setcookie('remember_me', '', time() - 3600, '/');
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: ' . BASE_URL . '/pages/login.php');
        exit;
    }

    $timeout = SESSION_TIMEOUT;
    if (isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === ROLE_KASIR) {
        $timeout = max($timeout, KASIR_INACTIVITY_TIMEOUT);
    }

    if (!isset($_SESSION['last_activity']) || time() - $_SESSION['last_activity'] > $timeout) {
        logout_user();
        header('Location: ' . BASE_URL . '/pages/login.php?expired=1');
        exit;
    }

    $_SESSION['last_activity'] = time();
}

function require_role(string $role): void
{
    global $ROLE_HIERARCHY;

    require_login();

    $user = current_user();
    $userLevel = $ROLE_HIERARCHY[$user['role']] ?? 0;
    $requiredLevel = $ROLE_HIERARCHY[$role] ?? PHP_INT_MAX;

    if ($userLevel < $requiredLevel) {
        http_response_code(403);
        include __DIR__ . '/../pages/403.php';
        exit;
    }
}
