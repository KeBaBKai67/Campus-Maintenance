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

function send_line_message($message_text) {
    $access_token = 'YOUR_CHANNEL_ACCESS_TOKEN_HERE'; 
    
    $user_id = 'YOUR_USER_ID_HERE'; 

    $url = 'https://api.line.me/v2/bot/message/push';
    
    $data = [
        'to' => $user_id,
        'messages' => [
            [
                'type' => 'text',
                'text' => $message_text
            ]
        ]
    ];
    
    $post_data = json_encode($data);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $access_token
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    $result = curl_exec($ch);
    curl_close($ch);

    return $result;
}