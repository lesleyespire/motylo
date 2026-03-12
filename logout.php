<?php
require "config.php";

// clear auth_token in DB (if present)
if (!empty($_COOKIE['auth_token'])) {
    try {
        $stmt = $pdo->prepare("UPDATE users SET auth_token = NULL WHERE auth_token = ?");
        $stmt->execute([$_COOKIE['auth_token']]);
    } catch (Exception $e) {
        // ignore
    }
}

// expire cookie
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

setcookie("auth_token", "", [
    'expires' => time() - 3600,
    'path' => '/',
    'domain' => '',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax'
]);

header("Location: index.php");
exit;
