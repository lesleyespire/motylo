<?php
// private_interface.php - room messaging endpoint (updated)
require "config.php";
header("Content-Type: application/json");
set_time_limit(30);

// helpers
function json_err($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(["error" => $msg]);
    exit;
}
function json_ok($data = []) {
    echo json_encode($data);
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
function try_add_column($pdo, $table, $definition) {
    try {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN $definition");
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// ensure notifications.ref_code exists (non-fatal)
try {
    $notifCols = get_table_columns($pdo, 'notifications');
    if (!in_array('ref_code', $notifCols)) {
        try_add_column($pdo, 'notifications', "ref_code VARCHAR(128) DEFAULT NULL");
    }
} catch (Exception $e) {}

// create community_timeouts table if absent (defensive)
try {
    $cols = get_table_columns($pdo, 'community_timeouts');
    if (empty($cols)) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS community_timeouts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            community_id INT NOT NULL,
            user_id INT NOT NULL,
            actor_user INT DEFAULT NULL,
            reason VARCHAR(255) DEFAULT NULL,
            until_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (community_id), INDEX (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
} catch (Exception $e) {}

// improved notification helper wrapper (kept compatible)
function create_notification($pdo, $user_id, $type, $source_user_id, $message, $context = null, $ref_id = null, $ref_code = null) {
    if (!$user_id) return;
    try {
        $path = __DIR__ . '/notifications_lib.php';
        if (!function_exists('send_user_notification') && is_file($path)) require_once $path;
        $important = 1;
        if (function_exists('send_user_notification')) {
            try {
                $rf = new ReflectionFunction('send_user_notification');
                $num = $rf->getNumberOfParameters();
                if ($num >= 8) {
                    send_user_notification($pdo, (int)$user_id, (string)$message, (string)$type, $source_user_id ? (int)$source_user_id : null, $ref_id ? (int)$ref_id : null, $ref_code !== null ? (string)$ref_code : null, $important);
                    return;
                } elseif ($num >= 7) {
                    send_user_notification($pdo, (int)$user_id, (string)$message, (string)$type, $source_user_id ? (int)$source_user_id : null, $ref_id ? (int)$ref_id : null, $important);
                    return;
                } else {
                    send_user_notification($pdo, (int)$user_id, (string)$message);
                    return;
                }
            } catch (ReflectionException $e) {}
        }
    } catch (Exception $e) {}

    try {
        $cols = get_table_columns($pdo, 'notifications');
        if (in_array('ref_code', $cols)) {
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, source_user_id, message, context, ref_id, ref_code, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$user_id, $type, $source_user_id, mb_substr($message,0,240), $context, $ref_id ?: null, $ref_code ?: null]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, source_user_id, message, context, ref_id, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$user_id, $type, $source_user_id, mb_substr($message,0,240), $context, $ref_id ?: null]);
        }
    } catch (Exception $e) {}
}

// config
$MAX_MESSAGE_LENGTH = 750;

// auth
if (empty($_COOKIE["auth_token"])) { echo json_encode(["error" => "not logged in"]); exit; }

// room param
$roomParam = $_GET["room"] ?? "";
if ($roomParam === "") { echo json_encode(["error" => "invalid room"]); exit; }

// find room row
if (ctype_digit((string)$roomParam)) {
    $stmt = $pdo->prepare("SELECT id, code, name, community_id, required_role_id FROM private_rooms WHERE id = ?");
    $stmt->execute([(int)$roomParam]);
} else {
    $stmt = $pdo->prepare("SELECT id, code, name, community_id, required_role_id FROM private_rooms WHERE code = ?");
    $stmt->execute([$roomParam]);
}
$roomRow = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$roomRow) { echo json_encode(["error" => "invalid room"]); exit; }
$room_id   = (int)$roomRow["id"];
$room_code = $roomRow["code"];
$room_name = $roomRow['name'] ?? 'Private Room';
$room_community_id = isset($roomRow['community_id']) ? (int)$roomRow['community_id'] : null;
$room_required_role = isset($roomRow['required_role_id']) ? (int)$roomRow['required_role_id'] : 0;

