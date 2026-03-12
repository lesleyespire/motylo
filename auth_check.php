<?php
require_once 'config.php';

// If no auth cookie, user is not logged in
if (!isset($_COOKIE['auth_user'])) {
    http_response_code(401);
    echo "Unauthorized - no cookie";
    exit;
}

$username = $_COOKIE['auth_user'];

// Optional: sanitize
$username = preg_replace('/[^a-zA-Z0-9_\-]/', '', $username);

// Check user exists
$stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
$stmt->execute([$username]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(401);
    echo "Unauthorized - user not found";
    exit;
}

// Authentication OK
return $username;
