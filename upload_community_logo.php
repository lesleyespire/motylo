<?php
// upload_community_logo.php
require "config.php";

if (empty($_COOKIE['auth_token'])) { header("Location:index.php"); exit; }
$stmt = $pdo->prepare("SELECT id, username FROM users WHERE auth_token = ? LIMIT 1");
$stmt->execute([$_COOKIE['auth_token']]);
$current = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$current) { header("Location:index.php"); exit; }
$me_id = (int)$current['id'];

$community_id = isset($_POST['community_id']) ? (int)$_POST['community_id'] : 0;
if ($community_id <= 0) {
    http_response_code(400);
    echo "Missing community_id";
    exit;
}

// check admin permission (owner or local admin)
function is_comm_admin_local_simple($pdo,$community_id,$user_id){
    $s = $pdo->prepare("SELECT owner_id FROM communities WHERE id = ? LIMIT 1");
    $s->execute([$community_id]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r) return false;
    if ((int)$r['owner_id'] === (int)$user_id) return true;
    try {
        $s2 = $pdo->prepare("SELECT cr.is_admin FROM community_members cm JOIN community_roles cr ON cr.id = cm.role_id WHERE cm.community_id = ? AND cm.user_id = ? LIMIT 1");
        $s2->execute([$community_id,$user_id]);
        $v = $s2->fetchColumn();
        return (bool)$v;
    } catch (Exception $e) { return false; }
}
if (!is_comm_admin_local_simple($pdo,$community_id,$me_id)) {
    http_response_code(403);
    echo "Forbidden";
    exit;
}

// handle file
if (!isset($_FILES['logo']) || !is_uploaded_file($_FILES['logo']['tmp_name'])) {
    // redirect back
    $ref = $_SERVER['HTTP_REFERER'] ?? 'community_admin.php?id=' . $community_id;
    header('Location: ' . $ref . '&upload_error=missing_file'); exit;
}

$file = $_FILES['logo'];
$maxBytes = 3 * 1024 * 1024; // 3 MB
if ($file['size'] > $maxBytes) {
    $ref = $_SERVER['HTTP_REFERER'] ?? 'community_admin.php?id=' . $community_id;
    header('Location: ' . $ref . '&upload_error=too_large'); exit;
}
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);
$allowed = ['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp','image/gif'=>'gif'];
if (!isset($allowed[$mime])) {
    $ref = $_SERVER['HTTP_REFERER'] ?? 'community_admin.php?id=' . $community_id;
    header('Location: ' . $ref . '&upload_error=bad_type'); exit;
}

$ext = $allowed[$mime];
$dir = __DIR__ . '/uploads/community_logos';
if (!is_dir($dir)) mkdir($dir, 0755, true);
$filename = 'logo_' . $community_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$path = $dir . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $path)) {
    $ref = $_SERVER['HTTP_REFERER'] ?? 'community_admin.php?id=' . $community_id;
    header('Location: ' . $ref . '&upload_error=save_failed'); exit;
}

// update DB (store relative path or filename, choose filename)
$stmt = $pdo->prepare("UPDATE communities SET logo = ? WHERE id = ?");
$stmt->execute([$filename, $community_id]);

// redirect back to admin page for the community (prefer public_id)
$pub = null;
try {
    $t = $pdo->prepare("SELECT public_id FROM communities WHERE id = ? LIMIT 1");
    $t->execute([$community_id]);
    $pub = $t->fetchColumn();
} catch (Exception $e) { $pub = null; }

if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');
    echo json_encode(['ok'=>true,'filename'=>$filename]);
    exit;
}

if ($pub) {
    header('Location: community_admin.php?public_id=' . urlencode($pub) . '&logo_uploaded=1');
} else {
    header('Location: community_admin.php?id=' . $community_id . '&logo_uploaded=1');
}
exit;
