<?php
// community_interface.php
// AJAX endpoint for community-level actions and polling helpers.
require "config.php";
header("Content-Type: application/json; charset=utf-8");

function json_err($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(["error" => $msg], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function json_ok($data = []) {
    echo json_encode(array_merge(["ok" => true], $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function table_exists($pdo, $name) {
    try {
        $q = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($name));
        return (bool)$q->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}
function get_table_columns($pdo, $table) {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table`");
        $stmt->execute();
        return array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: []);
    } catch (Exception $e) {
        return [];
    }
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
        // ignore migration issues here
    }
}
function ensure_voice_presence_table($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS voice_room_presence (
            id INT AUTO_INCREMENT PRIMARY KEY,
            room_code VARCHAR(128) NOT NULL,
            user_id INT NOT NULL,
            username VARCHAR(255) DEFAULT NULL,
            last_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY room_user (room_code, user_id),
            KEY idx_room_seen (room_code, last_seen)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {
        // ignore
    }
}
function cleanup_voice_presence($pdo, $olderThanSeconds = 45) {
    try {
        $pdo->exec("DELETE FROM voice_room_presence WHERE last_seen < (NOW() - INTERVAL " . (int)$olderThanSeconds . " SECOND)");
    } catch (Exception $e) {
        // ignore
    }
}
function get_voice_counts($pdo, array $roomCodes) {
    ensure_voice_presence_table($pdo);
    cleanup_voice_presence($pdo, 45);

    $roomCodes = array_values(array_filter(array_map('strval', $roomCodes), fn($v) => $v !== ''));
    if (empty($roomCodes)) return [];

    $counts = [];
    try {
        $placeholders = implode(',', array_fill(0, count($roomCodes), '?'));
        $q = $pdo->prepare("SELECT room_code, COUNT(*) AS c
                            FROM voice_room_presence
                            WHERE room_code IN ($placeholders)
                              AND last_seen >= (NOW() - INTERVAL 45 SECOND)
                            GROUP BY room_code");
        $q->execute($roomCodes);
        while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
            $counts[(string)$row['room_code']] = (int)$row['c'];
        }
    } catch (Exception $e) {
        // ignore
    }

    foreach ($roomCodes as $code) {
        if (!isset($counts[$code])) $counts[$code] = 0;
    }
    return $counts;
}
function ensure_community_tables($pdo) {
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
    } catch (Exception $e) {}

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS community_roles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            community_id INT NOT NULL,
            name VARCHAR(120) NOT NULL,
            color VARCHAR(32) DEFAULT NULL,
            badge VARCHAR(32) DEFAULT NULL,
            priority INT NOT NULL DEFAULT 0,
            is_admin TINYINT(1) DEFAULT 0,
            can_delete_messages TINYINT(1) DEFAULT 0,
            can_timeout TINYINT(1) DEFAULT 0,
            can_ban TINYINT(1) DEFAULT 0,
            can_assign_roles TINYINT(1) NOT NULL DEFAULT 0,
            can_edit_channels TINYINT(1) DEFAULT 0,
            can_view_locked TINYINT(1) DEFAULT 0,
            UNIQUE KEY ux_comm_name (community_id, name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS community_members (
            community_id INT NOT NULL,
            user_id INT NOT NULL,
            role_id INT DEFAULT NULL,
            accepted_at DATETIME DEFAULT NULL,
            PRIMARY KEY (community_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS community_member_roles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            community_id INT NOT NULL,
            user_id INT NOT NULL,
            role_id INT NOT NULL,
            assigned_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_comm_user (community_id, user_id),
            KEY idx_comm_role (community_id, role_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS community_audit (
            id INT AUTO_INCREMENT PRIMARY KEY,
            community_id INT DEFAULT NULL,
            action VARCHAR(64) NOT NULL,
            actor_user INT NOT NULL,
            target_user INT DEFAULT NULL,
            target_message INT DEFAULT NULL,
            reason TEXT DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS community_timeouts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            community_id INT NOT NULL,
            user_id INT NOT NULL,
            actor_user INT DEFAULT NULL,
            reason VARCHAR(255) DEFAULT NULL,
            until_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS community_bans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            community_id INT NOT NULL,
            user_id INT NOT NULL,
            username VARCHAR(128) DEFAULT NULL,
            banned_by INT DEFAULT NULL,
            reason TEXT DEFAULT NULL,
            until_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS dm_calls (
            id INT NOT NULL AUTO_INCREMENT,
            room VARCHAR(64) NOT NULL,
            caller_id INT NOT NULL,
            callee_id INT NOT NULL,
            status ENUM('ringing','accepted','declined','ended','expired') NOT NULL DEFAULT 'ringing',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            responded_at DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_dm_calls_pair_status (caller_id, callee_id, status, expires_at),
            KEY idx_dm_calls_room (room)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {
        // ignore
    }
}
function is_comm_admin($pdo, $community_id, $uid) {
    try {
        $q = $pdo->prepare("SELECT owner_id FROM communities WHERE id = ? LIMIT 1");
        $q->execute([$community_id]);
        $owner = $q->fetchColumn();
        if ($owner && (int)$owner === (int)$uid) return true;

        if (table_exists($pdo, 'community_member_roles')) {
            $q2 = $pdo->prepare("SELECT 1
                                 FROM community_member_roles mr
                                 JOIN community_roles cr ON cr.id = mr.role_id
                                 WHERE mr.community_id = ? AND mr.user_id = ? AND cr.is_admin = 1
                                 LIMIT 1");
            $q2->execute([$community_id, $uid]);
            if ($q2->fetchColumn()) return true;
        } else {
            $q2 = $pdo->prepare("SELECT cr.is_admin
                                 FROM community_members cm
                                 JOIN community_roles cr ON cr.id = cm.role_id
                                 WHERE cm.community_id = ? AND cm.user_id = ?
                                 LIMIT 1");
            $q2->execute([$community_id, $uid]);
            if ($q2->fetchColumn()) return true;
        }
    } catch (Exception $e) {
        // ignore
    }
    return false;
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

    try {
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

        if (table_exists($pdo, 'community_member_roles')) {
            $q = $pdo->prepare("SELECT cr.priority, cr.is_admin, cr.can_timeout, cr.can_ban, cr.can_delete_messages, cr.can_view_locked
                                FROM community_member_roles mr
                                JOIN community_roles cr ON cr.id = mr.role_id
                                WHERE mr.community_id = ? AND mr.user_id = ?");
            $q->execute([$community_id, $user_id]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                $perms['max_priority'] = max($perms['max_priority'], (int)($r['priority'] ?? 0));
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
                $perms['max_priority'] = (int)($r['priority'] ?? 0);
                $perms['is_admin'] = !empty($r['is_admin']);
                $perms['can_timeout'] = !empty($r['can_timeout']);
                $perms['can_ban'] = !empty($r['can_ban']);
                $perms['can_delete_messages'] = !empty($r['can_delete_messages']);
                $perms['can_view_locked'] = !empty($r['can_view_locked']);
            }
        }
    } catch (Exception $e) {
        // ignore
    }

    return $perms;
}
function dm_room_name($a, $b) {
    return 'dmvoice_' . min($a, $b) . '__' . max($a, $b);
}
function format_call_row($row) {
    if (!$row) return null;
    return [
        "id" => (int)$row['id'],
        "room" => $row['room'],
        "caller_id" => (int)$row['caller_id'],
        "callee_id" => (int)$row['callee_id'],
        "status" => (string)$row['status'],
        "created_at" => $row['created_at'],
        "expires_at" => $row['expires_at'],
        "responded_at" => $row['responded_at'] ?? null,
        "caller_username" => $row['caller_username'] ?? null,
        "caller_avatar" => $row['caller_avatar'] ?? null,
        "callee_username" => $row['callee_username'] ?? null,
        "callee_avatar" => $row['callee_avatar'] ?? null,
    ];
}
function fetch_latest_incoming_call_for_user($pdo, $me_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT c.*,
                   u1.username AS caller_username, u1.avatar AS caller_avatar,
                   u2.username AS callee_username, u2.avatar AS callee_avatar
            FROM dm_calls c
            LEFT JOIN users u1 ON u1.id = c.caller_id
            LEFT JOIN users u2 ON u2.id = c.callee_id
            WHERE c.callee_id = :me
              AND c.status = 'ringing'
              AND c.expires_at > NOW()
            ORDER BY c.id DESC
            LIMIT 1
        ");
        $stmt->execute([":me" => $me_id]);
        return format_call_row($stmt->fetch(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        return null;
    }
}
function get_community_voice_counts($pdo, $community_id) {
    ensure_voice_presence_table($pdo);
    cleanup_voice_presence($pdo, 45);

    $roomCodes = [];
    try {
        $s = $pdo->prepare("SELECT code FROM private_rooms WHERE community_id = ? AND is_voice = 1");
        $s->execute([$community_id]);
        $roomCodes = $s->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Exception $e) {
        $roomCodes = [];
    }
    return get_voice_counts($pdo, $roomCodes);
}

if (empty($_COOKIE['auth_token'])) json_err('not logged in', 401);

$stmt = $pdo->prepare("SELECT id, username FROM users WHERE auth_token = ? LIMIT 1");
$stmt->execute([$_COOKIE['auth_token']]);
$me = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$me) json_err('bad login', 401);
$me_id = (int)$me['id'];
$me_username = $me['username'];

ensure_community_tables($pdo);

$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));

if ($action === 'resolve_user') {
    $username = trim((string)($_GET['username'] ?? ''));
    if ($username === '') json_err('no username');

    $s = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
    $s->execute([$username]);
    $uid = $s->fetchColumn();
    if ($uid) json_ok(['user_id' => (int)$uid]);
    json_ok(['ok' => false]);
}

if ($action === 'get_user_roles_by_name') {
    $username = trim((string)($_GET['username'] ?? ''));
    $community_id = (int)($_GET['community_id'] ?? 0);
    if ($username === '' || $community_id <= 0) json_err('missing');

    $u = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
    $u->execute([$username]);
    $user_id = (int)$u->fetchColumn();
    if (!$user_id) json_ok(['roles' => []]);

    $roles = [];
    if (table_exists($pdo, 'community_member_roles')) {
        $q = $pdo->prepare("SELECT cr.id, cr.name, cr.color, cr.badge
                            FROM community_member_roles mr
                            JOIN community_roles cr ON cr.id = mr.role_id
                            WHERE mr.community_id = ? AND mr.user_id = ?
                            ORDER BY cr.id ASC");
        $q->execute([$community_id, $user_id]);
        $roles = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $q = $pdo->prepare("SELECT cr.id, cr.name, cr.color, cr.badge
                            FROM community_members cm
                            JOIN community_roles cr ON cr.id = cm.role_id
                            WHERE cm.community_id = ? AND cm.user_id = ? LIMIT 1");
        $q->execute([$community_id, $user_id]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if ($r) $roles = [$r];
    }
    json_ok(['roles' => $roles]);
}

if ($action === 'voice_counts') {
    $community_id = (int)($_GET['community_id'] ?? $_POST['community_id'] ?? 0);
    if ($community_id <= 0) json_err('missing community_id');
    json_ok([
        'counts' => get_community_voice_counts($pdo, $community_id)
    ]);
}

if ($action === 'call_state') {
    $call = fetch_latest_incoming_call_for_user($pdo, $me_id);
    json_ok(['call' => $call]);
}

if ($action === 'moderate') {
    $data = $_POST;
    $action_type = (string)($data['action_type'] ?? '');
    $community_id = (int)($data['community_id'] ?? 0);
    $target_user_id = (int)($data['target_user_id'] ?? 0);
    $role_id = isset($data['role_id']) && $data['role_id'] !== '' ? (int)$data['role_id'] : null;
    $reason = trim((string)($data['reason'] ?? ''));
    $duration = isset($data['duration_minutes']) ? (int)$data['duration_minutes'] : null;

    if ($action_type === '' || $community_id <= 0 || $target_user_id <= 0) json_err('missing');

    $perms = get_user_roles_and_perms($pdo, $community_id, $me_id);
    $has_any_perm = $perms['is_owner'] || $perms['is_admin'] || $perms['can_timeout'] || $perms['can_ban'];
    if (!$has_any_perm) json_err('not allowed', 403);

    if (!$perms['is_owner']) {
        if ($action_type === 'timeout' && !$perms['can_timeout']) json_err('missing permission', 403);
        if ($action_type === 'ban' && !$perms['can_ban']) json_err('missing permission', 403);
        if (($action_type === 'add_role' || $action_type === 'remove_role') && !$perms['is_admin']) json_err('missing permission', 403);
    }

    if (!$perms['is_owner']) {
        $tq = $pdo->prepare("SELECT owner_id FROM communities WHERE id = ? LIMIT 1");
        $tq->execute([$community_id]);
        $owner_id = (int)$tq->fetchColumn();
        if ($owner_id && $owner_id === $target_user_id) json_err('cannot act on owner', 403);

        $target_perms = get_user_roles_and_perms($pdo, $community_id, $target_user_id);
        if ($target_perms['max_priority'] > $perms['max_priority']) json_err('cannot act on higher role', 403);
    }

    try {
        if ($action_type === 'add_role' || $action_type === 'remove_role') {
            if (!$role_id) json_err('no role_id');

            $r = $pdo->prepare("SELECT id, priority FROM community_roles WHERE id = ? AND community_id = ? LIMIT 1");
            $r->execute([$role_id, $community_id]);
            $roleRow = $r->fetch(PDO::FETCH_ASSOC);
            if (!$roleRow) json_err('invalid role');

            if (!$perms['is_owner']) {
                $rolePriority = (int)($roleRow['priority'] ?? 0);
                if ($rolePriority > $perms['max_priority']) json_err('role priority too high', 403);
            }

            if ($action_type === 'add_role') {
                $pdo->prepare("INSERT IGNORE INTO community_member_roles (community_id, user_id, role_id) VALUES (?, ?, ?)")
                    ->execute([$community_id, $target_user_id, $role_id]);
            } else {
                $pdo->prepare("DELETE FROM community_member_roles WHERE community_id = ? AND user_id = ? AND role_id = ?")
                    ->execute([$community_id, $target_user_id, $role_id]);
            }

            $tu = $pdo->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
            $tu->execute([$target_user_id]);
            $target_username = $tu->fetchColumn();

            $audit_cols = get_table_columns($pdo, 'community_audit');
            if (in_array('action', $audit_cols, true)) {
                $ins = $pdo->prepare("INSERT INTO community_audit (community_id, action, actor_user, target_user, reason) VALUES (?, ?, ?, ?, ?)");
                $ins->execute([$community_id, $action_type, $me_id, $target_user_id, $reason]);
            }
            json_ok();
        }

        if ($action_type === 'timeout') {
            $minutes = max(1, (int)$duration);
            $until = (new DateTime())->modify("+{$minutes} minutes")->format('Y-m-d H:i:s');

            $cols = get_table_columns($pdo, 'community_timeouts');
            if (!in_array('timeout_until', $cols, true)) {
                if (!in_array('until_dt', $cols, true)) {
                    // schema fallback
                    try {
                        $pdo->exec("ALTER TABLE community_timeouts ADD COLUMN timeout_until DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
                    } catch (Exception $e) {}
                }
            }

            if (in_array('timeout_until', $cols, true)) {
                $sql = "INSERT INTO community_timeouts (community_id, user_id, timeout_until, reason, actor_user) VALUES (?, ?, ?, ?, ?)";
                $pdo->prepare($sql)->execute([$community_id, $target_user_id, $until, $reason, $me_id]);
            } elseif (in_array('until_dt', $cols, true)) {
                $sql = "INSERT INTO community_timeouts (community_id, user_id, until_dt, reason, actor_user) VALUES (?, ?, ?, ?, ?)";
                $pdo->prepare($sql)->execute([$community_id, $target_user_id, $until, $reason, $me_id]);
            } else {
                $pdo->prepare("INSERT INTO community_timeouts (community_id, user_id, actor_user, reason, until_at) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$community_id, $target_user_id, $me_id, $reason, $until]);
            }

            $tu = $pdo->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
            $tu->execute([$target_user_id]);
            $target_username = $tu->fetchColumn();

            $audit_cols = get_table_columns($pdo, 'community_audit');
            if (in_array('action', $audit_cols, true)) {
                $ins = $pdo->prepare("INSERT INTO community_audit (community_id, action, actor_user, target_user, reason) VALUES (?, ?, ?, ?, ?)");
                $ins->execute([$community_id, 'timeout', $me_id, $target_user_id, $reason]);
            }
            json_ok(['until' => $until]);
        }

        if ($action_type === 'ban') {
            $tu = $pdo->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
            $tu->execute([$target_user_id]);
            $target_username = $tu->fetchColumn();

            $pdo->prepare("INSERT INTO community_bans (community_id, user_id, username, banned_by, reason, until_at) VALUES (?, ?, ?, ?, ?, NULL)")
                ->execute([$community_id, $target_user_id, $target_username, $me_id, $reason]);

            $audit_cols = get_table_columns($pdo, 'community_audit');
            if (in_array('action', $audit_cols, true)) {
                $ins = $pdo->prepare("INSERT INTO community_audit (community_id, action, actor_user, target_user, reason) VALUES (?, ?, ?, ?, ?)");
                $ins->execute([$community_id, 'ban', $me_id, $target_user_id, $reason]);
            }
            json_ok();
        }

        json_err('unknown action');
    } catch (Exception $e) {
        json_err('exception', 500);
    }
}

if ($action === 'create_room') {
    $name = trim((string)($_POST['name'] ?? ''));
    $community_id = isset($_POST['community_id']) ? (int)$_POST['community_id'] : 0;
    $required_role_id = isset($_POST['required_role_id']) && $_POST['required_role_id'] !== '' ? (int)$_POST['required_role_id'] : null;
    $room_type = strtolower(trim((string)($_POST['room_type'] ?? 'text')));
    $is_voice = ($room_type === 'voice') ? 1 : 0;
    $is_hidden = !empty($_POST['is_hidden']) ? 1 : 0;

    if ($name === '') json_err('missing name');
    if ($community_id <= 0) json_err('missing community_id');
    if (!is_comm_admin($pdo, $community_id, $me_id)) json_err('denied', 403);

    if (mb_strlen($name, 'UTF-8') > 120) $name = mb_substr($name, 0, 120, 'UTF-8');

    ensure_private_rooms_columns($pdo);

    $code = null;
    try {
        for ($i = 0; $i < 8; $i++) {
            $code = 'c' . substr(bin2hex(random_bytes(8)), 0, 16);
            try {
                $ins = $pdo->prepare("INSERT INTO private_rooms (code, name, community_id, required_role_id, is_hidden, is_voice) VALUES (?, ?, ?, ?, ?, ?)");
                $ins->execute([$code, $name, $community_id, $required_role_id, $is_hidden, $is_voice]);
                break;
            } catch (Exception $e) {
                $code = null;
            }
        }
        if (!$code) json_err('failed to create room', 500);
    } catch (Exception $e) {
        json_err('failed to create room', 500);
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

if ($action === 'create_default_roles') {
    $community_id = isset($_REQUEST['community_id']) ? (int)$_REQUEST['community_id'] : 0;
    if ($community_id <= 0) json_err('missing community_id');
    if (!is_comm_admin($pdo, $community_id, $me_id)) json_err('denied', 403);

    try {
        $exists = $pdo->prepare("SELECT COUNT(*) FROM community_roles WHERE community_id = ? AND name = ? LIMIT 1");
        $ins = $pdo->prepare("INSERT INTO community_roles
            (community_id, name, color, badge, priority, is_admin, can_delete_messages, can_timeout, can_ban, can_assign_roles, can_edit_channels, can_view_locked)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $exists->execute([$community_id, 'Member']);
        if (!$exists->fetchColumn()) {
            $ins->execute([$community_id, 'Member', null, null, 0, 0, 0, 0, 0, 0, 0, 0]);
        }

        $exists->execute([$community_id, 'Moderator']);
        if (!$exists->fetchColumn()) {
            $ins->execute([$community_id, 'Moderator', null, '✦', 10, 0, 1, 1, 1, 0, 1, 1]);
        }

        $exists->execute([$community_id, 'Admin']);
        if (!$exists->fetchColumn()) {
            $ins->execute([$community_id, 'Admin', null, '★', 100, 1, 1, 1, 1, 1, 1, 1]);
        }

        json_ok();
    } catch (Exception $e) {
        json_err('failed to create default roles', 500);
    }
}

if ($action === 'create_community') {
    $name = trim((string)($_POST['name'] ?? ''));
    $slug = trim((string)($_POST['slug'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));

    if ($name === '') json_err('missing name');

    if ($slug !== '') {
        $slug = strtolower(preg_replace('/[^a-z0-9\\-]+/', '', str_replace(' ', '-', $slug)));
    } else {
        $slug = strtolower(preg_replace('/[^a-z0-9\\-]+/', '', str_replace(' ', '-', mb_substr($name, 0, 64))));
        if ($slug === '') $slug = 'c' . time();
    }

    $base = $slug;
    for ($try = 0; $try < 10; $try++) {
        $q = $pdo->prepare("SELECT id FROM communities WHERE slug = ? LIMIT 1");
        $q->execute([$slug]);
        if (!$q->fetchColumn()) break;
        $slug = $base . '-' . mt_rand(100, 999);
    }

    $public_id = (string)time() . mt_rand(100, 999);

    try {
        $ins = $pdo->prepare("INSERT INTO communities (slug, name, description, owner_id, public_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $ins->execute([$slug, $name, $description !== '' ? $description : null, $me_id, $public_id]);
        $community_id = (int)$pdo->lastInsertId();

        if ($community_id > 0) {
            $pdo->prepare("INSERT INTO community_members (community_id, user_id, accepted_at) VALUES (?, ?, NOW())")
                ->execute([$community_id, $me_id]);

            $exists = $pdo->prepare("SELECT COUNT(*) FROM community_roles WHERE community_id = ? AND name = ? LIMIT 1");
            $insr = $pdo->prepare("INSERT INTO community_roles
                (community_id, name, color, badge, priority, is_admin, can_delete_messages, can_timeout, can_ban, can_assign_roles, can_edit_channels, can_view_locked)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            $exists->execute([$community_id, 'Member']);
            if (!$exists->fetchColumn()) $insr->execute([$community_id, 'Member', null, null, 0, 0, 0, 0, 0, 0, 0, 0]);

            $exists->execute([$community_id, 'Moderator']);
            if (!$exists->fetchColumn()) $insr->execute([$community_id, 'Moderator', null, '✦', 10, 0, 1, 1, 1, 0, 1, 1]);

            $exists->execute([$community_id, 'Admin']);
            if (!$exists->fetchColumn()) $insr->execute([$community_id, 'Admin', null, '★', 100, 1, 1, 1, 1, 1, 1, 1]);

            json_ok(['id' => $community_id, 'slug' => $slug]);
        }

        json_err('failed to create community', 500);
    } catch (Exception $e) {
        json_err('exception creating community: ' . $e->getMessage(), 500);
    }
}

json_err('unknown action', 400);