// get current user & role
$stmt = $pdo->prepare("
    SELECT u.id, u.username, u.avatar, u.role_id, u.timeout_until,
           r.name AS role, r.color AS role_color, r.badge AS role_badge
    FROM users u
    LEFT JOIN roles r ON r.id = u.role_id
    WHERE u.auth_token = ?
");
$stmt->execute([$_COOKIE["auth_token"]]);
$current_user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$current_user) { echo json_encode(["error" => "bad login"]); exit; }
$uid = (int)$current_user['id'];
$username = $current_user['username'];

// detect private_messages schema
$colCheck = $pdo->query("SHOW COLUMNS FROM private_messages")->fetchAll(PDO::FETCH_COLUMN);
$has_room_id = in_array("room_id", $colCheck, true);
$has_room_code = in_array("room_code", $colCheck, true);
if (!$has_room_id && !$has_room_code) { error_log("private_interface.php: neither room_id nor room_code exists in private_messages"); echo json_encode(["error" => "server misconfiguration"]); exit; }
$pm_column = $has_room_id ? "room_id" : "room_code";
$pm_value  = $has_room_id ? $room_id : $room_code;

// ensure message columns
$pm_cols = get_table_columns($pdo, 'private_messages');
if (!in_array('edited_at', $pm_cols)) { try_add_column($pdo, 'private_messages', "edited_at DATETIME NULL DEFAULT NULL"); $pm_cols = get_table_columns($pdo, 'private_messages'); }
if (!in_array('deleted_at', $pm_cols)) { try_add_column($pdo, 'private_messages', "deleted_at DATETIME NULL DEFAULT NULL"); $pm_cols = get_table_columns($pdo, 'private_messages'); }
if (!in_array('deleted_by', $pm_cols)) { try_add_column($pdo, 'private_messages', "deleted_by INT NULL DEFAULT NULL"); $pm_cols = get_table_columns($pdo, 'private_messages'); }
if (!in_array('reply_to', $pm_cols)) { try_add_column($pdo, 'private_messages', "reply_to INT NULL DEFAULT NULL"); $pm_cols = get_table_columns($pdo, 'private_messages'); }

// -------------------- permission enforcement: block banned users from API --------------------
if ($room_community_id) {
    try {
        $bq = $pdo->prepare("SELECT 1 FROM community_bans WHERE community_id = ? AND user_id = ? AND (until_at IS NULL OR until_at > NOW()) LIMIT 1");
        $bq->execute([$room_community_id, $uid]);
        if ($bq->fetchColumn()) { echo json_encode(["error" => "banned"]); exit; }
    } catch (Exception $e) {}
}

// also enforce required_role_id for API access (mirror page)
if ($room_community_id && $room_required_role) {
    $allowed = false;
    try {
        $ownerRow = $pdo->prepare("SELECT owner_id FROM communities WHERE id = ? LIMIT 1");
        $ownerRow->execute([$room_community_id]);
        $o = $ownerRow->fetch(PDO::FETCH_ASSOC);
        if ($o && (int)($o['owner_id'] ?? 0) === $uid) $allowed = true;
    } catch (Exception $e) {}
    if (!$allowed) {
        $hasMR = (bool)$pdo->query("SHOW TABLES LIKE 'community_member_roles'")->fetchColumn();
        if ($hasMR) {
            $q = $pdo->prepare("SELECT cr.* FROM community_member_roles mr JOIN community_roles cr ON cr.id = mr.role_id WHERE mr.community_id = ? AND mr.user_id = ?");
            $q->execute([$room_community_id, $uid]);
            $rr = $q->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rr as $r) {
                if (!empty($r['is_admin']) || !empty($r['admin'])) { $allowed = true; break; }
                if (!empty($r['can_view_locked'])) { $allowed = true; break; }
                if ((int)$r['id'] === (int)$room_required_role) { $allowed = true; break; }
            }
        } else {
            $q = $pdo->prepare("SELECT cm.role_id, cr.can_view_locked, cr.is_admin, cr.admin FROM community_members cm LEFT JOIN community_roles cr ON cr.id = cm.role_id WHERE cm.community_id = ? AND cm.user_id = ? LIMIT 1");
            $q->execute([$room_community_id, $uid]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
            if ($r) {
                if (!empty($r['is_admin']) || !empty($r['admin'])) $allowed = true;
                elseif (!empty($r['can_view_locked'])) $allowed = true;
                elseif ((int)($r['role_id'] ?? 0) === (int)$room_required_role) $allowed = true;
            }
        }
    }
    if (!$allowed) { echo json_encode(["error" => "forbidden"]); exit; }
}

