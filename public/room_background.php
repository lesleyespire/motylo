<?php
// upload_room_background.php
require "config.php";

// require auth
if (empty($_COOKIE["auth_token"])) {
    http_response_code(403);
    echo json_encode(["ok" => false, "error" => "not logged in"]);
    exit;
}

$stmt = $pdo->prepare("SELECT id, username FROM users WHERE auth_token = ?");
$stmt->execute([$_COOKIE["auth_token"]]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    http_response_code(403);
    echo json_encode(["ok" => false, "error" => "invalid login"]);
    exit;
}

// room param (code or id)
$roomParam = $_POST["room"] ?? "";
if ($roomParam === "") {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "missing room"]);
    exit;
}

// resolve room row
if (ctype_digit((string)$roomParam)) {
    $stmt = $pdo->prepare("SELECT id, code FROM private_rooms WHERE id = ?");
    $stmt->execute([(int)$roomParam]);
} else {
    $stmt = $pdo->prepare("SELECT id, code FROM private_rooms WHERE code = ?");
    $stmt->execute([$roomParam]);
}
$room = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$room) {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "invalid room"]);
    exit;
}
$room_id = (int)$room["id"];

// ensure column exists (best-effort; ignores errors)
try {
    $cols = [];
    $q = $pdo->query("SHOW COLUMNS FROM private_rooms")->fetchAll(PDO::FETCH_COLUMN);
    $cols = array_map('strtolower', $q ?: []);
    if (!in_array('background', $cols)) {
        $pdo->exec("ALTER TABLE private_rooms ADD COLUMN background VARCHAR(255) NULL");
    }
} catch (Exception $e) {
    // ignore - fall back to silent failure if no ALTER privileges
}

// file checks
if (!isset($_FILES['background']) || !is_uploaded_file($_FILES['background']['tmp_name'])) {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "no file"]);
    exit;
}

$file = $_FILES['background'];
$maxBytes = 3 * 1024 * 1024; // 3 MB
if ($file['size'] > $maxBytes) {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "file too large"]);
    exit;
}

// validate image (mime + extension)
$imgInfo = @getimagesize($file['tmp_name']);
if (!$imgInfo) {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "invalid image"]);
    exit;
}
$mime = $imgInfo['mime'] ?? '';
$allowed = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/gif'=>'gif', 'image/webp'=>'webp'];
if (!isset($allowed[$mime])) {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "unsupported image type"]);
    exit;
}

$ext = $allowed[$mime];
$dir = __DIR__ . DIRECTORY_SEPARATOR . "room_backgrounds";
if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
}

// create sanitized unique filename
$base = bin2hex(random_bytes(8));
$filename = $base . '.' . $ext;
$target = $dir . DIRECTORY_SEPARATOR . $filename;

if (!move_uploaded_file($file['tmp_name'], $target)) {
    http_response_code(500);
    echo json_encode(["ok" => false, "error" => "move failed"]);
    exit;
}

// optional: create a web-friendly path
$webpath = "room_backgrounds/" . $filename;

// update private_rooms.background (best-effort)
try {
    $stmt = $pdo->prepare("UPDATE private_rooms SET background = ? WHERE id = ?");
    $stmt->execute([$webpath, $room_id]);
} catch (Exception $e) {
    // ignore DB update failures, but return the path so client can preview
}

// success
echo json_encode(["ok" => true, "path" => $webpath]);
exit;
