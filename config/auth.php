<?php
// Shared authentication & security helper
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax'
    ]);
    session_start();
}

function require_login(): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

function auth_escape($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function auth_token(): string {
    if (empty($_SESSION['auth_csrf'])) {
        $_SESSION['auth_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['auth_csrf'];
}

function check_auth_token(): void {
    if (!hash_equals(auth_token(), (string)($_POST['auth_csrf'] ?? ''))) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }
}