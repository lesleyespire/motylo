<?php
// upload_image.php
// Simple authenticated image upload endpoint for private rooms.
// Stores files in ./images and records metadata in uploaded_images.
// Performs basic cleanup of old files (files older than $CLEANUP_OLDER_THAN seconds).

require "config.php";

header('Content-Type: application/json; charset=utf-8');

if (empty($_COOKIE['auth_token'])) {
    http_response_code(403);
    echo json_encode(['ok'=>false, 'error'=>'Not authenticated']);
    exit;
}
$st = $pdo->prepare("SELECT id, username FROM users WHERE auth_token = ? LIMIT 1");
$st->execute([$_COOKIE['auth_token']]);
$user = $st->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    http_response_code(403);
    echo json_encode(['ok'=>false, 'error'=>'Not authenticated']);
    exit;
}
$uid = (int)$user['id'];

// config
$UPLOAD_DIR = __DIR__ . '/images';
$WEB_PATH_PREFIX = '/images'; // used in returned url
$MAX_BYTES = 2 * 1024 * 1024; // 2 MB limit (tweakable)
$ALLOWED_MIME = [
    'image/png'  => 'png',
    'image/jpeg' => 'jpg',
    'image/jpg'  => 'jpg',
    'image/webp' => 'webp',
    'image/gif'  => 'gif'
];
// cleanup older than 30 days
$CLEANUP_OLDER_THAN = 30 * 24 * 3600;

// ensure upload dir exists
if (!is_dir($UPLOAD_DIR)) {
    if (!@mkdir($UPLOAD_DIR, 0755, true)) {
        http_response_code(500);
        echo json_encode(['ok'=>false, 'error'=>'Unable to create images directory']);
        exit;
    }
}

// basic POST + file presence
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false, 'error'=>'Invalid method']);
    exit;
}
if (empty($_FILES['image']) || !is_uploaded_file($_FILES['image']['tmp_name'])) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>'No file uploaded']);
    exit;
}

$file = $_FILES['image'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>'Upload error: ' . $file['error']]);
    exit;
}
if ($file['size'] > $MAX_BYTES) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>'File too large (max ' . ($MAX_BYTES/1024/1024) . ' MB)']);
    exit;
}

// use getimagesize to validate real image
$imginfo = @getimagesize($file['tmp_name']);
if (!$imginfo || empty($imginfo['mime'])) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>'Not a valid image']);
    exit;
}
$mime = strtolower($imginfo['mime']);
if (!array_key_exists($mime, $ALLOWED_MIME)) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>'Unsupported image type']);
    exit;
}
$ext = $ALLOWED_MIME[$mime];

// generate safe filename
$basename = bin2hex(random_bytes(10));
$filename = $basename . '.' . $ext;
$dest = $UPLOAD_DIR . '/' . $filename;

// move uploaded file
if (!move_uploaded_file($file['tmp_name'], $dest)) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>'Failed to save file']);
    exit;
}

// optional: set more restrictive perms
@chmod($dest, 0644);

// record in DB (table uploaded_images)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS uploaded_images (
        id INT AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(255) NOT NULL,
        original_name VARCHAR(255) NULL,
        user_id INT NULL,
        size_bytes INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    // ignore table create failures but continue
}

try {
    $ins = $pdo->prepare("INSERT INTO uploaded_images (filename, original_name, user_id, size_bytes) VALUES (?, ?, ?, ?)");
    $ins->execute([$filename, $file['name'] ?? null, $uid, (int)$file['size']]);
    $rowId = (int)$pdo->lastInsertId();
} catch (Exception $e) {
    // not fatal; continue
    $rowId = null;
}

// perform lightweight cleanup: remove files older than CLEANUP_OLDER_THAN
// This is intentionally simple: uses file mtime and unlinks.
// If you prefer to only remove files not referenced in DB, you can extend this.
try {
    $now = time();
    foreach (new DirectoryIterator($UPLOAD_DIR) as $item) {
        if ($item->isDot() || !$item->isFile()) continue;
        $filePath = $item->getPathname();
        $mtime = $item->getMTime();
        if ($now - $mtime > $CLEANUP_OLDER_THAN) {
            @unlink($filePath);
        }
    }
} catch (Exception $e) {
    // ignore cleanup errors
}

// return success + web-accessible url and a simple token for client usage
$url = rtrim($WEB_PATH_PREFIX, '/') . '/' . rawurlencode($filename);
echo json_encode(['ok' => true, 'url' => $url, 'filename' => $filename, 'image_id' => $rowId]);
exit;