// -------------------- compute community perms for current user (expose flags) --------------------
$current_user['can_timeout'] = false;
$current_user['can_ban'] = false;
$current_user['can_assign_roles'] = false;
if ($room_community_id) {
    $hasMR = (bool)$pdo->query("SHOW TABLES LIKE 'community_member_roles'")->fetchColumn();
    if ($hasMR) {
        $ps = $pdo->prepare("SELECT cr.* FROM community_member_roles mr JOIN community_roles cr ON cr.id = mr.role_id WHERE mr.community_id = ? AND mr.user_id = ?");
        $ps->execute([$room_community_id, $uid]);
        $rows = $ps->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            if (!empty($r['can_timeout'])) $current_user['can_timeout'] = true;
            if (!empty($r['can_ban'])) $current_user['can_ban'] = true;
            if (!empty($r['can_assign_roles'])) $current_user['can_assign_roles'] = true;
            if (!empty($r['is_admin']) || !empty($r['admin'])) {
                $current_user['can_timeout']= $current_user['can_ban'] = $current_user['can_assign_roles'] = true;
            }
        }
    } else {
        $ps = $pdo->prepare("SELECT cr.* FROM community_members cm LEFT JOIN community_roles cr ON cr.id = cm.role_id WHERE cm.community_id = ? AND cm.user_id = ? LIMIT 1");
        $ps->execute([$room_community_id, $uid]);
        $r = $ps->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            if (!empty($r['can_timeout'])) $current_user['can_timeout'] = true;
            if (!empty($r['can_ban'])) $current_user['can_ban'] = true;
            if (!empty($r['can_assign_roles'])) $current_user['can_assign_roles'] = true;
            if (!empty($r['is_admin']) || !empty($r['admin'])) {
                $current_user['can_timeout']= $current_user['can_ban'] = $current_user['can_assign_roles'] = true;
            }
        }
    }
}

// -------------------- modes --------------------
$mode = $_GET['mode'] ?? '';

if ($mode === 'typing') {
    try {
        $sql = "
            INSERT INTO private_typing ({col}, user_id, username, typing_until)
            VALUES (:roomval, :uid, :username, DATE_ADD(NOW(), INTERVAL 3 SECOND))
            ON DUPLICATE KEY UPDATE typing_until = DATE_ADD(NOW(), INTERVAL 3 SECOND)
        ";
        $sql = str_replace("{col}", $pm_column, $sql);
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ":roomval" => $pm_value,
            ":uid"     => $uid,
            ":username"=> $username
        ]);
    } catch (Exception $e) {}
    echo json_encode(["ok" => true]);
    exit;
}

if ($mode === 'send') {
    $msg = trim($_POST["message"] ?? "");
    $reply_to = isset($_POST["reply_to"]) ? (int)$_POST["reply_to"] : null;

    // enforce length
    if ($msg !== "") {
        if (mb_strlen($msg, 'UTF-8') > $MAX_MESSAGE_LENGTH) {
            echo json_encode(["error" => "message too long", "max" => $MAX_MESSAGE_LENGTH]);
            exit;
        }

        // block if user has community timeout
        if ($room_community_id) {
            $tq = $pdo->prepare("SELECT until_at FROM community_timeouts WHERE community_id = ? AND user_id = ? AND until_at > NOW() ORDER BY until_at DESC LIMIT 1");
            $tq->execute([$room_community_id, $uid]);
            $trow = $tq->fetch(PDO::FETCH_ASSOC);
            if ($trow && !empty($trow['until_at'])) {
                echo json_encode(["error" => "timed_out", "until" => $trow['until_at']]);
                exit;
            }
        }

        if ($reply_to && $reply_to <= 0) $reply_to = null;
        $sql = "
            INSERT INTO private_messages ({col}, user_id, username, message, reply_to)
            VALUES (:roomval, :uid, :username, :message, :reply_to)
        ";
        $sql = str_replace("{col}", $pm_column, $sql);
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ":roomval" => $pm_value,
            ":uid"     => $uid,
            ":username"=> $username,
            ":message" => $msg,
            ":reply_to"=> $reply_to
        ]);
        $newId = (int)$pdo->lastInsertId();

        if ($reply_to) {
            try {
                $q = "SELECT user_id, username FROM private_messages WHERE id = ? LIMIT 1";
                $s = $pdo->prepare($q); $s->execute([$reply_to]);
                $orig = $s->fetch(PDO::FETCH_ASSOC);
                if ($orig && isset($orig['user_id'])) {
                    $origUser = (int)$orig['user_id'];
                    if ($origUser !== $uid) {
                        $noteMsg = "{$username} replied to your message in \"{$room_name}\"";
                        create_notification($pdo, $origUser, 'reply_private', $uid, $noteMsg, 'private', $newId, $room_code);
                    }
                }
            } catch (Exception $e) { }
        }
    }
    echo json_encode(["ok" => true]);
    exit;
}

