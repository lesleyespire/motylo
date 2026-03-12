<?php
// user.php - view / edit a user profile
require "config.php";

// ---------- helper: current user (if logged in) ----------
$currentUser = null;
if (!empty($_COOKIE['auth_token'])) {
    $st = $pdo->prepare("SELECT id, username FROM users WHERE auth_token = ?");
    $st->execute([$_COOKIE['auth_token']]);
    $currentUser = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ---------- detect DB schema for roles ----------
$usersCols = $pdo->query("SHOW COLUMNS FROM `users`")->fetchAll(PDO::FETCH_COLUMN);
$has_role_id_col = in_array('role_id', $usersCols, true);
$has_role_col    = in_array('role', $usersCols, true);

// check whether a roles table exists
$has_roles_table = false;
try {
    $r = $pdo->query("SHOW TABLES LIKE 'roles'")->fetchAll();
    $has_roles_table = !empty($r);
} catch (Exception $e) {
    $has_roles_table = false;
}

// ---------- pick target user ----------
$target = null;
$target_id = null;

if (!empty($_GET['id']) && ctype_digit((string)$_GET['id'])) {
    $target_id = (int)$_GET['id'];
    if ($has_role_id_col && $has_roles_table) {
        $stmt = $pdo->prepare("
            SELECT u.*, r.name AS role_name, r.color AS role_color, r.badge AS role_badge
            FROM users u
            LEFT JOIN roles r ON r.id = u.role_id
            WHERE u.id = ?
        ");
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    }
    $stmt->execute([$target_id]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);
} elseif (!empty($_GET['username'])) {
    $uname = trim($_GET['username']);
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
    // default to current user
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

// if not found, 404-ish
if (!$target) {
    http_response_code(404);
    echo "User not found.";
    exit;
}

// normalize role fields (fallbacks)
$role_name  = $target['role_name'] ?? ($target['role'] ?? null);
$role_color = $target['role_color'] ?? null;
$role_badge = $target['role_badge'] ?? null;

// fallback badge / color for owner/moderator if not provided
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

// ---------- POST handlers for edits (only owner allowed) ----------
$viewer_is_owner = $currentUser && ((int)$currentUser['id'] === (int)$target['id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF is not implemented (stateless site) — keep forms minimal and only allow owner edits.
    if (!$viewer_is_owner) {
        http_response_code(403);
        echo "Forbidden";
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $full_name = trim((string)($_POST['full_name'] ?? ''));
        $bio = trim((string)($_POST['bio'] ?? ''));

        $stmt = $pdo->prepare("UPDATE users SET full_name = ?, bio = ? WHERE id = ?");
        $stmt->execute([$full_name ?: null, $bio ?: null, $target['id']]);

        // reload target
        header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . "?username=" . urlencode($target['username']));
        exit;
    }

    if ($action === 'upload_avatar' && !empty($_FILES['avatar']) && is_uploaded_file($_FILES['avatar']['tmp_name'])) {
        // basic validation
        $maxBytes = 2 * 1024 * 1024; // 2MB
        if ($_FILES['avatar']['size'] > $maxBytes) {
            $err = "Avatar too large (max 2MB).";
        } else {
            $info = getimagesize($_FILES['avatar']['tmp_name']);
            if ($info === false) {
                $err = "Uploaded file is not a valid image.";
            } else {
                // allow jpeg, png, gif, webp
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
                    // prepare filename, move
                    $safe = bin2hex(random_bytes(8)) . $ext;
                    $destdir = __DIR__ . '/avatars';
                    if (!is_dir($destdir)) @mkdir($destdir, 0755, true);
                    $dest = $destdir . '/' . $safe;
                    if (!move_uploaded_file($_FILES['avatar']['tmp_name'], $dest)) {
                        $err = "Failed to save avatar.";
                    } else {
                        // update DB
                        $stmt = $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?");
                        $stmt->execute([$safe, $target['id']]);
                        // reload
                        header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . "?username=" . urlencode($target['username']));
                        exit;
                    }
                }
            }
        }
        if (!empty($err)) {
            // simple feedback (in real app you'd store in session / flash)
            $error_message = $err;
        }
    }
}

// ---------- helper escaping ----------
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }

// display values
$display_avatar = $target['avatar'] ? 'avatars/' . $target['avatar'] : null;
$display_fullname = $target['full_name'] ?? '';
$display_bio = $target['bio'] ?? '';
$display_username = $target['username'];
$joined = $target['created_at'] ?? null;

// admin/large view mode?
$adminMode = isset($_GET['admin']) && ($_GET['admin'] === '1' || $_GET['admin'] === 'true' || $_GET['admin'] === 'on');

