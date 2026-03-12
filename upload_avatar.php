<?php
require "config.php";

if (empty($_COOKIE["auth_token"])) {
    header("Location:index.php");
    exit;
}

$stmt = $pdo->prepare("SELECT id, avatar FROM users WHERE auth_token = ?");
$stmt->execute([$_COOKIE["auth_token"]]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    exit("Invalid login.");
}

$user_id = (int)$user["id"];
$old_avatar = $user["avatar"] ?? null;

if (!isset($_FILES["avatar"]) || $_FILES["avatar"]["error"] !== UPLOAD_ERR_OK) {
    exit("Upload failed.");
}

$file = $_FILES["avatar"];
$allowed = ["image/png","image/jpeg","image/gif"];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file["tmp_name"]);
finfo_close($finfo);
if (!in_array($mime, $allowed)) {
    exit("Invalid file type.");
}

// create safe extension
$ext = pathinfo($file["name"], PATHINFO_EXTENSION);
$ext = preg_replace('/[^a-zA-Z0-9]/', '', $ext);
if (!$ext) {
    // derive from mime
    if ($mime === 'image/png') $ext = 'png';
    elseif ($mime === 'image/gif') $ext = 'gif';
    else $ext = 'jpg';
}

// create unique name
$name = $user_id . "_" . time() . "." . $ext;
$target_dir = __DIR__ . "/avatars/";
if (!is_dir($target_dir)) @mkdir($target_dir, 0755, true);
$target = $target_dir . $name;

if (!move_uploaded_file($file["tmp_name"], $target)) {
    exit("Failed to save file.");
}

// update DB
$stmt = $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?");
$stmt->execute([$name, $user_id]);

// now try to remove old avatar file (only if no other user references it)
if ($old_avatar && $old_avatar !== $name) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE avatar = ?");
    $stmt->execute([$old_avatar]);
    $count = (int)$stmt->fetchColumn();
    if ($count === 0) {
        $oldPath = $target_dir . $old_avatar;
        if (is_file($oldPath)) @unlink($oldPath);
    }
}

header("Location: room.php");
exit;