if ($mode === 'edit') {
    $id = (int)($_POST['id'] ?? 0);
    $text = trim($_POST['message'] ?? '');
    if ($id <= 0 || $text === '') { echo json_encode(["error"=>"invalid"]); exit; }

    if (mb_strlen($text, 'UTF-8') > $MAX_MESSAGE_LENGTH) {
        echo json_encode(["error" => "message too long", "max" => $MAX_MESSAGE_LENGTH]);
        exit;
    }

    $sql = "SELECT user_id, created_at, deleted_at, message FROM private_messages WHERE id = :id AND " . $pm_column . " = :roomval";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([":id"=>$id, ":roomval"=>$pm_value]);
    $m = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$m) { echo json_encode(["error"=>"not found"]); exit; }
    if ($m['deleted_at']) { echo json_encode(["error"=>"deleted"]); exit; }
    if ((int)$m['user_id'] !== $uid) { echo json_encode(["error"=>"denied"]); exit; }

    // write old message to community_audit (if table exists)
    try {
        $cols = get_table_columns($pdo, 'community_audit');
        if (!empty($cols)) {
            $aq = $pdo->prepare("INSERT INTO community_audit (community_id, action, actor_user, target_user, target_message, reason, created_at) VALUES (?, 'edit', ?, ?, ?, NULL, NOW())");
            $aq->execute([$room_community_id ?: null, $uid, $m['user_id'], $m['message']]);
        }
    } catch (Exception $e) {}

    $sql = "UPDATE private_messages SET message = :msg, edited_at = NOW() WHERE id = :id AND " . $pm_column . " = :roomval";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([":msg"=>$text, ":id"=>$id, ":roomval"=>$pm_value]);
    echo json_encode(["ok" => true]);
    exit;
}

if ($mode === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { echo json_encode(["error"=>"invalid"]); exit; }

    $roleName = strtolower((string)($current_user['role'] ?? ''));
    $roleId   = isset($current_user['role_id']) ? (int)$current_user['role_id'] : null;
    $isPrivileged = false;
    if ($roleId !== null && $roleId < 3) $isPrivileged = true;
    $checkNames = ['site administrator','site moderator','admin','owner','moderator'];
    foreach ($checkNames as $n) { if (strpos($roleName, $n) !== false) { $isPrivileged = true; break; } }

    if (!$isPrivileged) { echo json_encode(["error"=>"denied"]); exit; }

    $sql = "UPDATE private_messages
            SET message = 'Message removed by a site moderator', deleted_at = NOW(), deleted_by = :mod
            WHERE id = :id AND " . $pm_column . " = :roomval";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([":mod"=>$uid, ":id"=>$id, ":roomval"=>$pm_value]);
    echo json_encode(["ok" => true]);
    exit;
}

// cleanup - keep message count bounded
try {
    if ($has_room_id) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM private_messages WHERE room_id = ?");
        $stmt->execute([$room_id]);
        $count = (int)$stmt->fetchColumn();
        if ($count > 200) {
            $del = $pdo->prepare("DELETE FROM private_messages WHERE id = (SELECT id FROM (SELECT id FROM private_messages WHERE room_id = ? ORDER BY created_at ASC LIMIT 1) x)");
            $del->execute([$room_id]);
        }
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM private_messages WHERE room_code = ?");
        $stmt->execute([$room_code]);
        $count = (int)$stmt->fetchColumn();
        if ($count > 200) {
            $del = $pdo->prepare("DELETE FROM private_messages WHERE id = (SELECT id FROM (SELECT id FROM private_messages WHERE room_code = ? ORDER BY created_at ASC LIMIT 1) x)");
            $del->execute([$room_code]);
        }
    }
} catch (Exception $e) {}

