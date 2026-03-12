<?php
require "config.php";

// current user (if logged in)
$currentUser = null;
if (!empty($_COOKIE['auth_token'])) {
    $st = $pdo->prepare("SELECT id, username FROM users WHERE auth_token = ?");
    $st->execute([$_COOKIE['auth_token']]);
    $currentUser = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// detect role columns/tables (reuse desktop logic)
$usersCols = $pdo->query("SHOW COLUMNS FROM `users`")->fetchAll(PDO::FETCH_COLUMN);
$has_role_id_col = in_array('role_id', $usersCols, true);
$has_role_col    = in_array('role', $usersCols, true);
$has_roles_table = false;
try {
    $r = $pdo->query("SHOW TABLES LIKE 'roles'")->fetchAll();
    $has_roles_table = !empty($r);
} catch (Exception $e) { $has_roles_table = false; }

// select target user
$target = null;
if (!empty($_GET['username'])) {
    $uname = trim((string)$_GET['username']);
    if ($has_role_id_col && $has_roles_table) {
        $stmt = $pdo->prepare("
            SELECT u.*, r.name AS role_name, r.color AS role_color, r.badge AS role_badge
            FROM users u
            LEFT JOIN roles r ON r.id = u.role_id
            WHERE u.username = ?
            LIMIT 1
        ");
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
    }
    $stmt->execute([$uname]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);
} elseif ($currentUser) {
    // fallback to current user
    if ($has_role_id_col && $has_roles_table) {
        $stmt = $pdo->prepare("
            SELECT u.*, r.name AS role_name, r.color AS role_color, r.badge AS role_badge
            FROM users u
            LEFT JOIN roles r ON r.id = u.role_id
            WHERE u.id = ?
            LIMIT 1
        ");
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    }
    $stmt->execute([$currentUser['id']]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);
}

// not found
if (!$target) {
    http_response_code(404);
    echo "User not found.";
    exit;
}

// normalize role
$role_name  = $target['role_name'] ?? ($target['role'] ?? null);
$role_color = $target['role_color'] ?? null;
$role_badge = $target['role_badge'] ?? null;
if (!$role_badge && $role_name) {
    $rn = strtolower($role_name);
    if ($rn === 'owner') $role_badge = '★';
    if ($rn === 'moderator') $role_badge = '✧';
}
if (!$role_color && $role_name) {
    $rn = strtolower($role_name);
    if ($rn === 'owner') $role_color = '#ffffff';
    if ($rn === 'moderator') $role_color = '#c0c0c0';
}

// owner check
$viewer_is_owner = $currentUser && ((int)$currentUser['id'] === (int)$target['id']);

// handle updates (same as desktop)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$viewer_is_owner) { http_response_code(403); echo "Forbidden"; exit; }
    $action = $_POST['action'] ?? '';
    if ($action === 'update_profile') {
        $full_name = trim((string)($_POST['full_name'] ?? ''));
        $bio = trim((string)($_POST['bio'] ?? ''));
        $stmt = $pdo->prepare("UPDATE users SET full_name = ?, bio = ? WHERE id = ?");
        $stmt->execute([$full_name ?: null, $bio ?: null, $target['id']]);
        header("Location: mobile_user.php?username=" . urlencode($target['username']));
        exit;
    }
    if ($action === 'upload_avatar' && !empty($_FILES['avatar']) && is_uploaded_file($_FILES['avatar']['tmp_name'])) {
        $maxBytes = 2 * 1024 * 1024;
        if ($_FILES['avatar']['size'] > $maxBytes) { $err = "Avatar too large (2MB)"; }
        else {
            $info = getimagesize($_FILES['avatar']['tmp_name']);
            if ($info === false) $err = "Not an image.";
            else {
                $mime = $info['mime'] ?? '';
                $ext = '';
                switch ($mime) {
                    case 'image/jpeg': $ext = '.jpg'; break;
                    case 'image/png':  $ext = '.png'; break;
                    case 'image/gif':  $ext = '.gif'; break;
                    case 'image/webp': $ext = '.webp'; break;
                    default: $ext = '';
                }
                if ($ext === '') $err = "Unsupported image type.";
                else {
                    $safe = bin2hex(random_bytes(8)) . $ext;
                    $destdir = __DIR__ . '/avatars';
                    if (!is_dir($destdir)) @mkdir($destdir, 0755, true);
                    $dest = $destdir . '/' . $safe;
                    if (!move_uploaded_file($_FILES['avatar']['tmp_name'], $dest)) $err = "Failed to save avatar.";
                    else {
                        $stmt = $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?");
                        $stmt->execute([$safe, $target['id']]);
                        header("Location: mobile_user.php?username=" . urlencode($target['username']));
                        exit;
                    }
                }
            }
        }
    }
}

// small helpers
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
$display_avatar = $target['avatar'] ? 'avatars/' . $target['avatar'] : null;
$display_fullname = $target['full_name'] ?? '';
$display_bio = $target['bio'] ?? '';
$display_username = $target['username'];
$joined = $target['created_at'] ?? null;
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($display_username) ?> — Profile</title>
<link rel="icon" href="root/favicon.ico">
<style>
:root{--bg:#0b0c0d;--panel:#101213;--muted:#9aa3b8;--accent:#5865F2}
body{margin:0;background:var(--bg);color:#eef2ff;font-family:Inter,Arial,Helvetica,sans-serif}
.container{padding:14px}
.header{display:flex;gap:12px;align-items:center}
.avatar{width:92px;height:92px;border-radius:12px;overflow:hidden;border:2px solid #0b0b0b;flex:0 0 92px;background:#222;display:flex;align-items:center;justify-content:center;font-size:36px}
.title{font-size:20px;font-weight:800}
.sub{color:var(--muted);font-size:13px}
.bio{margin-top:10px;color:#e8eef8;font-size:15px;line-height:1.25}
.buttons{display:flex;gap:8px;margin-top:12px}
.btn{padding:10px 12px;border-radius:10px;border:0;background:var(--accent);color:white;cursor:pointer;font-weight:700}
.btn.ghost{background:transparent;border:1px solid rgba(255,255,255,0.04)}
.form{margin-top:12px}
.input,textarea{width:100%;padding:10px;border-radius:10px;border:0;background:#0d0d0f;color:#fff;box-sizing:border-box}
.small{font-size:13px;color:var(--muted);margin-top:8px}
.error{color:#f88}
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <div class="avatar">
      <?php if ($display_avatar): ?>
        <img src="<?= e($display_avatar) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:12px" alt="avatar">
      <?php else: ?>
        <?= e(strtoupper($display_username[0] ?? '?')) ?>
      <?php endif; ?>
    </div>
    <div style="flex:1">
      <div class="title"><?= e($display_username) ?> <?php if ($role_badge): ?><span style="font-size:14px;margin-left:8px;color:#ddd"><?= e($role_badge) ?></span><?php endif; ?></div>
      <?php if ($display_fullname): ?><div class="sub"><?= e($display_fullname) ?></div><?php endif; ?>
      <div class="sub">Joined: <?= e($joined ?: 'unknown') ?></div>

      <div class="buttons">
        <button class="btn" onclick="location.href='mobile_message.php?user=<?= urlencode($display_username) ?>'">Message</button>
        <?php if ($currentUser && $currentUser['username'] !== $display_username): ?>
          <button class="btn ghost" onclick="location.href='message.php?user=<?= urlencode($display_username) ?>'">Message (desktop)</button>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="bio"><?= nl2br(e($display_bio)) ?></div>

  <?php if (!empty($error)): ?><div class="error"><?= e($error) ?></div><?php endif; ?>

  <?php if ($viewer_is_owner): ?>
    <div class="form">
      <form method="post">
        <input type="hidden" name="action" value="update_profile">
        <label class="small">Title</label>
        <input name="full_name" class="input" value="<?= e($display_fullname) ?>">
        <label class="small">Bio</label>
        <textarea name="bio" rows="4" class="input"><?= e($display_bio) ?></textarea>
        <div style="margin-top:8px">
          <button class="btn" type="submit">Save</button>
        </div>
      </form>

      <form method="post" enctype="multipart/form-data" style="margin-top:10px">
        <input type="hidden" name="action" value="upload_avatar">
        <label class="small">Upload avatar (png/jpg/gif/webp up to 2MB)</label>
        <div style="display:flex;gap:8px;margin-top:8px">
          <input type="file" name="avatar" accept="image/*" required>
          <button class="btn" type="submit">Upload</button>
        </div>
      </form>
    </div>
  <?php else: ?>
    <div class="small">Profile (read-only)</div>
  <?php endif; ?>
</div>
</body>
</html>
