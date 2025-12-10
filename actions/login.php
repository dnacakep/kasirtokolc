<?php

require_once __DIR__ . '/../config/setup_state.php';
if (setup_requires_wizard() && !setup_is_setup_request()) {
    header('Location: ' . setup_build_url());
    exit;
}

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';
require_once __DIR__ . '/../includes/cash_drawer.php';

guard_post();
verify_csrf_token($_POST['csrf_token'] ?? '');
 unset($_SESSION['csrf_token']);

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
$rememberMe = isset($_POST['remember_me']);

if ($username === '' || $password === '') {
    redirect_with_message('/pages/login.php', 'Nama pengguna dan kata sandi wajib diisi.', 'error');
}

$pdo = get_db_connection();
$stmt = $pdo->prepare('SELECT * FROM users WHERE username = :username AND is_active = 1 LIMIT 1');
$stmt->execute([':username' => $username]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    redirect_with_message('/pages/login.php', 'Nama pengguna atau kata sandi salah.', 'error');
}

$pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')->execute([':id' => $user['id']]);

login_user($user);

unset($_SESSION['cash_drawer_session_id'], $_SESSION['cash_drawer_pending_open']);

if ($rememberMe) {
    // Hapus token lama milik user ini
    $pdo->prepare('DELETE FROM auth_tokens WHERE user_id = :user_id')->execute([':user_id' => $user['id']]);

    $selector = bin2hex(random_bytes(16));
    $validator = bin2hex(random_bytes(32));
    $hashedValidator = hash('sha256', $validator);
    $expiresAt = date('Y-m-d H:i:s', time() + 60 * 60 * 24 * 30); // 30 hari

    $stmt = $pdo->prepare('INSERT INTO auth_tokens (selector, hashed_validator, user_id, expires_at) VALUES (:selector, :hashed_validator, :user_id, :expires_at)');
    $stmt->execute([
        ':selector' => $selector,
        ':hashed_validator' => $hashedValidator,
        ':user_id' => $user['id'],
        ':expires_at' => $expiresAt,
    ]);

    $cookieValue = $selector . ':' . $validator;
    setcookie('remember_me', $cookieValue, [
        'expires' => strtotime($expiresAt),
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

if ($user['role'] === ROLE_KASIR) {
    ensure_cash_drawer_schema($pdo);

    while ($existingSession = fetch_open_cash_session($pdo, (int) $user['id'])) {
        $summary = summarize_cash_session($pdo, $existingSession);
        close_cash_session(
            $pdo,
            (int) $existingSession['id'],
            (float) $summary['expected_balance'],
            (float) $summary['expected_balance'],
            (int) $user['id'],
            'Ditutup otomatis saat login ulang kasir.'
        );
    }

    $_SESSION['cash_drawer_pending_open'] = 1;
    redirect_with_message('/index.php?page=cash_drawer_open', 'Saldo kas direset. Catat saldo awal kas sebelum mulai shift.', 'info');
}

redirect_with_message('/index.php?page=dashboard', 'Selamat datang kembali, ' . $user['full_name'] . '!');
