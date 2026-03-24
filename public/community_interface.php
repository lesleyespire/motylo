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

function table_exists($pdo, $name) {
    try {
        $q = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($name));
        return (bool)$q->fetchColumn();
    } catch (Exception $e) { return false; }
}
function get_table_columns($pdo, $table) {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table`");
        $stmt->execute();
        return array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: []);
    } catch (Exception $e) { return []; }
}

function ensure_private_rooms_columns($pdo) {
    try {
        if (!table_exists($pdo, 'private_rooms')) return;
        $cols = get_table_columns($pdo, 'private_rooms');
        if (!in_array('community_id', $cols, true)) {
            $pdo->exec("ALTER TABLE private_rooms ADD COLUMN community_id INT DEFAULT NULL");
        }
        if (!in_array('required_role_id', $cols, true)) {
            $pdo->exec("ALTER TABLE private_rooms ADD COLUMN required_role_id INT DEFAULT NULL");
        }
        if (!in_array('is_hidden', $cols, true)) {
            $pdo->exec("ALTER TABLE private_rooms ADD COLUMN is_hidden TINYINT(1) NOT NULL DEFAULT 0");
        }
        if (!in_array('is_voice', $cols, true)) {
            $pdo->exec("ALTER TABLE private_rooms ADD COLUMN is_voice TINYINT(1) NOT NULL DEFAULT 0");
        }
    } catch (Exception $e) {
        // ignore migration issues here; create_room will still try best effort
    }
}

function get_user_roles_and_perms($pdo, $community_id, $user_id) {
    $perms = [
        'is_owner' => false,
        'is_admin' => false,
        'can_timeout' => false,
        'can_ban' => false,
        'can_delete_messages' => false,
        'can_view_locked' => false,
        'max_priority' => -1
    ];

    $q = $pdo->prepare("SELECT owner_id FROM communities WHERE id = ? LIMIT 1");
    $q->execute([$community_id]);
    $owner_id = (int)$q->fetchColumn();
    if ($owner_id && $owner_id === (int)$user_id) {
        $perms['is_owner'] = true;
        $perms['is_admin'] = true;
        $perms['can_timeout'] = true;
        $perms['can_ban'] = true;
        $perms['can_delete_messages'] = true;
        $perms['can_view_locked'] = true;
        $perms['max_priority'] = 1000000;
        return $perms;
    }

    $hasMany = table_exists($pdo, 'community_member_roles');
    if ($hasMany) {
        $q = $pdo->prepare("SELECT cr.priority, cr.is_admin, cr.can_timeout, cr.can_ban, cr.can_delete_messages, cr.can_view_locked
                            FROM community_member_roles mr
                            JOIN community_roles cr ON cr.id = mr.role_id
                            WHERE mr.community_id = ? AND mr.user_id = ?");
        $q->execute([$community_id, $user_id]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $perms['max_priority'] = max($perms['max_priority'], (int)$r['priority']);
            if (!empty($r['is_admin'])) $perms['is_admin'] = true;
            if (!empty($r['can_timeout'])) $perms['can_timeout'] = true;
            if (!empty($r['can_ban'])) $perms['can_ban'] = true;
            if (!empty($r['can_delete_messages'])) $perms['can_delete_messages'] = true;
            if (!empty($r['can_view_locked'])) $perms['can_view_locked'] = true;
        }
    } else {
        $q = $pdo->prepare("SELECT cr.priority, cr.is_admin, cr.can_timeout, cr.can_ban, cr.can_delete_messages, cr.can_view_locked
                            FROM community_members cm
                            JOIN community_roles cr ON cr.id = cm.role_id
                            WHERE cm.community_id = ? AND cm.user_id = ? LIMIT 1");
        $q->execute([$community_id, $user_id]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            $perms['max_priority'] = (int)$r['priority'];
            $perms['is_admin'] = !empty($r['is_admin']);
            $perms['can_timeout'] = !empty($r['can_timeout']);
            $perms['can_ban'] = !empty($r['can_ban']);
            $perms['can_delete_messages'] = !empty($r['can_delete_messages']);
            $perms['can_view_locked'] = !empty($r['can_view_locked']);
        }
    }
    return $perms;
}

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
    $u = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
    $u->execute([$username]);
    $user = $u->fetchColumn();
    if (!$user) { echo json_encode(['ok'=>true,'roles'=>[]]); exit; }
    $hasMany = (bool)$pdo->query("SHOW TABLES LIKE 'community_member_roles'")->fetchColumn();
    $roles = [];
    if ($hasMany) {
        $q = $pdo->prepare("SELECT cr.id, cr.name, cr.color, cr.badge
                            FROM community_member_roles mr
                            JOIN community_roles cr ON cr.id = mr.role_id
                            WHERE mr.community_id = ? AND mr.user_id = ?
                            ORDER BY cr.id ASC");
        $q->execute([$community_id, $user]);
        $roles = $q->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $q = $pdo->prepare("SELECT cr.id, cr.name, cr.color, cr.badge
                            FROM community_members cm
                            JOIN community_roles cr ON cr.id = cm.role_id
                            WHERE cm.community_id = ? AND cm.user_id = ? LIMIT 1");
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

    $perms = get_user_roles_and_perms($pdo, $community_id, $me_id);
    $has_any_perm = $perms['is_owner'] || $perms['is_admin'] || $perms['can_timeout'] || $perms['can_ban'];
    if (!$has_any_perm) { echo json_encode(['error'=>'not allowed']); exit; }

    if (!$perms['is_owner']) {
        if ($action_type === 'timeout' && !$perms['can_timeout']) { echo json_encode(['error'=>'missing permission']); exit; }
        if ($action_type === 'ban' && !$perms['can_ban']) { echo json_encode(['error'=>'missing permission']); exit; }
        if (($action_type === 'add_role' || $action_type === 'remove_role') && !$perms['is_admin']) { echo json_encode(['error'=>'missing permission']); exit; }
    }

    if (!$perms['is_owner']) {
        $tq = $pdo->prepare("SELECT owner_id FROM communities WHERE id = ? LIMIT 1");
        $tq->execute([$community_id]);
        $owner_id = (int)$tq->fetchColumn();
        if ($owner_id && $owner_id === $target_user_id) { echo json_encode(['error'=>'cannot act on owner']); exit; }
        $target_perms = get_user_roles_and_perms($pdo, $community_id, $target_user_id);
        if ($target_perms['max_priority'] > $perms['max_priority']) { echo json_encode(['error'=>'cannot act on higher role']); exit; }
    }

    try {
        if ($action_type === 'add_role' || $action_type === 'remove_role') {
            if (!$role_id) { echo json_encode(['error'=>'no role_id']); exit; }
            $r = $pdo->prepare("SELECT id FROM community_roles WHERE id = ? AND community_id = ? LIMIT 1");
            $r->execute([$role_id, $community_id]);
            if (!$r->fetchColumn()) { echo json_encode(['error'=>'invalid role']); exit; }

            if (!$perms['is_owner']) {
                $rP = $pdo->prepare("SELECT priority FROM community_roles WHERE id = ? AND community_id = ? LIMIT 1");
                $rP->execute([$role_id, $community_id]);
                $rolePriority = (int)$rP->fetchColumn();
                if ($rolePriority > $perms['max_priority']) { echo json_encode(['error'=>'role priority too high']); exit; }
            }

            if ($action_type === 'add_role') {
                $pdo->prepare("INSERT IGNORE INTO community_member_roles (community_id, user_id, role_id) VALUES (?, ?, ?)")
                    ->execute([$community_id, $target_user_id, $role_id]);
            } else {
                $pdo->prepare("DELETE FROM community_member_roles WHERE community_id = ? AND user_id = ? AND role_id = ?")
                    ->execute([$community_id, $target_user_id, $role_id]);
            }

            $meta = ['role_id' => $role_id];
            $audit_cols = get_table_columns($pdo, 'community_audit');
            $tu = $pdo->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
            $tu->execute([$target_user_id]);
            $target_username = $tu->fetchColumn();

            if (in_array('action', $audit_cols, true)) {
                $ins = $pdo->prepare("INSERT INTO community_audit (community_id, action, actor_user, target_user, reason) VALUES (?, ?, ?, ?, ?)");
                $ins->execute([$community_id, $action_type, $me_id, $target_user_id, $reason]);
            } else {
                $ins = $pdo->prepare("INSERT INTO community_audit (community_id, moderator_id, moderator_name, target_user_id, target_username, action_type, action_meta, reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([$community_id, $me_id, $me['username'], $target_user_id, $target_username, $action_type, json_encode($meta), $reason]);
            }
            echo json_encode(['ok'=>true]); exit;
        }

        if ($action_type === 'timeout') {
            $minutes = max(1, (int)$duration);
            $cols = get_table_columns($pdo, 'community_timeouts');
            $timeout_col = in_array('timeout_until', $cols, true) ? 'timeout_until' : (in_array('until_dt', $cols, true) ? 'until_dt' : null);
            if (!$timeout_col) {
                $pdo->exec("CREATE TABLE IF NOT EXISTS community_timeouts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    community_id INT NOT NULL,
                    user_id INT NOT NULL,
                    timeout_until DATETIME NOT NULL,
                    reason TEXT NULL,
                    by_user INT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");
                $timeout_col = 'timeout_until';
            }
            $until = (new DateTime())->modify("+{$minutes} minutes")->format('Y-m-d H:i:s');
            $has_by = in_array('by_user', $cols, true);
            if ($has_by) {
                $sql = "INSERT INTO community_timeouts (community_id, user_id, {$timeout_col}, reason, by_user) VALUES (?, ?, ?, ?, ?)";
                $pdo->prepare($sql)->execute([$community_id, $target_user_id, $until, $reason, $me_id]);
            } else {
                $sql = "INSERT INTO community_timeouts (community_id, user_id, {$timeout_col}, reason) VALUES (?, ?, ?, ?)";
                $pdo->prepare($sql)->execute([$community_id, $target_user_id, $until, $reason]);
            }

            $audit_cols = get_table_columns($pdo, 'community_audit');
            $tu = $pdo->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
            $tu->execute([$target_user_id]);
            $target_username = $tu->fetchColumn();
            $meta = ['duration_minutes' => $minutes, 'until' => $until];
            if (in_array('action', $audit_cols, true)) {
                $ins = $pdo->prepare("INSERT INTO community_audit (community_id, action, actor_user, target_user, reason) VALUES (?, ?, ?, ?, ?)");
                $ins->execute([$community_id, 'timeout', $me_id, $target_user_id, $reason]);
            } else {
                $ins = $pdo->prepare("INSERT INTO community_audit (community_id, moderator_id, moderator_name, target_user_id, target_username, action_type, action_meta, reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([$community_id, $me_id, $me['username'], $target_user_id, $target_username, 'timeout', json_encode($meta), $reason]);
            }
            echo json_encode(['ok'=>true, 'until'=>$until]); exit;
        }

        if ($action_type === 'ban') {
            $cols = get_table_columns($pdo, 'community_bans');
            if (empty($cols)) {
                $pdo->exec("CREATE TABLE IF NOT EXISTS community_bans (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    community_id INT NOT NULL,
                    user_id INT NOT NULL,
                    username VARCHAR(128) NULL,
                    banned_by INT NULL,
                    reason TEXT NULL,
                    until_at DATETIME NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");
                $cols = get_table_columns($pdo, 'community_bans');
            }
            $tu = $pdo->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
            $tu->execute([$target_user_id]);
            $target_username = $tu->fetchColumn();
            $has_username = in_array('username', $cols, true);
            $has_banned_by = in_array('banned_by', $cols, true);
            if ($has_username || $has_banned_by) {
                $fields = ['community_id','user_id'];
                $vals = [$community_id, $target_user_id];
                if ($has_username) { $fields[] = 'username'; $vals[] = $target_username; }
                if ($has_banned_by) { $fields[] = 'banned_by'; $vals[] = $me_id; }
                $fields[] = 'reason'; $vals[] = $reason;
                if (in_array('until_at', $cols, true)) { $fields[] = 'until_at'; $vals[] = null; }
                $sql = "INSERT INTO community_bans (" . implode(',', $fields) . ") VALUES (" . implode(',', array_fill(0, count($fields), '?')) . ")";
                $pdo->prepare($sql)->execute($vals);
            } else {
                $pdo->prepare("INSERT INTO community_bans (community_id, user_id, reason) VALUES (?, ?, ?)")
                    ->execute([$community_id, $target_user_id, $reason]);
            }

            $audit_cols = get_table_columns($pdo, 'community_audit');
            if (in_array('action', $audit_cols, true)) {
                $ins = $pdo->prepare("INSERT INTO community_audit (community_id, action, actor_user, target_user, reason) VALUES (?, ?, ?, ?, ?)");
                $ins->execute([$community_id, 'ban', $me_id, $target_user_id, $reason]);
            } else {
                $ins = $pdo->prepare("INSERT INTO community_audit (community_id, moderator_id, moderator_name, target_user_id, target_username, action_type, action_meta, reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([$community_id, $me_id, $me['username'], $target_user_id, $target_username, 'ban', json_encode((object)[]), $reason]);
            }
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
    $q = $pdo->prepare("SELECT owner_id FROM communities WHERE id = ? LIMIT 1");
    $q->execute([$community_id]);
    $owner = $q->fetchColumn();
    if ($owner && (int)$owner === (int)$uid) return true;

    try {
        $hasMany = table_exists($pdo, 'community_member_roles');
        if ($hasMany) {
            $q2 = $pdo->prepare("SELECT 1 FROM community_member_roles mr JOIN community_roles cr ON cr.id = mr.role_id WHERE mr.community_id = ? AND mr.user_id = ? AND cr.is_admin = 1 LIMIT 1");
            $q2->execute([$community_id, $uid]);
            return (bool)$q2->fetchColumn();
        } else {
            $q2 = $pdo->prepare("SELECT cr.is_admin FROM community_members cm JOIN community_roles cr ON cr.id = cm.role_id WHERE cm.community_id = ? AND cm.user_id = ? LIMIT 1");
            $q2->execute([$community_id, $uid]);
            return (bool)$q2->fetchColumn();
        }
    } catch (Exception $e) {
        return false;
    }
}

// Ensure common schema exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS private_rooms (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(128) NOT NULL UNIQUE,
        name VARCHAR(255) NOT NULL,
        community_id INT DEFAULT NULL,
        required_role_id INT DEFAULT NULL,
        is_hidden TINYINT(1) NOT NULL DEFAULT 0,
        is_voice TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    ensure_private_rooms_columns($pdo);
} catch (Exception $e) { /* ignore */ }

// ACTION: create_room
if ($action === 'create_room') {
    // expected POST: name, community_id, optional required_role_id, room_type=text|voice, is_hidden
    $name = trim((string)($_POST['name'] ?? ''));
    $community_id = isset($_POST['community_id']) ? (int)$_POST['community_id'] : 0;
    $required_role_id = isset($_POST['required_role_id']) && $_POST['required_role_id'] !== '' ? (int)$_POST['required_role_id'] : null;
    $room_type = strtolower(trim((string)($_POST['room_type'] ?? 'text')));
    $is_voice = ($room_type === 'voice') ? 1 : 0;
    $is_hidden = !empty($_POST['is_hidden']) ? 1 : 0;

    if ($name === '') json_err("missing name");
    if ($community_id <= 0) json_err("missing community_id");

    // is caller allowed to create room? require community admin/owner
    if (!is_comm_admin($pdo, $community_id, $me_id)) json_err("denied", 403);

    // sanitize name length
    if (mb_strlen($name, 'UTF-8') > 120) $name = mb_substr($name,0,120,'UTF-8');

    // ensure schema
    ensure_private_rooms_columns($pdo);

    // generate unique code: c + 16 hex
    try {
        $tries = 0;
        do {
            $code = 'c' . substr(bin2hex(random_bytes(8)),0,16);
            $ins = $pdo->prepare("INSERT INTO private_rooms (code, name, community_id, required_role_id, is_hidden, is_voice) VALUES (?, ?, ?, ?, ?, ?)");
            $ins->execute([$code, $name, $community_id, $required_role_id, $is_hidden, $is_voice]);
            $ok = true;
        } while(false);
    } catch (Exception $e) {
        // collision or other - try simple fallback loop
        $ok = false;
        for ($i=0;$i<6;$i++){
            $code = 'c' . substr(md5($name . microtime(true) . random_int(1,999999)),0,14);
            try {
                $ins = $pdo->prepare("INSERT INTO private_rooms (code, name, community_id, required_role_id, is_hidden, is_voice) VALUES (?, ?, ?, ?, ?, ?)");
                $ins->execute([$code, $name, $community_id, $required_role_id, $is_hidden, $is_voice]);
                $ok = true;
                break;
            } catch (Exception $e2) {
                $ok = false;
                continue;
            }
        }
        if (!$ok) json_err("failed to create room");
    }

    json_ok([
        "id" => (int)$pdo->lastInsertId(),
        "code" => $code,
        "name" => $name,
        "can_view" => true,
        "nameEscaped" => htmlentities($name, ENT_QUOTES, 'UTF-8'),
        "required_role_id" => $required_role_id,
        "is_hidden" => $is_hidden,
        "is_voice" => $is_voice,
        "room_type" => $is_voice ? 'voice' : 'text'
    ]);
}

// ACTION: create_default_roles (legacy form in community_admin posts here)
if ($action === 'create_default_roles') {
    $community_id = isset($_REQUEST['community_id']) ? (int)$_REQUEST['community_id'] : 0;
    if ($community_id <= 0) json_err("missing community_id");
    if (!is_comm_admin($pdo, $community_id, $me_id)) json_err("denied",403);

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

    $exists = $pdo->prepare("SELECT COUNT(*) FROM community_roles WHERE community_id = ? AND name = ? LIMIT 1");
    $ins = $pdo->prepare("INSERT INTO community_roles (community_id,name,color,badge,is_admin,can_delete_messages,can_timeout,can_ban,can_edit_channels,can_view_locked) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $exists->execute([$community_id, 'Member']); if (!$exists->fetchColumn()) { $ins->execute([$community_id,'Member',null,null,0,0,0,0,0,0]); }
    $exists->execute([$community_id, 'Moderator']); if (!$exists->fetchColumn()) { $ins->execute([$community_id,'Moderator',null,'✦',0,1,1,1,1,1]); }
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

    if ($slug !== '') {
        $slug = strtolower(preg_replace('/[^a-z0-9\\-]+/', '', str_replace(' ', '-', $slug)));
    } else {
        $slug = strtolower(preg_replace('/[^a-z0-9\\-]+/', '', str_replace(' ', '-', mb_substr($name,0,64))));
        if ($slug === '') $slug = 'c' . time();
    }

    $base = $slug;
    $try = 0;
    while ($try < 10) {
        $q = $pdo->prepare("SELECT id FROM communities WHERE slug = ? LIMIT 1");
        $q->execute([$slug]);
        if (!$q->fetchColumn()) break;
        $slug = $base . '-' . mt_rand(100,999);
        $try++;
    }

    $public_id = (string)time() . mt_rand(100,999);

    try {
        $ins = $pdo->prepare("INSERT INTO communities (slug, name, description, owner_id, public_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $ins->execute([$slug, $name, $description !== '' ? $description : null, $me_id, $public_id]);
        $community_id = (int)$pdo->lastInsertId();

        if ($community_id > 0) {
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS community_members (community_id INT NOT NULL, user_id INT NOT NULL, role_id INT DEFAULT NULL, accepted_at DATETIME DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            } catch (Exception $e) {}
            $ins2 = $pdo->prepare("INSERT INTO community_members (community_id, user_id, accepted_at) VALUES (?, ?, NOW())");
            $ins2->execute([$community_id, $me_id]);

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
