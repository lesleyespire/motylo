<?php
// community_interface.php
// AJAX endpoint for community-level actions (create_room, create_default_roles, etc.)
require "config.php";
header("Content-Type: application/json; charset=utf-8");

if (empty($_COOKIE['auth_token'])) { echo json_encode(['error'=>'not logged in']); exit; }
$stmt = $pdo->prepare("SELECT id, username FROM users WHERE auth_token = ? LIMIT 1");
$stmt->execute([$_COOKIE['auth_token']]);
$me = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$me) { echo json_encode(['error'=>'bad login']); exit; }
$me_id = (int)$me['id'];
$me_username = $me['username'];

$action = $_GET['action'] ?? $_POST['action'] ?? '';
if ($action === 'resolve_user') {
    $username = trim($_GET['username'] ?? '');
    $community_id = (int)($_GET['community_id'] ?? 0);
    if (!$username) { echo json_encode(['error'=>'no username']); exit; }
    $s = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
    $s->execute([$username]);
    $uid = $s->fetchColumn();
    if ($uid) echo json_encode(['ok'=>true,'user_id'=> (int)$uid]);
    else echo json_encode(['ok'=>false]);
    exit;
}
if ($action === 'get_user_roles_by_name') {
    $username = trim($_GET['username'] ?? '');
    $community_id = (int)($_GET['community_id'] ?? 0);
    if (!$username || !$community_id) { echo json_encode(['error'=>'missing']); exit; }
    $u = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1"); $u->execute([$username]); $user = $u->fetchColumn();
    if (!$user) { echo json_encode(['ok'=>true,'roles'=>[]]); exit; }
    // fetch roles via community_member_roles (or fallback to community_members)
    $hasMany = (bool)$pdo->query("SHOW TABLES LIKE 'community_member_roles'")->fetchColumn();
    $roles = [];
    if ($hasMany) {
        $q = $pdo->prepare("SELECT cr.id, cr.name, cr.color, cr.badge FROM community_member_roles mr JOIN community_roles cr ON cr.id = mr.role_id WHERE mr.community_id = ? AND mr.user_id = ? ORDER BY cr.id ASC");
        $q->execute([$community_id, $user]);
        $roles = $q->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $q = $pdo->prepare("SELECT cr.id, cr.name, cr.color, cr.badge FROM community_members cm JOIN community_roles cr ON cr.id = cm.role_id WHERE cm.community_id = ? AND cm.user_id = ? LIMIT 1");
        $q->execute([$community_id, $user]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if ($r) $roles = [$r];
    }
    echo json_encode(['ok'=>true,'roles'=>$roles]);
    exit;
}

if ($action === 'moderate') {
    // expected POST fields: action_type, community_id, target_user_id, role_id (optional), duration_minutes (optional), reason (optional)
    $data = $_POST;
    $action_type = $data['action_type'] ?? '';
    $community_id = (int)($data['community_id'] ?? 0);
    $target_user_id = (int)($data['target_user_id'] ?? 0);
    $role_id = isset($data['role_id']) ? (int)$data['role_id'] : null;
    $reason = trim((string)($data['reason'] ?? ''));
    $duration = isset($data['duration_minutes']) ? (int)$data['duration_minutes'] : null;

    if (!$action_type || !$community_id || !$target_user_id) { echo json_encode(['error'=>'missing']); exit; }

    // check that current user is admin for the community
    $q = $pdo->prepare("SELECT owner_id FROM communities WHERE id = ? LIMIT 1"); $q->execute([$community_id]); $com = $q->fetch(PDO::FETCH_ASSOC);
    if (!$com) { echo json_encode(['error'=>'bad community']); exit; }
    if ((int)$com['owner_id'] !== $me_id) {
        // check community_roles membership: is_admin
        $hasMany = (bool)$pdo->query("SHOW TABLES LIKE 'community_member_roles'")->fetchColumn();
        $isAdmin = false;
        if ($hasMany) {
            $q = $pdo->prepare("SELECT 1 FROM community_member_roles mr JOIN community_roles cr ON cr.id = mr.role_id WHERE mr.community_id = ? AND mr.user_id = ? AND cr.is_admin = 1 LIMIT 1");
            $q->execute([$community_id, $me_id]);
            if ($q->fetchColumn()) $isAdmin = true;
        } else {
            $q = $pdo->prepare("SELECT cr.is_admin FROM community_members cm JOIN community_roles cr ON cr.id = cm.role_id WHERE cm.community_id = ? AND cm.user_id = ? LIMIT 1");
            $q->execute([$community_id, $me_id]);
            if ($q->fetchColumn()) $isAdmin = true;
        }
        if (!$isAdmin) { echo json_encode(['error'=>'not allowed']); exit; }
    }

    // perform actions
    try {
        if ($action_type === 'add_role' || $action_type === 'remove_role') {
            if (!$role_id) { echo json_encode(['error'=>'no role_id']); exit; }
            // ensure role belongs to community
            $r = $pdo->prepare("SELECT id FROM community_roles WHERE id = ? AND community_id = ? LIMIT 1"); $r->execute([$role_id, $community_id]); if (!$r->fetchColumn()) { echo json_encode(['error'=>'invalid role']); exit; }

            if ($action_type === 'add_role') {
                // insert into community_member_roles (create table earlier)
                $pdo->prepare("INSERT IGNORE INTO community_member_roles (community_id, user_id, role_id) VALUES (?, ?, ?)")->execute([$community_id, $target_user_id, $role_id]);
            } else {
                $pdo->prepare("DELETE FROM community_member_roles WHERE community_id = ? AND user_id = ? AND role_id = ?")->execute([$community_id, $target_user_id, $role_id]);
            }
            // audit log
            $meta = ['role_id' => $role_id];
            $ins = $pdo->prepare("INSERT INTO community_audit (community_id, moderator_id, moderator_name, target_user_id, target_username, action_type, action_meta, reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            // try to fetch target username
            $tu = $pdo->prepare("SELECT username FROM users WHERE id = ? LIMIT 1"); $tu->execute([$target_user_id]); $target_username = $tu->fetchColumn();
            $ins->execute([$community_id, $me_id, $me['username'], $target_user_id, $target_username, $action_type, json_encode($meta), $reason]);
            echo json_encode(['ok'=>true]); exit;
        }

        if ($action_type === 'timeout') {
            // set a per-community timeout: store on users table or a community timeouts table; simplest: community_timeouts
            $minutes = max(1, (int)$duration);
            // ensure table exists
            $pdo->exec("CREATE TABLE IF NOT EXISTS community_timeouts (id INT AUTO_INCREMENT PRIMARY KEY, community_id INT NOT NULL, user_id INT NOT NULL, until_dt DATETIME NOT NULL, reason TEXT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
            $until = (new DateTime())->modify("+{$minutes} minutes")->format('Y-m-d H:i:s');
            $pdo->prepare("INSERT INTO community_timeouts (community_id, user_id, until_dt, reason) VALUES (?, ?, ?, ?)")->execute([$community_id, $target_user_id, $until, $reason]);
            $ins = $pdo->prepare("INSERT INTO community_audit (community_id, moderator_id, moderator_name, target_user_id, target_username, action_type, action_meta, reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $tu = $pdo->prepare("SELECT username FROM users WHERE id = ? LIMIT 1"); $tu->execute([$target_user_id]); $target_username = $tu->fetchColumn();
            $meta = ['duration_minutes' => $minutes, 'until' => $until];
            $ins->execute([$community_id, $me_id, $me['username'], $target_user_id, $target_username, 'timeout', json_encode($meta), $reason]);
            echo json_encode(['ok'=>true, 'until'=>$until]); exit;
        }

        if ($action_type === 'ban') {
            // implement per-community ban table
            $pdo->exec("CREATE TABLE IF NOT EXISTS community_bans (id INT AUTO_INCREMENT PRIMARY KEY, community_id INT NOT NULL, user_id INT NOT NULL, reason TEXT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(community_id), INDEX(user_id))");
            $pdo->prepare("INSERT IGNORE INTO community_bans (community_id, user_id, reason) VALUES (?, ?, ?)")->execute([$community_id, $target_user_id, $reason]);
            $ins = $pdo->prepare("INSERT INTO community_audit (community_id, moderator_id, moderator_name, target_user_id, target_username, action_type, action_meta, reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $tu = $pdo->prepare("SELECT username FROM users WHERE id = ? LIMIT 1"); $tu->execute([$target_user_id]); $target_username = $tu->fetchColumn();
            $ins->execute([$community_id, $me_id, $me['username'], $target_user_id, $target_username, 'ban', json_encode((object)[]), $reason]);
            echo json_encode(['ok'=>true]); exit;
        }

        echo json_encode(['error'=>'unknown action']);
    } catch (Exception $e) {
        echo json_encode(['error'=>'exception', 'msg'=>$e->getMessage()]);
    }
    exit;
}

// small helpers
function json_err($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(["error" => $msg]);
    exit;
}
function json_ok($data = []) {
    echo json_encode(array_merge(["ok" => true], $data));
    exit;
}

// action (GET preferred for routing compatibility)
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$action = trim((string)$action);
if ($action === '') json_err("missing action");

// helper: check whether $uid is community owner or local admin (community_roles.is_admin)
function is_comm_admin($pdo, $community_id, $uid) {
    // owner
    $q = $pdo->prepare("SELECT owner_id FROM communities WHERE id = ? LIMIT 1");
    $q->execute([$community_id]);
    $owner = $q->fetchColumn();
    if ($owner && (int)$owner === (int)$uid) return true;
    // role flagged is_admin
    try {
        $q2 = $pdo->prepare("SELECT cr.is_admin FROM community_members cm JOIN community_roles cr ON cr.id = cm.role_id WHERE cm.community_id = ? AND cm.user_id = ? LIMIT 1");
        $q2->execute([$community_id, $uid]);
        return (bool)$q2->fetchColumn();
    } catch (Exception $e) { return false; }
}

// Ensure common schema exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS private_rooms (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(128) NOT NULL UNIQUE,
        name VARCHAR(255) NOT NULL,
        community_id INT DEFAULT NULL,
        required_role_id INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { /* ignore */ }

// ACTION: create_room
if ($action === 'create_room') {
    // expected POST: name, community_id, optional required_role_id
    $name = trim((string)($_POST['name'] ?? ''));
    $community_id = isset($_POST['community_id']) ? (int)$_POST['community_id'] : 0;
    $required_role_id = isset($_POST['required_role_id']) && $_POST['required_role_id'] !== '' ? (int)$_POST['required_role_id'] : null;

    if ($name === '') json_err("missing name");
    if ($community_id <= 0) json_err("missing community_id");

    // is caller allowed to create room? require community admin/owner
    if (!is_comm_admin($pdo, $community_id, $uid)) json_err("denied", 403);

    // sanitize name length
    if (mb_strlen($name, 'UTF-8') > 120) $name = mb_substr($name,0,120,'UTF-8');

    // generate unique code: c + 16 hex
    try {
        $tries = 0;
        do {
            $code = 'c' . substr(bin2hex(random_bytes(8)),0,16);
            $ins = $pdo->prepare("INSERT INTO private_rooms (code, name, community_id, required_role_id) VALUES (?, ?, ?, ?)");
            $ins->execute([$code, $name, $community_id, $required_role_id]);
            $ok = true;
        } while(false);
    } catch (Exception $e) {
        // collision or other - try simple fallback loop
        $ok = false;
        for ($i=0;$i<6;$i++){
            $code = 'c' . substr(md5($name . microtime(true) . random_int(1,999999)),0,14);
            try {
                $ins = $pdo->prepare("INSERT INTO private_rooms (code, name, community_id, required_role_id) VALUES (?, ?, ?, ?)");
                $ins->execute([$code, $name, $community_id, $required_role_id]);
                $ok = true; break;
            } catch (Exception $e2) { $ok = false; continue; }
        }
        if (!$ok) json_err("failed to create room");
    }

    // success response — include can_view for calling user (owner/admin should view)
    $can_view = true; // creators are admins => allowed
    json_ok([
        "code" => $code,
        "name" => $name,
        "can_view" => $can_view,
        "nameEscaped" => htmlentities($name, ENT_QUOTES, 'UTF-8')
    ]);
}

// ACTION: create_default_roles (legacy form in community_admin posts here)
if ($action === 'create_default_roles') {
    $community_id = isset($_REQUEST['community_id']) ? (int)$_REQUEST['community_id'] : 0;
    if ($community_id <= 0) json_err("missing community_id");
    if (!is_comm_admin($pdo, $community_id, $uid)) json_err("denied",403);

    try {
        // ensure table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS community_roles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            community_id INT NOT NULL,
            name VARCHAR(120) NOT NULL,
            color VARCHAR(32) DEFAULT NULL,
            badge VARCHAR(32) DEFAULT NULL,
            is_admin TINYINT(1) DEFAULT 0,
            can_delete_messages TINYINT(1) DEFAULT 0,
            can_timeout TINYINT(1) DEFAULT 0,
            can_ban TINYINT(1) DEFAULT 0,
            can_edit_channels TINYINT(1) DEFAULT 0,
            can_view_locked TINYINT(1) DEFAULT 0,
            UNIQUE KEY ux_comm_name (community_id, name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}

    // create Member, Moderator, Admin if missing
    $exists = $pdo->prepare("SELECT COUNT(*) FROM community_roles WHERE community_id = ? AND name = ? LIMIT 1");
    $ins = $pdo->prepare("INSERT INTO community_roles (community_id,name,color,badge,is_admin,can_delete_messages,can_timeout,can_ban,can_edit_channels,can_view_locked) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    // Member
    $exists->execute([$community_id, 'Member']); if (!$exists->fetchColumn()) { $ins->execute([$community_id,'Member',null,null,0,0,0,0,0,0]); }
    // Moderator
    $exists->execute([$community_id, 'Moderator']); if (!$exists->fetchColumn()) { $ins->execute([$community_id,'Moderator',null,'✦',0,1,1,1,1,1]); }
    // Admin
    $exists->execute([$community_id, 'Admin']); if (!$exists->fetchColumn()) { $ins->execute([$community_id,'Admin',null,'★',1,1,1,1,1,1]); }

    json_ok();
}
// ACTION: create_community
if ($action === 'create_community') {
    // Anyone logged in may create a community (per your request).
    $name = trim((string)($_POST['name'] ?? ''));
    $slug = trim((string)($_POST['slug'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));

    if ($name === '') json_err("missing name");

    // sanitize slug: lowercase, letters/numbers/dashes only
    if ($slug !== '') {
        $slug = strtolower(preg_replace('/[^a-z0-9\\-]+/', '', str_replace(' ', '-', $slug)));
    } else {
        // derive from name
        $slug = strtolower(preg_replace('/[^a-z0-9\\-]+/', '', str_replace(' ', '-', mb_substr($name,0,64))));
        if ($slug === '') $slug = 'c' . time();
    }

    // ensure slug uniqueness (try a few variants)
    $base = $slug;
    $try = 0;
    while ($try < 10) {
        $q = $pdo->prepare("SELECT id FROM communities WHERE slug = ? LIMIT 1");
        $q->execute([$slug]);
        if (!$q->fetchColumn()) break;
        $slug = $base . '-' . mt_rand(100,999);
        $try++;
    }

    // generate a simple public_id (string)
    $public_id = (string)time() . mt_rand(100,999);

    try {
        $ins = $pdo->prepare("INSERT INTO communities (slug, name, description, owner_id, public_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $ins->execute([$slug, $name, $description !== '' ? $description : null, $me_id, $public_id]);
        $community_id = (int)$pdo->lastInsertId();

        if ($community_id > 0) {
            // ensure community_members exists and add owner
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS community_members (community_id INT NOT NULL, user_id INT NOT NULL, role_id INT DEFAULT NULL, accepted_at DATETIME DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            } catch (Exception $e) {}
            $ins2 = $pdo->prepare("INSERT INTO community_members (community_id, user_id, accepted_at) VALUES (?, ?, NOW())");
            $ins2->execute([$community_id, $me_id]);

            // create default roles (Member / Moderator / Admin)
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS community_roles (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    community_id INT NOT NULL,
                    name VARCHAR(120) NOT NULL,
                    color VARCHAR(32) DEFAULT NULL,
                    badge VARCHAR(32) DEFAULT NULL,
                    is_admin TINYINT(1) DEFAULT 0,
                    can_delete_messages TINYINT(1) DEFAULT 0,
                    can_timeout TINYINT(1) DEFAULT 0,
                    can_ban TINYINT(1) DEFAULT 0,
                    can_edit_channels TINYINT(1) DEFAULT 0,
                    can_view_locked TINYINT(1) DEFAULT 0,
                    UNIQUE KEY ux_comm_name (community_id, name)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            } catch (Exception $e) {}

            try {
                $exists = $pdo->prepare("SELECT COUNT(*) FROM community_roles WHERE community_id = ? AND name = ? LIMIT 1");
                $insr = $pdo->prepare("INSERT INTO community_roles (community_id, name, color, badge, is_admin, can_delete_messages, can_timeout, can_ban, can_edit_channels, can_view_locked) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $exists->execute([$community_id, 'Member']); if (!$exists->fetchColumn()) $insr->execute([$community_id, 'Member', null, null, 0, 0, 0, 0, 0, 0]);
                $exists->execute([$community_id, 'Moderator']); if (!$exists->fetchColumn()) $insr->execute([$community_id, 'Moderator', null, '✦', 0, 1, 1, 1, 1, 1]);
                $exists->execute([$community_id, 'Admin']); if (!$exists->fetchColumn()) $insr->execute([$community_id, 'Admin', null, '★', 1, 1, 1, 1, 1, 1]);
            } catch (Exception $e) {
                // non-fatal
            }

            json_ok(['ok' => true, 'id' => $community_id, 'slug' => $slug]);
        } else {
            json_err("failed to create community");
        }
    } catch (Exception $e) {
        json_err("exception creating community: " . $e->getMessage());
    }
    exit;
}

// unknown
json_err("unknown action", 400);