?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>User: <?= e($display_username) ?></title>
<link rel="icon" href="root/favicon.ico">
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
:root{--bg:#0f1113;--panel:#121314;--muted:#cfcfcf}
body{margin:0;background:var(--bg);color:#eee;font-family:Inter,Arial,Helvetica,sans-serif}
.container{max-width:900px;margin:28px auto;padding:18px;background:var(--panel);border-radius:10px}
.header{display:flex;gap:16px;align-items:center}
.avatar{width:300px;height:300px;border-radius:12px;overflow:hidden;flex:0 0 300px;border:3px solid #111}
.avatar img{width:100%;height:100%;object-fit:cover;display:block}
.avatar.large{width:160px;height:160px;border-radius:12px}
.title{font-size:75px;font-weight:700}
.small{color:var(--muted);font-size:30px}
.roleBadge{display:inline-block;padding:6px 10px;border-radius:999px;font-size:13px;margin-left:8px}
.bio{white-space:pre-wrap;margin-top:14px;line-height:0.6;font-size:30px;}
.meta{margin-top:8px;color:var(--muted);font-size:13px}
.editForm{margin-top:14px}
.input, textarea{width:100%;padding:10px;border-radius:8px;border:0;background:#0b0c0d;color:#fff;box-sizing:border-box}
.row{display:flex;gap:10px}
.col{flex:1}
.smallBtn{background:#5865F2;border:0;padding:8px 10px;border-radius:8px;color:white;cursor:pointer}
.note{margin-top:8px;font-size:13px;color:var(--muted)}
.error{color:#f88;margin-top:8px}
.toplinks{margin-top:12px}
a.link{color:#9bbcff;text-decoration:none}
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="avatar <?= $adminMode ? 'large' : '' ?>">
            <?php if ($display_avatar): ?>
                <img src="<?= e($display_avatar) ?>" alt="Avatar">
            <?php else: ?>
                <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:#2a2a2a;color:#fff;font-size:48px;">
                    <?= e(strtoupper($display_username[0] ?? '?')) ?>
                </div>
            <?php endif; ?>
        </div>

        <div>
            <div style="display:flex;align-items:center;gap:12px">
                <div class="title"><?= e($display_username) ?></div>
                <?php if ($role_name): 
                    $badgeBg = $role_color ?: '#ddd';
                    $badgeColor = (strtolower($badgeBg) === '#ffffff') ? '#000' : '#000';
                ?>
                    <div class="roleBadge" style="background:<?= e($badgeBg) ?>;color:<?= e($badgeColor) ?>"><?= e($role_badge ?: $role_name) ?></div>
                <?php endif; ?>
            </div>

            <?php if ($display_fullname): ?>
                <div class="small"><?= e($display_fullname) ?></div>
            <?php endif; ?>

            <div class="meta">Joined: <?= e($joined ?: 'unknown') ?></div>

            <div class="toplinks">
                <a class="link" href="room.php">← Back to chat</a>
                <?php if ($currentUser): ?>&nbsp;•&nbsp;<a class="link" href="user.php?username=<?= urlencode($currentUser['username']) ?>">My profile</a><?php endif; ?>
                &nbsp;•&nbsp;<a class="link" href="message.php?user=<?= urlencode($display_username) ?>">Message</a>
            </div>
        </div>
    </div>

    <div class="bio">
        <?= nl2br(e($display_bio)) ?>
    </div>

    <?php if (!empty($error_message)): ?>
        <div class="error"><?= e($error_message) ?></div>
    <?php endif; ?>

    <?php if ($viewer_is_owner): ?>
        <div class="editForm">
            <form method="post" enctype="multipart/form-data" style="margin-bottom:12px">
                <input type="hidden" name="action" value="update_profile">
                <label class="small">Title</label>
                <input class="input" name="full_name" value="<?= e($display_fullname) ?>">
                <label class="small" style="margin-top:8px">Bio</label>
                <textarea name="bio" rows="6" class="input"><?= e($display_bio) ?></textarea>
                <div style="margin-top:8px;display:flex;gap:8px">
                    <button class="smallBtn" type="submit">Save profile</button>
                    <span class="note">Only you can edit your profile</span>
                </div>
            </form>

            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_avatar">
                <label class="small">Upload avatar (png,jpg,gif,webp up to 2MB)</label>
                <div style="display:flex;gap:10px;margin-top:15px">
                    <button class="smallBtn" type="submit">Upload</button>
                    <input type="file" name="avatar" accept="image/*" required>
                </div>
            </form>
        </div>
    <?php else: ?>
        <div class="note">Read-only.</div>
    <?php endif; ?>

</div>
</body>
</html>
