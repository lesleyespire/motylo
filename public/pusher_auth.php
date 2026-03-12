<?php
// pusher_auth.php
require "config.php";
header("Content-Type: application/json");

// silence notices
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

if (empty($pusher_app_key) || empty($pusher_app_secret) || empty($pusher_app_id)) {
    http_response_code(500);
    echo json_encode(["error" => "Pusher not configured."]);
    exit;
}

$socket_id = $_POST['socket_id'] ?? null;
$channel_name = $_POST['channel_name'] ?? null;
if (!$socket_id || !$channel_name) {
    http_response_code(400);
    echo json_encode(["error" => "Missing socket_id or channel_name"]);
    exit;
}

if (empty($_COOKIE["auth_token"])) {
    http_response_code(403);
    echo json_encode(["error" => "not logged in"]);
    exit;
}

$stmt = $pdo->prepare("SELECT id, username, avatar FROM users WHERE auth_token = ?");
$stmt->execute([$_COOKIE["auth_token"]]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    http_response_code(403);
    echo json_encode(["error" => "invalid login"]);
    exit;
}

$user_id = (string)$user['id'];
$user_info = [
    "username" => $user['username'],
    "avatar" => $user['avatar'] ?? null
];

$channel_data = json_encode([
    "user_id" => $user_id,
    "user_info" => $user_info
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// signature according to Pusher presence auth docs
$string_to_sign = $socket_id . ':' . $channel_name . ':' . $channel_data;
$signature = hash_hmac('sha256', $string_to_sign, $pusher_app_secret);
$auth = $pusher_app_key . ':' . $signature;

echo json_encode([
    "auth" => $auth,
    "channel_data" => $channel_data
]);
exit;