// fetch messages (supports ?since= + long-poll)
$since = isset($_GET["since"]) ? (int)$_GET["since"] : 0;
$longpoll = $since > 0;
$timeout_seconds = 25;
$interval_usec = 500000;

try {
    if ($longpoll && $since > 0) {
        $start = time();
        $messages = [];
        while ((time() - $start) < $timeout_seconds) {
            $sql = "
                SELECT m.id, m.username, m.user_id, m.message, m.created_at, m.edited_at, m.deleted_at, m.deleted_by, m.reply_to,
                       rm.username AS reply_to_username, rm.message AS reply_to_message,
                       u.avatar, r.name AS role, r.color AS role_color, r.badge AS role_badge,
                       (SELECT MAX(ct.until_at) FROM community_timeouts ct WHERE ct.community_id = :community_id AND ct.user_id = u.id AND ct.until_at > NOW()) AS timeout_until,
                       (EXISTS(SELECT 1 FROM community_bans cb WHERE cb.community_id = :community_id AND cb.user_id = u.id AND (cb.until_at IS NULL OR cb.until_at > NOW()))) AS is_banned
                FROM private_messages m
                LEFT JOIN private_messages rm ON rm.id = m.reply_to
                LEFT JOIN users u ON u.id = m.user_id
                LEFT JOIN roles r ON r.id = u.role_id
                WHERE m." . $pm_column . " = :roomval AND m.id > :since
                ORDER BY m.id ASC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([":roomval" => $pm_value, ":since" => $since, ":community_id" => $room_community_id]);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($messages)) break;
            usleep($interval_usec);
        }
    } else {
        $sql = "
            SELECT m.id, m.username, m.user_id, m.message, m.created_at, m.edited_at, m.deleted_at, m.deleted_by, m.reply_to,
                   rm.username AS reply_to_username, rm.message AS reply_to_message,
                   u.avatar, r.name AS role, r.color AS role_color, r.badge AS role_badge,
                   (SELECT MAX(ct.until_at) FROM community_timeouts ct WHERE ct.community_id = :community_id AND ct.user_id = u.id AND ct.until_at > NOW()) AS timeout_until,
                   (EXISTS(SELECT 1 FROM community_bans cb WHERE cb.community_id = :community_id AND cb.user_id = u.id AND (cb.until_at IS NULL OR cb.until_at > NOW()))) AS is_banned
            FROM private_messages m
            LEFT JOIN private_messages rm ON rm.id = m.reply_to
            LEFT JOIN users u ON u.id = m.user_id
            LEFT JOIN roles r ON r.id = u.role_id
            WHERE m." . $pm_column . " = :roomval
            ORDER BY m.id DESC
            LIMIT 50
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([":roomval" => $pm_value, ":community_id" => $room_community_id]);
        $messages = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
} catch (Exception $e) {
    json_err("fetch failed");
}

// fetch typing list for this room
try {
    $sql = "SELECT username FROM private_typing WHERE " . $pm_column . " = :roomval AND typing_until > NOW()";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([":roomval" => $pm_value]);
    $typing = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $typing = [];
}

// normalize reply excerpts
foreach ($messages as &$m) {
    if (!empty($m['reply_to_message'])) {
        $m['reply_to_excerpt'] = mb_substr($m['reply_to_message'], 0, 240);
    } else {
        $m['reply_to_excerpt'] = null;
    }
}
unset($m);

// normalize current_user timeout to ISO
$current_user_safe = $current_user;
if (isset($current_user_safe['timeout_until']) && $current_user_safe['timeout_until'] !== null) {
    $ts = strtotime($current_user_safe['timeout_until']);
    if ($ts !== false) $current_user_safe['timeout_until'] = gmdate('Y-m-d\TH:i:s\Z', $ts);
}

// also expose community permission flags for current user already computed earlier
// (we included these fields on $current_user above in private_interface.php caller area)
// compute them here similarly (defensive)
$current_user_safe['can_timeout'] = $current_user_safe['can_timeout'] ?? false;
$current_user_safe['can_ban'] = $current_user_safe['can_ban'] ?? false;
$current_user_safe['can_assign_roles'] = $current_user_safe['can_assign_roles'] ?? false;

echo json_encode([
    "user"     => $current_user_safe,
    "messages" => $messages,
    "typing"   => $typing
]);
exit;
