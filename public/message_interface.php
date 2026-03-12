<?php
// message_interface.php - DM server interface (updated: emits notifications for DMs & friend actions)
require "config.php";
header("Content-Type: application/json");
set_time_limit(30);

function json_err($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(["error" => $msg]);
    exit;
}
function json_ok($data = []) {
    echo json_encode(array_merge(["ok" => true], $data));
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
    try {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN $definition");
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// -------------------- auth --------------------
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

// -------------------- target user --------------------
$target_name = trim((string)($_GET['user'] ?? ''));
if ($target_name === '') json_err("missing user", 400);

$stmt = $pdo->prepare("SELECT u.id,u.username,u.avatar,u.bio,u.created_at, r.name AS role, r.color AS role_color, r.badge AS role_badge
                       FROM users u
                       LEFT JOIN roles r ON r.id = u.role_id
                       WHERE u.username = ? LIMIT 1");
$stmt->execute([$target_name]);
$target = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$target) json_err("target not found", 404);
$target_id = (int)$target['id'];

// -------------------- ensure DM schema (create tables if needed) --------------------
try {
    // dm_messages
    $tbls = $pdo->query("SHOW TABLES LIKE 'dm_messages'")->fetchAll();
    if (empty($tbls)) {
        $sql = "CREATE TABLE IF NOT EXISTS dm_messages (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        try_exec($pdo, $sql);
    }

    // dm_typing
    $tbls = $pdo->query("SHOW TABLES LIKE 'dm_typing'")->fetchAll();
    if (empty($tbls)) {
        $sql = "CREATE TABLE IF NOT EXISTS dm_typing (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            username VARCHAR(128) NOT NULL,
            target_user_id INT NOT NULL,
            typing_until DATETIME NOT NULL,
            UNIQUE KEY ux_user_target (user_id, target_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        try_exec($pdo, $sql);
    }

    // friendships
    $tbls = $pdo->query("SHOW TABLES LIKE 'friendships'")->fetchAll();
    if (empty($tbls)) {
        $sql = "CREATE TABLE IF NOT EXISTS friendships (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_a INT NOT NULL,
            user_b INT NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'requested',
            initiator INT NULL,
            requested_kind VARCHAR(32) NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY ux_pair (user_a, user_b)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        try_exec($pdo, $sql);
    } else {
        $cols = get_table_columns($pdo, 'friendships');
        if (!in_array('requested_kind', $cols)) {
            try_add_column($pdo, 'friendships', "requested_kind VARCHAR(32) NULL DEFAULT NULL");
        }
    }

    // blocks
    $tbls = $pdo->query("SHOW TABLES LIKE 'blocks'")->fetchAll();
    if (empty($tbls)) {
        $sql = "CREATE TABLE IF NOT EXISTS blocks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            blocker_id INT NOT NULL,
            blocked_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY ux_block (blocker_id, blocked_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        try_exec($pdo, $sql);
    }
} catch (Exception $e) {
    // ignore
}

// -------------------- helpers --------------------
function get_relationship($pdo, $a, $b) {
    $ua = min($a, $b); $ub = max($a, $b);
    $stmt = $pdo->prepare("SELECT * FROM friendships WHERE user_a = ? AND user_b = ? LIMIT 1");
    $stmt->execute([$ua, $ub]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ["status"=>"none","initiator"=>null,"allowed"=>false,"requested_kind"=>null];
    $status = $row['status'];
    $allowed = in_array($status, ['friends','acquaintance'], true);
    return ["status"=>$status,"initiator"=>$row['initiator'] ?? null,"allowed"=>$allowed,"requested_kind"=>$row['requested_kind'] ?? null];
}
function check_block($pdo, $who, $whom) {
    $stmt = $pdo->prepare("SELECT 1 FROM blocks WHERE blocker_id = ? AND blocked_id = ? LIMIT 1");
    $stmt->execute([$who, $whom]);
    return (bool)$stmt->fetchColumn();
}
// replace existing create_notification(...) with this wrapper
function create_notification($pdo, $user_id, $type, $source_user_id, $message, $context = null, $ref_id = null) {
    if (!$user_id) return;
    try {
        if (!function_exists('send_user_notification')) {
            $path = __DIR__ . '/notifications_lib.php';
            if (is_file($path)) require_once $path;
        }
        $important = 1;
        if (function_exists('send_user_notification')) {
            @send_user_notification($pdo, (int)$user_id, (string)$message, (string)$type, $source_user_id ? (int)$source_user_id : null, $ref_id ? (int)$ref_id : null, $important);
            return;
        }
    } catch (Exception $e) {
        // continue to fallback behavior
    }

    // fallback insert if helper missing
    try {
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, source_user_id, message, context, ref_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $type, $source_user_id, mb_substr($message,0,240), $context, $ref_id ?: null]);
    } catch (Exception $e) {
        // ignore
    }
}

$rel = get_relationship($pdo, $me_id, $target_id);
$blocked_by_me = check_block($pdo, $me_id, $target_id);
$blocked_by_them = check_block($pdo, $target_id, $me_id);
$relationship_status = $rel['status'];
$relationship_allowed = $rel['allowed'] && !$blocked_by_me && !$blocked_by_them;

// -------------------- mode handling --------------------
$mode = $_GET['mode'] ?? '';

// typing
if ($mode === 'typing') {
    try {
        $sql = "INSERT INTO dm_typing (user_id, username, target_user_id, typing_until)
                VALUES (:uid, :username, :target, DATE_ADD(NOW(), INTERVAL 3 SECOND))
                ON DUPLICATE KEY UPDATE typing_until = DATE_ADD(NOW(), INTERVAL 3 SECOND), username = :username2";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([":uid"=>$me_id, ":username"=>$me_username, ":target"=>$target_id, ":username2"=>$me_username]);
    } catch (Exception $e) { /* ignore */ }
    echo json_encode(["ok"=>true]);
    exit;
}

// send
if ($mode === 'send') {
    $msg = trim((string)($_POST['message'] ?? ''));
    $reply_to = isset($_POST['reply_to']) ? (int)$_POST['reply_to'] : null;
    if ($msg === '') { echo json_encode(["error"=>"empty"]); exit; }
    if (mb_strlen($msg) > 750) { echo json_encode(["error"=>"too long"]); exit; }
    if ($blocked_by_me) { echo json_encode(["error"=>"you_blocked"]); exit; }
    if ($blocked_by_them) { echo json_encode(["error"=>"blocked_by_target"]); exit; }
    if (!$rel['allowed']) { echo json_encode(["error"=>"not allowed"]); exit; }

    try {
        $stmt = $pdo->prepare("INSERT INTO dm_messages (user_id, username, target_user_id, message, reply_to) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$me_id, $me_username, $target_id, $msg, $reply_to ?: null]);
        $insertedId = (int)$pdo->lastInsertId();

        // create notification for the recipient
        if ($target_id && $target_id !== $me_id) {
            create_notification($pdo, $target_id, 'dm', $me_id, "{$me_username} sent you a DM", 'dm', $insertedId);
        }

    } catch (Exception $e) { echo json_encode(["error"=>"send failed"]); exit; }
    echo json_encode(["ok"=>true]);
    exit;
}

// edit
if ($mode === 'edit') {
    $id = (int)($_POST['id'] ?? 0);
    $text = trim((string)($_POST['message'] ?? ''));
    if ($id <= 0 || $text === '') { echo json_encode(["error"=>"invalid"]); exit; }
    if (mb_strlen($text) > 750) { echo json_encode(["error"=>"too long"]); exit; }
    $stmt = $pdo->prepare("SELECT user_id, created_at, deleted_at FROM dm_messages WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $m = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$m) { echo json_encode(["error"=>"not found"]); exit; }
    if ($m['deleted_at']) { echo json_encode(["error"=>"deleted"]); exit; }
    if ((int)$m['user_id'] !== $me_id) { echo json_encode(["error"=>"denied"]); exit; }
    $created = strtotime($m['created_at']);
    if ($created === false || (time() - $created) > 600) { echo json_encode(["error"=>"edit window expired"]); exit; }

    $stmt = $pdo->prepare("UPDATE dm_messages SET message = :msg, edited_at = NOW() WHERE id = :id");
    $stmt->execute([":msg"=>$text, ":id"=>$id]);
    echo json_encode(["ok"=>true]);
    exit;
}

// delete - NO deletes allowed for DMs (explicitly disallowed)
if ($mode === 'delete') {
    json_err("deletes not allowed in DMs", 403);
}

// friend_action (map client actions) - with notifications for request & accept
if ($mode === 'friend_action') {
    $action_raw = $_POST['action'] ?? '';
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

    $ua = min($me_id, $target_id); $ub = max($me_id, $target_id);
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
            // notify the other user (target_id)
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
            if (!$row || $row['status'] !== 'requested') json_err("no request", 400);
            if ((int)$row['initiator'] === $me_id) json_err("invalid", 400);
            $rk = $row['requested_kind'] ?? 'friend';
            if ($rk !== 'friend') json_err("mismatch kind", 400);
            $initiator = (int)$row['initiator'];
            $stmt = $pdo->prepare("UPDATE friendships SET status = 'friends', initiator = NULL, requested_kind = NULL, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$row['id']]);
            // notify initiator that their request was accepted
            if ($initiator && $initiator !== $me_id) create_notification($pdo, $initiator, 'friend_accept', $me_id, "{$me_username} accepted your friend request", 'friendship');
            json_ok();
        } elseif ($action === 'accept_acquaintance') {
            if (!$row) json_err("no request", 400);
            if ($row['status'] === 'requested') {
                if ((int)$row['initiator'] === $me_id) json_err("invalid", 400);
                $rk = $row['requested_kind'] ?? null;
                if ($rk !== null && $rk !== 'acquaintance') json_err("mismatch kind", 400);
                $initiator = (int)$row['initiator'];
                $stmt = $pdo->prepare("UPDATE friendships SET status = 'acquaintance', initiator = NULL, requested_kind = NULL, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$row['id']]);
                if ($initiator && $initiator !== $me_id) create_notification($pdo, $initiator, 'friend_accept', $me_id, "{$me_username} accepted your acquaintance request", 'friendship');
                json_ok();
            }
            if ($row['status'] === 'acquaintance') {
                json_ok();
            }
            if ($row['status'] === 'friends') {
                json_err("already friends", 400);
            }
            json_err("no request", 400);
        } elseif ($action === 'decline_friend') {
            if (!$row || $row['status'] !== 'requested') json_err("no request", 400);
            if ((int)$row['initiator'] === $me_id) json_err("invalid", 400);
            $stmt = $pdo->prepare("DELETE FROM friendships WHERE id = ?");
            $stmt->execute([$row['id']]);
            json_ok();
        } elseif ($action === 'cancel_request') {
            if (!$row) json_err("none", 400);
            if ((int)$row['initiator'] !== $me_id) json_err("not allowed", 403);
            $stmt = $pdo->prepare("DELETE FROM friendships WHERE id = ?");
            $stmt->execute([$row['id']]);
            json_ok();
        } elseif ($action === 'remove_friend') {
            if ($row) { $stmt = $pdo->prepare("DELETE FROM friendships WHERE id = ?"); $stmt->execute([$row['id']]); }
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
        } elseif ($action === 'promote') {
            json_err("promote not supported; use request_friend", 400);
        } else {
            json_err("unknown action", 400);
        }
    } catch (Exception $e) {
        json_err("friend action failed");
    }
}

// block_action
if ($mode === 'block_action') {
    $action = $_POST['action'] ?? '';
    if ($action === 'block') {
        try {
            $stmt = $pdo->prepare("INSERT IGNORE INTO blocks (blocker_id, blocked_id) VALUES (?, ?)");
            $stmt->execute([$me_id, $target_id]);
            $ua = min($me_id, $target_id); $ub = max($me_id, $target_id);
            $stmt = $pdo->prepare("DELETE FROM friendships WHERE user_a = ? AND user_b = ?");
            $stmt->execute([$ua, $ub]);
            json_ok();
        } catch (Exception $e){ json_err("block failed"); }
    } elseif ($action === 'unblock') {
        try {
            $stmt = $pdo->prepare("DELETE FROM blocks WHERE blocker_id = ? AND blocked_id = ?");
            $stmt->execute([$me_id, $target_id]);
            json_ok();
        } catch (Exception $e) { json_err("unblock failed"); }
    } else json_err("unknown block action", 400);
}

// voice_start (unchanged)
if ($mode === 'voice_start') {
    $code = substr(bin2hex(random_bytes(5)), 0, 12);
    $cols = $pdo->query("SHOW TABLES LIKE 'private_rooms'")->fetchAll();
    if (!empty($cols)) {
        $columns = get_table_columns($pdo, 'private_rooms');
        $label = "DM voice: {$current_user['username']} <-> {$target['username']}";
        if (in_array('code', $columns)) {
            $stmt = $pdo->prepare("INSERT INTO private_rooms (code, name) VALUES (?, ?)");
            try { $stmt->execute([$code, $label]); } catch (Exception $e) { /* ignore */ }
        } elseif (in_array('room_code', $columns)) {
            $stmt = $pdo->prepare("INSERT INTO private_rooms (room_code, name) VALUES (?, ?)");
            try { $stmt->execute([$code, $label]); } catch (Exception $e) { /* ignore */ }
        }
    }
    json_ok(["room_code"=>$code]);
    exit;
}

// -------------------- cleanup dm_messages --------------------
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM dm_messages WHERE (user_id = :a AND target_user_id = :b) OR (user_id = :b AND target_user_id = :a)");
    $stmt->execute([":a"=>$me_id, ":b"=>$target_id]);
    $count = (int)$stmt->fetchColumn();
    if ($count > 500) {
        $del = $pdo->prepare("DELETE FROM dm_messages WHERE id = (SELECT id FROM (SELECT id FROM dm_messages WHERE (user_id = :a AND target_user_id = :b) OR (user_id = :b AND target_user_id = :a) ORDER BY created_at ASC LIMIT 1) x)");
        $del->execute([":a"=>$me_id, ":b"=>$target_id]);
    }
} catch (Exception $e) { /* ignore */ }

// -------------------- fetch messages (since supports longpoll) --------------------
$since = isset($_GET["since"]) ? (int)$_GET["since"] : 0;
$longpoll = $since > 0;
$timeout_seconds = 25;
$interval_usec = 500000;
$messages = [];

try {
    if ($longpoll && $since > 0) {
        $start = time();
        while ((time() - $start) < $timeout_seconds) {
            $sql = "SELECT m.id, m.username, m.user_id, m.message, m.created_at, m.edited_at, m.deleted_at, m.deleted_by, m.reply_to,
                           rm.username AS reply_to_username, rm.message AS reply_to_message,
                           u.avatar, r.name AS role, r.color AS role_color, r.badge AS role_badge
                    FROM dm_messages m
                    LEFT JOIN dm_messages rm ON rm.id = m.reply_to
                    LEFT JOIN users u ON u.id = m.user_id
                    LEFT JOIN roles r ON r.id = u.role_id
                    WHERE ((m.user_id = :me AND m.target_user_id = :them) OR (m.user_id = :them AND m.target_user_id = :me)) AND m.id > :since
                    ORDER BY m.id ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([":me"=>$me_id, ":them"=>$target_id, ":since"=>$since]);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($messages)) break;
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
    }
} catch (Exception $e) {
    json_err("fetch failed");
}

// typing list (who is typing to me)
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

// -------------------- mutual friends calculation (add avatar) --------------------
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

    // fetch up to 20 with avatar
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
} catch (Exception $e) {
    $mutual_friends_count = 0;
    $mutual_friends = [];
}

// -------------------- your friends list (friends of current user) --------------------
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
} catch (Exception $e) {
    $friends_list = [];
}

// relationship payload
$relationship = [
    "status" => $relationship_status,
    "allowed" => $relationship_allowed,
    "initiator" => $rel['initiator'] ?? null,
    "blocked" => $blocked_by_me,
    "blocked_by_them" => $blocked_by_them,
    "mutual_friends_count" => $mutual_friends_count,
    "mutual_friends" => $mutual_friends
];

// normalize current_user
$current_user_safe = $current_user;
if (isset($current_user_safe['timeout_until']) && $current_user_safe['timeout_until'] !== null) {
    $ts = strtotime($current_user_safe['timeout_until']);
    if ($ts !== false) $current_user_safe['timeout_until'] = gmdate('Y-m-d\TH:i:s\Z', $ts);
}

echo json_encode([
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
    "friends" => $friends_list
]);
exit;
