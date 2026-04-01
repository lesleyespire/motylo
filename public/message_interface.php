<?php
// message_interface.php - DM server interface with in-band voice call state
require "config.php";
header("Content-Type: application/json; charset=utf-8");
set_time_limit(30);

function json_err($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(["error" => $msg], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function json_ok($data = []) {
    echo json_encode(array_merge(["ok" => true], $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
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
function try_exec($pdo, $sql) {
    try { $pdo->exec($sql); return true; } catch (Exception $e) { return false; }
}
function try_add_column($pdo, $table, $definition) {
    try { $pdo->exec("ALTER TABLE `$table` ADD COLUMN $definition"); return true; }
    catch (Exception $e) { return false; }
}
function create_notification($pdo, $user_id, $type, $source_user_id, $message, $context = null, $ref_id = null) {
    if (!$user_id) return;
    try {
        if (!function_exists('send_user_notification')) {
            $path = __DIR__ . '/notifications_lib.php';
            if (is_file($path)) require_once $path;
        }
        $important = 1;
        if (function_exists('send_user_notification')) {
            @send_user_notification(
                $pdo,
                (int)$user_id,
                (string)$message,
                (string)$type,
                $source_user_id ? (int)$source_user_id : null,
                $ref_id ? (int)$ref_id : null,
                $important
            );
            return;
        }
    } catch (Exception $e) {
        // fall through
    }
    try {
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, source_user_id, message, context, ref_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $type, $source_user_id, mb_substr($message,0,240), $context, $ref_id ?: null]);
    } catch (Exception $e) {
        // ignore
    }
}

function get_relationship($pdo, $a, $b) {
    $ua = min($a, $b);
    $ub = max($a, $b);
    $stmt = $pdo->prepare("SELECT * FROM friendships WHERE user_a = ? AND user_b = ? LIMIT 1");
    $stmt->execute([$ua, $ub]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ["status"=>"none","initiator"=>null,"allowed"=>false,"requested_kind"=>null];
    $status = $row['status'];
    $allowed = in_array($status, ['friends','acquaintance'], true);
    return [
        "status" => $status,
        "initiator" => $row['initiator'] ?? null,
        "allowed" => $allowed,
        "requested_kind" => $row['requested_kind'] ?? null
    ];
}
function check_block($pdo, $who, $whom) {
    $stmt = $pdo->prepare("SELECT 1 FROM blocks WHERE blocker_id = ? AND blocked_id = ? LIMIT 1");
    $stmt->execute([$who, $whom]);
    return (bool)$stmt->fetchColumn();
}
function ensure_dm_schema($pdo) {
    try {
        if (empty($pdo->query("SHOW TABLES LIKE 'dm_messages'")->fetchAll())) {
            try_exec($pdo, "CREATE TABLE IF NOT EXISTS dm_messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                username VARCHAR(128) NOT NULL,
                target_user_id INT NOT NULL,
                message TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                edited_at DATETIME NULL DEFAULT NULL,
                deleted_at DATETIME NULL DEFAULT NULL,
                deleted_by INT NULL DEFAULT NULL,
                reply_to INT NULL DEFAULT NULL,
                INDEX (user_id),
                INDEX (target_user_id),
                INDEX (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        if (empty($pdo->query("SHOW TABLES LIKE 'dm_typing'")->fetchAll())) {
            try_exec($pdo, "CREATE TABLE IF NOT EXISTS dm_typing (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                username VARCHAR(128) NOT NULL,
                target_user_id INT NOT NULL,
                typing_until DATETIME NOT NULL,
                UNIQUE KEY ux_user_target (user_id, target_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } else {
            $cols = get_table_columns($pdo, 'dm_typing');
            if (!in_array('username', $cols)) try_add_column($pdo, 'dm_typing', "username VARCHAR(128) NOT NULL DEFAULT ''");
            if (!in_array('typing_until', $cols)) try_add_column($pdo, 'dm_typing', "typing_until DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
        }

        if (empty($pdo->query("SHOW TABLES LIKE 'friendships'")->fetchAll())) {
            try_exec($pdo, "CREATE TABLE IF NOT EXISTS friendships (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_a INT NOT NULL,
                user_b INT NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'requested',
                initiator INT NULL,
                requested_kind VARCHAR(32) NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY ux_pair (user_a, user_b)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } else {
            $cols = get_table_columns($pdo, 'friendships');
            if (!in_array('requested_kind', $cols)) try_add_column($pdo, 'friendships', "requested_kind VARCHAR(32) NULL DEFAULT NULL");
        }

        if (empty($pdo->query("SHOW TABLES LIKE 'blocks'")->fetchAll())) {
            try_exec($pdo, "CREATE TABLE IF NOT EXISTS blocks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                blocker_id INT NOT NULL,
                blocked_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY ux_block (blocker_id, blocked_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        if (empty($pdo->query("SHOW TABLES LIKE 'dm_calls'")->fetchAll())) {
            try_exec($pdo, "CREATE TABLE IF NOT EXISTS dm_calls (
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
        }
    } catch (Exception $e) {
        // ignore
    }
}
function dm_room_name($a, $b) {
    return 'dmvoice_' . min($a, $b) . '__' . max($a, $b);
}
function get_active_dm_call($pdo, $me_id, $target_id, $last_call_id = 0, $last_call_status = '') {
    try {
        $stmt = $pdo->prepare("
            SELECT c.*,
                   u1.username AS caller_username, u1.avatar AS caller_avatar,
                   u2.username AS callee_username, u2.avatar AS callee_avatar
            FROM dm_calls c
            LEFT JOIN users u1 ON u1.id = c.caller_id
            LEFT JOIN users u2 ON u2.id = c.callee_id
            WHERE (
                (c.caller_id = :me AND c.callee_id = :them)
                OR
                (c.caller_id = :them AND c.callee_id = :me)
            )
            AND c.expires_at > NOW()
            ORDER BY c.id DESC
            LIMIT 1
        ");
        $stmt->execute([":me" => $me_id, ":them" => $target_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $id = (int)$row['id'];
        $status = (string)$row['status'];
        if ($id <= (int)$last_call_id && ($last_call_status === '' || $status === $last_call_status)) {
            return null;
        }

        return [
            "id" => $id,
            "room" => $row['room'],
            "caller_id" => (int)$row['caller_id'],
            "callee_id" => (int)$row['callee_id'],
            "status" => $status,
            "created_at" => $row['created_at'],
            "expires_at" => $row['expires_at'],
            "responded_at" => $row['responded_at'] ?? null,
            "caller_username" => $row['caller_username'] ?? null,
            "caller_avatar" => $row['caller_avatar'] ?? null,
            "callee_username" => $row['callee_username'] ?? null,
            "callee_avatar" => $row['callee_avatar'] ?? null,
        ];
    } catch (Exception $e) {
        return null;
    }
}

if (empty($_COOKIE["auth_token"])) json_err("not logged in", 401);

$stmt = $pdo->prepare("
    SELECT u.id, u.username, u.avatar, u.role_id, u.timeout_until,
           r.name AS role, r.color AS role_color, r.badge AS role_badge
    FROM users u
    LEFT JOIN roles r ON r.id = u.role_id
    WHERE u.auth_token = ?
");
$stmt->execute([$_COOKIE["auth_token"]]);
$current_user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$current_user) json_err("bad login", 401);

$me_id = (int)$current_user['id'];
$me_username = $current_user['username'];
$me_avatar = $current_user['avatar'] ?? null;

$target_name = trim((string)($_GET['user'] ?? ''));
if ($target_name === '') json_err("missing user", 400);

$stmt = $pdo->prepare("
    SELECT u.id,u.username,u.avatar,u.bio,u.created_at,
           r.name AS role, r.color AS role_color, r.badge AS role_badge
    FROM users u
    LEFT JOIN roles r ON r.id = u.role_id
    WHERE u.username = ? LIMIT 1
");
$stmt->execute([$target_name]);
$target = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$target) json_err("target not found", 404);
$target_id = (int)$target['id'];

ensure_dm_schema($pdo);

$rel = get_relationship($pdo, $me_id, $target_id);
$blocked_by_me = check_block($pdo, $me_id, $target_id);
$blocked_by_them = check_block($pdo, $target_id, $me_id);
$relationship_status = $rel['status'];
$relationship_allowed = $rel['allowed'] && !$blocked_by_me && !$blocked_by_them;

$mode = (string)($_GET['mode'] ?? '');
$body = $_POST;

// typing
if ($mode === 'typing') {
    try {
        $sql = "INSERT INTO dm_typing (user_id, username, target_user_id, typing_until)
                VALUES (:uid, :username, :target, DATE_ADD(NOW(), INTERVAL 3 SECOND))
                ON DUPLICATE KEY UPDATE typing_until = DATE_ADD(NOW(), INTERVAL 3 SECOND), username = :username2";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([":uid"=>$me_id, ":username"=>$me_username, ":target"=>$target_id, ":username2"=>$me_username]);
    } catch (Exception $e) {}
    json_ok();
}

// start/invite voice call
if ($mode === 'voice_call_invite') {
    if ($blocked_by_me || $blocked_by_them) json_err("blocked", 403);
    if (!$rel['allowed']) json_err("not allowed", 403);

    $room = dm_room_name($me_id, $target_id);
    try {
        $pdo->prepare("UPDATE dm_calls
                       SET status = 'ended', responded_at = NOW()
                       WHERE room = ? AND status IN ('ringing','accepted')")
            ->execute([$room]);

        $stmt = $pdo->prepare("
            INSERT INTO dm_calls (room, caller_id, callee_id, status, expires_at)
            VALUES (?, ?, ?, 'ringing', DATE_ADD(NOW(), INTERVAL 45 SECOND))
        ");
        $stmt->execute([$room, $me_id, $target_id]);
        $call_id = (int)$pdo->lastInsertId();
        json_ok(["room" => $room, "call_id" => $call_id]);
    } catch (Exception $e) {
        json_err("call invite failed", 500);
    }
}

// accept voice call
if ($mode === 'voice_call_accept') {
    $room = dm_room_name($me_id, $target_id);
    try {
        $stmt = $pdo->prepare("
            UPDATE dm_calls
            SET status = 'accepted', responded_at = NOW()
            WHERE room = ? AND callee_id = ? AND caller_id = ? AND status = 'ringing'
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$room, $me_id, $target_id]);
        json_ok(["room" => $room]);
    } catch (Exception $e) {
        json_err("accept failed", 500);
    }
}

// dismiss/decline voice call
if ($mode === 'voice_call_dismiss') {
    $room = dm_room_name($me_id, $target_id);
    try {
        $stmt = $pdo->prepare("
            UPDATE dm_calls
            SET status = 'declined', responded_at = NOW()
            WHERE room = ? AND callee_id = ? AND caller_id = ? AND status = 'ringing'
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$room, $me_id, $target_id]);
        json_ok(["room" => $room]);
    } catch (Exception $e) {
        json_err("dismiss failed", 500);
    }
}

// end voice call
if ($mode === 'voice_call_end') {
    $room = dm_room_name($me_id, $target_id);
    try {
        $stmt = $pdo->prepare("
            UPDATE dm_calls
            SET status = 'ended', responded_at = NOW()
            WHERE room = ? AND status IN ('ringing','accepted')
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$room]);
        json_ok(["room" => $room]);
    } catch (Exception $e) {
        json_err("end failed", 500);
    }
}

// voice_start (legacy compatibility)
if ($mode === 'voice_start') {
    $code = substr(bin2hex(random_bytes(5)), 0, 12);
    $cols = $pdo->query("SHOW TABLES LIKE 'private_rooms'")->fetchAll();
    if (!empty($cols)) {
        $columns = get_table_columns($pdo, 'private_rooms');
        $label = "DM voice: {$current_user['username']} <-> {$target['username']}";
        if (in_array('code', $columns)) {
            try { $stmt = $pdo->prepare("INSERT INTO private_rooms (code, name) VALUES (?, ?)"); $stmt->execute([$code, $label]); } catch (Exception $e) {}
        } elseif (in_array('room_code', $columns)) {
            try { $stmt = $pdo->prepare("INSERT INTO private_rooms (room_code, name) VALUES (?, ?)"); $stmt->execute([$code, $label]); } catch (Exception $e) {}
        }
    }
    json_ok(["room_code" => $code]);
}

// send
if ($mode === 'send') {
    $msg = trim((string)($body['message'] ?? ''));
    $reply_to = isset($body['reply_to']) ? (int)$body['reply_to'] : null;
    if ($msg === '') json_err("empty");
    if (mb_strlen($msg) > 750) json_err("too long");
    if ($blocked_by_me) json_err("you_blocked");
    if ($blocked_by_them) json_err("blocked_by_target");
    if (!$rel['allowed']) json_err("not allowed");

    try {
        $stmt = $pdo->prepare("INSERT INTO dm_messages (user_id, username, target_user_id, message, reply_to) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$me_id, $me_username, $target_id, $msg, $reply_to ?: null]);
        $insertedId = (int)$pdo->lastInsertId();

        if ($target_id && $target_id !== $me_id) {
            create_notification($pdo, $target_id, 'dm', $me_id, "{$me_username} sent you a DM", 'dm', $insertedId);
        }

        json_ok(["id" => $insertedId]);
    } catch (Exception $e) {
        json_err("send failed", 500);
    }
}

// edit
if ($mode === 'edit') {
    $id = (int)($body['id'] ?? 0);
    $text = trim((string)($body['message'] ?? ''));
    if ($id <= 0 || $text === '') json_err("invalid");
    if (mb_strlen($text) > 750) json_err("too long");

    $stmt = $pdo->prepare("SELECT user_id, created_at, deleted_at FROM dm_messages WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $m = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$m) json_err("not found");
    if ($m['deleted_at']) json_err("deleted");
    if ((int)$m['user_id'] !== $me_id) json_err("denied");
    $created = strtotime($m['created_at']);
    if ($created === false || (time() - $created) > 600) json_err("edit window expired");

    $stmt = $pdo->prepare("UPDATE dm_messages SET message = :msg, edited_at = NOW() WHERE id = :id");
    $stmt->execute([":msg"=>$text, ":id"=>$id]);
    json_ok();
}

// delete - blocked in DMs
if ($mode === 'delete') {
    json_err("deletes not allowed in DMs", 403);
}

// friend_action
if ($mode === 'friend_action') {
    $action_raw = (string)($body['action'] ?? '');
    $map = [
        'request_friend' => 'request_friend',
        'send' => 'request_friend',
        'accept_friend_request' => 'accept_friend',
        'accept' => 'accept_friend',
        'decline_friend_request' => 'decline_friend',
        'decline' => 'decline_friend',
        'cancel_friend_request' => 'cancel_request',
        'cancel' => 'cancel_request',
        'remove_friend' => 'remove_friend',
        'remove' => 'remove_friend',
        'request_acquaintance' => 'request_acquaintance',
        'acquaintance' => 'request_acquaintance',
        'accept_acquaintance_request' => 'accept_acquaintance',
        'remove_acquaintance' => 'remove_acquaintance',
        'promote' => 'promote'
    ];
    $action = $map[$action_raw] ?? $action_raw;

    $ua = min($me_id, $target_id);
    $ub = max($me_id, $target_id);
    $stmt = $pdo->prepare("SELECT * FROM friendships WHERE user_a = ? AND user_b = ? LIMIT 1");
    $stmt->execute([$ua, $ub]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    try {
        if ($action === 'request_friend') {
            if ($row) {
                $stmt = $pdo->prepare("UPDATE friendships SET status = 'requested', initiator = ?, requested_kind = 'friend', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$me_id, $row['id']]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO friendships (user_a, user_b, status, initiator, requested_kind) VALUES (?, ?, 'requested', ?, 'friend')");
                $stmt->execute([$ua, $ub, $me_id]);
            }
            if ($target_id !== $me_id) create_notification($pdo, $target_id, 'friend_request', $me_id, "{$me_username} sent you a friend request", 'friendship');
            json_ok();
        } elseif ($action === 'request_acquaintance') {
            if ($row) {
                $stmt = $pdo->prepare("UPDATE friendships SET status = 'requested', initiator = ?, requested_kind = 'acquaintance', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$me_id, $row['id']]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO friendships (user_a, user_b, status, initiator, requested_kind) VALUES (?, ?, 'requested', ?, 'acquaintance')");
                $stmt->execute([$ua, $ub, $me_id]);
            }
            if ($target_id !== $me_id) create_notification($pdo, $target_id, 'friend_request', $me_id, "{$me_username} sent you an acquaintance request", 'friendship');
            json_ok();
        } elseif ($action === 'accept_friend') {
            if (!$row || $row['status'] !== 'requested') json_err("no request");
            if ((int)$row['initiator'] === $me_id) json_err("invalid");
            $rk = $row['requested_kind'] ?? 'friend';
            if ($rk !== 'friend') json_err("mismatch kind");
            $initiator = (int)$row['initiator'];
            $stmt = $pdo->prepare("UPDATE friendships SET status = 'friends', initiator = NULL, requested_kind = NULL, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$row['id']]);
            if ($initiator && $initiator !== $me_id) create_notification($pdo, $initiator, 'friend_accept', $me_id, "{$me_username} accepted your friend request", 'friendship');
            json_ok();
        } elseif ($action === 'accept_acquaintance') {
            if (!$row) json_err("no request");
            if ($row['status'] === 'requested') {
                if ((int)$row['initiator'] === $me_id) json_err("invalid");
                $rk = $row['requested_kind'] ?? null;
                if ($rk !== null && $rk !== 'acquaintance') json_err("mismatch kind");
                $initiator = (int)$row['initiator'];
                $stmt = $pdo->prepare("UPDATE friendships SET status = 'acquaintance', initiator = NULL, requested_kind = NULL, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$row['id']]);
                if ($initiator && $initiator !== $me_id) create_notification($pdo, $initiator, 'friend_accept', $me_id, "{$me_username} accepted your acquaintance request", 'friendship');
                json_ok();
            }
            if ($row['status'] === 'acquaintance') json_ok();
            if ($row['status'] === 'friends') json_err("already friends");
            json_err("no request");
        } elseif ($action === 'decline_friend') {
            if (!$row || $row['status'] !== 'requested') json_err("no request");
            if ((int)$row['initiator'] === $me_id) json_err("invalid");
            $stmt = $pdo->prepare("DELETE FROM friendships WHERE id = ?");
            $stmt->execute([$row['id']]);
            json_ok();
        } elseif ($action === 'cancel_request') {
            if (!$row) json_err("none");
            if ((int)$row['initiator'] !== $me_id) json_err("not allowed", 403);
            $stmt = $pdo->prepare("DELETE FROM friendships WHERE id = ?");
            $stmt->execute([$row['id']]);
            json_ok();
        } elseif ($action === 'remove_friend') {
            if ($row) {
                $stmt = $pdo->prepare("DELETE FROM friendships WHERE id = ?");
                $stmt->execute([$row['id']]);
            }
            json_ok();
        } elseif ($action === 'remove_acquaintance') {
            if (!$row) json_ok();
            if ($row['status'] === 'acquaintance' || $row['status'] === 'friends') {
                $stmt = $pdo->prepare("DELETE FROM friendships WHERE id = ?");
                $stmt->execute([$row['id']]);
                json_ok();
            } elseif ($row['status'] === 'requested' && (int)$row['initiator'] === $me_id) {
                $stmt = $pdo->prepare("DELETE FROM friendships WHERE id = ?");
                $stmt->execute([$row['id']]);
                json_ok();
            } else {
                json_err("not allowed", 403);
            }
        } else {
            json_err("unknown action");
        }
    } catch (Exception $e) {
        json_err("friend action failed", 500);
    }
}

// block_action
if ($mode === 'block_action') {
    $action = (string)($body['action'] ?? '');
    if ($action === 'block') {
        try {
            $stmt = $pdo->prepare("INSERT IGNORE INTO blocks (blocker_id, blocked_id) VALUES (?, ?)");
            $stmt->execute([$me_id, $target_id]);
            $ua = min($me_id, $target_id);
            $ub = max($me_id, $target_id);
            $stmt = $pdo->prepare("DELETE FROM friendships WHERE user_a = ? AND user_b = ?");
            $stmt->execute([$ua, $ub]);
            json_ok();
        } catch (Exception $e) {
            json_err("block failed", 500);
        }
    } elseif ($action === 'unblock') {
        try {
            $stmt = $pdo->prepare("DELETE FROM blocks WHERE blocker_id = ? AND blocked_id = ?");
            $stmt->execute([$me_id, $target_id]);
            json_ok();
        } catch (Exception $e) {
            json_err("unblock failed", 500);
        }
    } else {
        json_err("unknown block action");
    }
}

// cleanup old DM messages
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM dm_messages WHERE (user_id = :a AND target_user_id = :b) OR (user_id = :b AND target_user_id = :a)");
    $stmt->execute([":a"=>$me_id, ":b"=>$target_id]);
    $count = (int)$stmt->fetchColumn();
    if ($count > 500) {
        $del = $pdo->prepare("DELETE FROM dm_messages WHERE id = (SELECT id FROM (SELECT id FROM dm_messages WHERE (user_id = :a AND target_user_id = :b) OR (user_id = :b AND target_user_id = :a) ORDER BY created_at ASC LIMIT 1) x)");
        $del->execute([":a"=>$me_id, ":b"=>$target_id]);
    }
} catch (Exception $e) {}

// fetch messages
$since = isset($_GET["since"]) ? (int)$_GET["since"] : 0;
$call_since = isset($_GET["call_since"]) ? (int)$_GET["call_since"] : 0;
$call_status = (string)($_GET["call_status"] ?? '');

$timeout_seconds = 25;
$interval_usec = 500000;
$messages = [];
$call = null;

try {
    if ($since > 0 || $call_since > 0) {
        $start = time();
        while ((time() - $start) < $timeout_seconds) {
            $sql = "SELECT m.id, m.username, m.user_id, m.message, m.created_at, m.edited_at, m.deleted_at, m.deleted_by, m.reply_to,
                           rm.username AS reply_to_username, rm.message AS reply_to_message,
                           u.avatar, r.name AS role, r.color AS role_color, r.badge AS role_badge
                    FROM dm_messages m
                    LEFT JOIN dm_messages rm ON rm.id = m.reply_to
                    LEFT JOIN users u ON u.id = m.user_id
                    LEFT JOIN roles r ON r.id = u.role_id
                    WHERE ((m.user_id = :me AND m.target_user_id = :them) OR (m.user_id = :them AND m.target_user_id = :me))
                      AND m.id > :since
                    ORDER BY m.id ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([":me"=>$me_id, ":them"=>$target_id, ":since"=>$since]);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $call = get_active_dm_call($pdo, $me_id, $target_id, $call_since, $call_status);

            if (!empty($messages) || $call) break;
            usleep($interval_usec);
        }
    } else {
        if ($relationship_allowed) {
            $sql = "SELECT m.id, m.username, m.user_id, m.message, m.created_at, m.edited_at, m.deleted_at, m.deleted_by, m.reply_to,
                           rm.username AS reply_to_username, rm.message AS reply_to_message,
                           u.avatar, r.name AS role, r.color AS role_color, r.badge AS role_badge
                    FROM dm_messages m
                    LEFT JOIN dm_messages rm ON rm.id = m.reply_to
                    LEFT JOIN users u ON u.id = m.user_id
                    LEFT JOIN roles r ON r.id = u.role_id
                    WHERE (m.user_id = :me AND m.target_user_id = :them) OR (m.user_id = :them AND m.target_user_id = :me)
                    ORDER BY m.id DESC
                    LIMIT 200";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([":me"=>$me_id, ":them"=>$target_id]);
            $messages = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
        } else {
            $messages = [];
        }
        $call = get_active_dm_call($pdo, $me_id, $target_id, $call_since, $call_status);
    }
} catch (Exception $e) {
    json_err("fetch failed", 500);
}

// typing list
try {
    $stmt = $pdo->prepare("SELECT username FROM dm_typing WHERE target_user_id = :me AND typing_until > NOW()");
    $stmt->execute([":me"=>$me_id]);
    $typing = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $typing = [];
}

// normalize reply excerpts
foreach ($messages as &$m) {
    if (!empty($m['reply_to_message'])) $m['reply_to_excerpt'] = mb_substr($m['reply_to_message'], 0, 240);
    else $m['reply_to_excerpt'] = null;
}
unset($m);

// mutual friends
$mutual_friends_count = 0;
$mutual_friends = [];
try {
    $sqlCount = "
      SELECT COUNT(*) FROM users u
      WHERE u.id IN (
        SELECT CASE WHEN f.user_a = :me THEN f.user_b ELSE f.user_a END AS friend_id
        FROM friendships f
        WHERE (f.user_a = :me OR f.user_b = :me) AND f.status = 'friends'
      )
      AND u.id IN (
        SELECT CASE WHEN f2.user_a = :them THEN f2.user_b ELSE f2.user_a END AS friend_id
        FROM friendships f2
        WHERE (f2.user_a = :them OR f2.user_b = :them) AND f2.status = 'friends'
      )
    ";
    $st = $pdo->prepare($sqlCount);
    $st->execute([":me"=>$me_id, ":them"=>$target_id]);
    $mutual_friends_count = (int)$st->fetchColumn();

    if ($mutual_friends_count > 0) {
        $sqlList = "
          SELECT u.id, u.username, u.avatar FROM users u
          WHERE u.id IN (
            SELECT CASE WHEN f.user_a = :me THEN f.user_b ELSE f.user_a END AS friend_id
            FROM friendships f
            WHERE (f.user_a = :me OR f.user_b = :me) AND f.status = 'friends'
          )
          AND u.id IN (
            SELECT CASE WHEN f2.user_a = :them THEN f2.user_b ELSE f2.user_a END AS friend_id
            FROM friendships f2
            WHERE (f2.user_a = :them OR f2.user_b = :them) AND f2.status = 'friends'
          )
          LIMIT 20
        ";
        $st2 = $pdo->prepare($sqlList);
        $st2->execute([":me"=>$me_id, ":them"=>$target_id]);
        $mutual_friends = $st2->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}

$friends_list = [];
try {
    $sqlFriends = "
      SELECT u.id, u.username, u.avatar
      FROM users u
      WHERE u.id IN (
        SELECT CASE WHEN f.user_a = :me THEN f.user_b ELSE f.user_a END AS friend_id
        FROM friendships f
        WHERE (f.user_a = :me OR f.user_b = :me) AND f.status = 'friends'
      )
      ORDER BY u.username
      LIMIT 200
    ";
    $sf = $pdo->prepare($sqlFriends);
    $sf->execute([":me"=>$me_id]);
    $friends_list = $sf->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$relationship = [
    "status" => $relationship_status,
    "allowed" => $relationship_allowed,
    "initiator" => $rel['initiator'] ?? null,
    "blocked" => $blocked_by_me,
    "blocked_by_them" => $blocked_by_them,
    "mutual_friends_count" => $mutual_friends_count,
    "mutual_friends" => $mutual_friends
];

$current_user_safe = $current_user;
if (isset($current_user_safe['timeout_until']) && $current_user_safe['timeout_until'] !== null) {
    $ts = strtotime($current_user_safe['timeout_until']);
    if ($ts !== false) $current_user_safe['timeout_until'] = gmdate('Y-m-d\TH:i:s\Z', $ts);
}

echo json_encode([
    "ok" => true,
    "user" => $current_user_safe,
    "target" => [
        "id" => (int)$target['id'],
        "username" => $target['username'],
        "avatar" => $target['avatar'] ?? null,
        "bio" => $target['bio'] ?? null,
        "role" => $target['role'] ?? null,
        "role_color" => $target['role_color'] ?? null,
        "last_seen" => $target['created_at'] ?? null,
        "online" => false
    ],
    "messages" => $messages,
    "typing" => $typing,
    "relationship" => $relationship,
    "friends" => $friends_list,
    "call" => $call
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
