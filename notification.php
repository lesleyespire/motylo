<?php
// notifications.php
// Notification API + Pusher auth + OneSignal push integration + debug-friendly diagnostics
require "config.php";
header("Content-Type: application/json; charset=utf-8");

// Debug flag (set in config.php to false in production)
$NOTIF_DEBUG = isset($NOTIF_DEBUG) ? (bool)$NOTIF_DEBUG : true;

// helpers
function json_out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function pusher_trigger_event($channel, $event, $data = []) {
    global $PUSHER_APP_KEY, $PUSHER_APP_SECRET, $PUSHER_APP_ID, $PUSHER_APP_CLUSTER;
    if (empty($PUSHER_APP_KEY) || empty($PUSHER_APP_SECRET) || empty($PUSHER_APP_ID)) {
        return ['ok' => false, 'error' => 'pusher not configured'];
    }
    // SDK attempt
    if (class_exists('Pusher\Pusher')) {
        try {
            $options = ['useTLS' => true];
            if (!empty($PUSHER_APP_CLUSTER)) $options['cluster'] = $PUSHER_APP_CLUSTER;
            $pusher = new Pusher\Pusher($PUSHER_APP_KEY, $PUSHER_APP_SECRET, $PUSHER_APP_ID, $options);
            $pusher->trigger($channel, $event, $data);
            return ['ok' => true];
        } catch (Exception $e) {
            $sdkErr = $e->getMessage();
        }
    } else {
        $sdkErr = "sdk-missing";
    }

    // HTTP fallback
    $cluster = $PUSHER_APP_CLUSTER ?? '';
    $host = $cluster ? "api-{$cluster}.pusher.com" : "api.pusher.com";
    $urlPath = "/apps/{$PUSHER_APP_ID}/events";
    $body = json_encode(['name' => $event, 'channels' => (is_array($channel) ? $channel : [$channel]), 'data' => $data]);
    $body_md5 = md5($body);
    $auth_key = $PUSHER_APP_KEY;
    $auth_timestamp = time();
    $auth_version = '1.0';
    $query = "auth_key={$auth_key}&auth_timestamp={$auth_timestamp}&auth_version={$auth_version}&body_md5={$body_md5}";
    $string_to_sign = "POST\n{$urlPath}\n{$query}";
    $auth_signature = hash_hmac('sha256', $string_to_sign, $PUSHER_APP_SECRET);
    $full_url = "https://{$host}{$urlPath}?{$query}&auth_signature={$auth_signature}";
    $ch = curl_init($full_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json','Content-Length: '.strlen($body)]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    if (defined('CURL_HTTP_VERSION_1_1')) curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
    $resp = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp !== false && $httpcode >= 200 && $httpcode < 300) return ['ok' => true, 'resp' => $resp];
    return ['ok' => false, 'error' => 'pusher_rest_error', 'http' => $httpcode, 'resp' => $resp, 'curl_err' => $err, 'sdk_err' => $sdkErr ?? null];
}

function send_one_signal_notification(array $playerIds, $title, $message, $data = []) {
    global $ONESIGNAL_APP_ID, $ONESIGNAL_REST_KEY, $NOTIF_DEBUG;
    if (empty($ONESIGNAL_REST_KEY) || empty($ONESIGNAL_APP_ID)) {
        return ['ok' => false, 'error' => 'onesignal_not_configured'];
    }
    $playerIds = array_filter(array_map('trim', $playerIds));
    if (empty($playerIds)) return ['ok' => false, 'error' => 'no_player_ids'];
    $body = [
        'app_id' => $ONESIGNAL_APP_ID,
        'include_player_ids' => array_values($playerIds),
        'headings' => ['en' => $title],
        'contents' => ['en' => $message],
        'data' => $data,
        'ios_badgeType' => 'Increase',
        'ios_badgeCount' => 1,
    ];
    $ch = curl_init("https://onesignal.com/api/v1/notifications");
    $payload = json_encode($body);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json; charset=utf-8',
        'Authorization: Basic ' . ($ONESIGNAL_REST_KEY ?? '')
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $json = null;
    if ($resp) $json = json_decode($resp, true);
    if ($http >= 200 && $http < 300) return ['ok' => true, 'resp' => $json];
    return ['ok' => false, 'http' => $http, 'resp' => $json, 'curl_err' => $err, 'raw' => $resp];
}

// auth
if (empty($_COOKIE['auth_token'])) {
    json_out(['ok' => false, 'error' => 'not logged in'], 401);
}
try {
    $stmt = $pdo->prepare("SELECT id, username FROM users WHERE auth_token = ? LIMIT 1");
    $stmt->execute([$_COOKIE['auth_token']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $msg = $NOTIF_DEBUG ? $e->getMessage() : "db error";
    json_out(['ok' => false, 'error' => 'db error', 'detail' => $msg], 500);
}
if (!$user) json_out(['ok' => false, 'error' => 'bad login'], 401);
$me_id = (int)$user['id'];

function check_notifications_table(PDO $pdo) {
    try {
        $t = $pdo->prepare("SHOW TABLES LIKE 'notifications'");
        $t->execute();
        $found = $t->fetch(PDO::FETCH_COLUMN);
        if (!$found) return ['ok' => false, 'error' => 'notifications table missing'];
        $cols = $pdo->query("SHOW COLUMNS FROM notifications")->fetchAll(PDO::FETCH_COLUMN, 0);
        $need = ['id','user_id','type','message','is_read','created_at'];
        foreach ($need as $c) { if (!in_array($c, $cols)) return ['ok' => false, 'error' => "missing column {$c}"]; }
        return ['ok' => true];
    } catch (Exception $e) {
        return ['ok' => false, 'error' => 'table check failed', 'detail' => $e->getMessage()];
    }
}

// Diagnostics
if (isset($_GET['action']) && $_GET['action'] === 'status') {
    $tableCheck = check_notifications_table($pdo);
    $pusher_ok = (!empty($PUSHER_APP_KEY) && !empty($PUSHER_APP_SECRET) && !empty($PUSHER_APP_ID));
    $onesignal_ok = (!empty($ONESIGNAL_APP_ID) && !empty($ONESIGNAL_REST_KEY));
    // push_subscriptions table existence
    try {
        $t2 = $pdo->prepare("SHOW TABLES LIKE 'push_subscriptions'");
        $t2->execute();
        $push_exists = (bool)$t2->fetch(PDO::FETCH_COLUMN);
    } catch (Exception $_) { $push_exists = false; }
    json_out(['ok' => true, 'table' => $tableCheck, 'pusher_configured' => $pusher_ok, 'onesignal_configured' => $onesignal_ok, 'push_table_exists' => $push_exists, 'me' => ['id' => $me_id, 'username' => $user['username']]]);
}

// Pusher auth for private channels
if (isset($_GET['action']) && $_GET['action'] === 'auth') {
    $socket_id = $_POST['socket_id'] ?? $_GET['socket_id'] ?? '';
    $channel_name = $_POST['channel_name'] ?? $_GET['channel_name'] ?? '';
    if (!$socket_id || !$channel_name) {
        http_response_code(400);
        $msg = 'missing socket_id or channel_name';
        if ($NOTIF_DEBUG) json_out(['error' => $msg], 400);
        else json_out(['error' => 'bad params'], 400);
    }
    $expected = 'private-notifs-' . $me_id;
    if ($channel_name !== $expected) { http_response_code(403); json_out(['error' => 'unauthorized channel'], 403); }
    if (empty($PUSHER_APP_SECRET) || empty($PUSHER_APP_KEY)) { http_response_code(500); json_out(['error' => 'pusher not configured'], 500); }
    $string_to_sign = $socket_id . ':' . $channel_name;
    $signature = hash_hmac('sha256', $string_to_sign, $PUSHER_APP_SECRET);
    $auth = $PUSHER_APP_KEY . ':' . $signature;
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['auth' => $auth]);
    exit;
}

// Main API
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;
    if ($limit <= 0) $limit = 100; if ($limit > 200) $limit = 200;
    $since = isset($_GET['since_id']) ? intval($_GET['since_id']) : 0;
    $tableCheck = check_notifications_table($pdo);
    if (!$tableCheck['ok']) json_out(['ok' => false, 'error' => 'missing_notifications_table', 'detail' => $tableCheck], 500);
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $st->execute([$me_id]);
        $unread_count = (int)$st->fetchColumn();
        if ($since > 0) {
            $sql = "SELECT n.*, u.username AS source_username, u.avatar AS source_avatar
                    FROM notifications n
                    LEFT JOIN users u ON u.id = n.source_user_id
                    WHERE n.user_id = ? AND n.id > ?
                    ORDER BY n.id ASC
                    LIMIT " . intval($limit);
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$me_id, $since]);
        } else {
            $sql = "SELECT n.*, u.username AS source_username, u.avatar AS source_avatar
                    FROM notifications n
                    LEFT JOIN users u ON u.id = n.source_user_id
                    WHERE n.user_id = ?
                    ORDER BY n.id DESC
                    LIMIT " . intval($limit);
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$me_id]);
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id']; $r['user_id'] = (int)$r['user_id'];
            $r['source_user_id'] = $r['source_user_id'] !== null ? (int)$r['source_user_id'] : null;
            $r['is_read'] = (int)$r['is_read'];
            if (isset($r['created_at'])) $r['created_at'] = gmdate('Y-m-d\TH:i:s\Z', strtotime($r['created_at']));
        }
        unset($r);
        json_out(['ok' => true, 'unread_count' => $unread_count, 'notifications' => $rows]);
    } catch (Exception $e) {
        $detail = $NOTIF_DEBUG ? $e->getMessage() : 'fetch failed';
        json_out(['ok' => false, 'error' => 'fetch_failed', 'detail' => $detail], 500);
    }
    exit;
}

if ($method === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'mark_read') {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) json_out(['ok' => false, 'error' => 'invalid id'], 400);
        try {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $me_id]);
            pusher_trigger_event('private-notifs-' . $me_id, 'mark_read', ['id' => $id]);
            json_out(['ok' => true]);
        } catch (Exception $e) {
            $detail = $NOTIF_DEBUG ? $e->getMessage() : 'mark failed';
            json_out(['ok' => false, 'error' => 'mark_failed', 'detail' => $detail], 500);
        }
        exit;
    }

    if ($action === 'mark_all_read') {
        try {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
            $stmt->execute([$me_id]);
            pusher_trigger_event('private-notifs-' . $me_id, 'mark_all_read', []);
            json_out(['ok' => true]);
        } catch (Exception $e) {
            $detail = $NOTIF_DEBUG ? $e->getMessage() : 'mark all failed';
            json_out(['ok' => false, 'error' => 'mark_all_failed', 'detail' => $detail], 500);
        }
        exit;
    }

    // subscribe_push: store mapping and send confirmation push + DB notification
    if ($action === 'subscribe_push') {
        $player_id = trim($_POST['player_id'] ?? '');
        $device_info = trim($_POST['device_info'] ?? '');
        if ($player_id === '') json_out(['ok' => false, 'error' => 'missing player_id'], 400);
        if (!preg_match('/^[A-Za-z0-9\-\:_]+$/', $player_id)) json_out(['ok' => false, 'error' => 'invalid player_id'], 400);
        try {
            // create table if missing
            $pdo->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                player_id VARCHAR(255) NOT NULL,
                device_info VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY (player_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Upsert mapping
            $stmt = $pdo->prepare("INSERT INTO push_subscriptions (user_id, player_id, device_info) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), device_info = VALUES(device_info), created_at = CURRENT_TIMESTAMP");
            $stmt->execute([$me_id, $player_id, $device_info ?: null]);

            // Add an in-app notification for feedback
            $nid = null;
            try {
                $noteMsg = "You have enabled push notifications on this browser.";
                $ins = $pdo->prepare("INSERT INTO notifications (user_id, type, source_user_id, message, context, ref_id, important) VALUES (?, 'system', NULL, ?, NULL, NULL, 0)");
                $ins->execute([$me_id, $noteMsg]);
                $nid = (int)$pdo->lastInsertId();
            } catch (Exception $_) { $nid = null; }

            // Try to send OneSignal confirmation
            $onesignal_result = null;
            if (!empty($GLOBALS['ONESIGNAL_APP_ID']) && !empty($GLOBALS['ONESIGNAL_REST_KEY'])) {
                $onesignal_result = send_one_signal_notification([$player_id], "Subscribed", "You are now subscribed to push notifications.", ['notification_id' => $nid]);
            }

            json_out(['ok' => true, 'onesignal' => $onesignal_result ?? null]);
        } catch (Exception $e) {
            $detail = $NOTIF_DEBUG ? $e->getMessage() : 'db error';
            json_out(['ok' => false, 'error' => 'db_error', 'detail' => $detail], 500);
        }
        exit;
    }

    if ($action === 'unsubscribe_push') {
        $player_id = trim($_POST['player_id'] ?? '');
        try {
            if ($player_id !== '') {
                $stmt = $pdo->prepare("DELETE FROM push_subscriptions WHERE player_id = ? AND user_id = ?");
                $stmt->execute([$player_id, $me_id]);
            } else {
                $stmt = $pdo->prepare("DELETE FROM push_subscriptions WHERE user_id = ?");
                $stmt->execute([$me_id]);
            }
            json_out(['ok' => true]);
        } catch (Exception $e) {
            $detail = $NOTIF_DEBUG ? $e->getMessage() : 'db error';
            json_out(['ok' => false, 'error' => 'db_error', 'detail' => $detail], 500);
        }
        exit;
    }

    // create notification row and push via Pusher + OneSignal
    if ($action === 'create') {
        $target_user = isset($_POST['user_id']) ? intval($_POST['user_id']) : $me_id;
        $type = trim($_POST['type'] ?? 'generic');
        $source = isset($_POST['source_user_id']) ? intval($_POST['source_user_id']) : null;
        $message = trim($_POST['message'] ?? '');
        $context = $_POST['context'] ?? null;
        $ref_id = isset($_POST['ref_id']) ? intval($_POST['ref_id']) : null;
        $important = (!empty($_POST['important']) && ($_POST['important'] === '1' || $_POST['important'] === 'true')) ? 1 : 0;
        if ($message === '') json_out(['ok' => false, 'error' => 'message required'], 400);

        $tableCheck = check_notifications_table($pdo);
        if (!$tableCheck['ok']) json_out(['ok' => false, 'error' => 'notifications table missing', 'detail' => $tableCheck], 500);

        try {
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, source_user_id, message, context, ref_id, important) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$target_user, $type, $source, $message, $context, $ref_id, $important]);
            $nid = (int)$pdo->lastInsertId();

            // cap to 99 notifications per user by deleting oldest single row if over
            try {
                $cst = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
                $cst->execute([$target_user]);
                $cnt = (int)$cst->fetchColumn();
                if ($cnt > 99) {
                    $del = $pdo->prepare("DELETE FROM notifications WHERE user_id = ? ORDER BY id ASC LIMIT 1");
                    $del->execute([$target_user]);
                }
            } catch (Exception $_) {}

            // Broadcast to real-time clients
            $payload = ['id' => $nid, 'type' => $type, 'message' => $message, 'ref_id' => $ref_id, 'important' => (int)$important, 'created_at' => gmdate('Y-m-d\TH:i:s\Z')];
            $pRes = pusher_trigger_event('private-notifs-' . $target_user, 'new_notification', $payload);

            // Try OneSignal push send using stored player ids
            $onesignal_result = null;
            if (!empty($GLOBALS['ONESIGNAL_APP_ID']) && !empty($GLOBALS['ONESIGNAL_REST_KEY'])) {
                try {
                    $ps = $pdo->prepare("SELECT player_id FROM push_subscriptions WHERE user_id = ?");
                    $ps->execute([$target_user]);
                    $players = $ps->fetchAll(PDO::FETCH_COLUMN, 0);
                    if (!empty($players)) {
                        $onesignal_result = send_one_signal_notification($players, $message, $message, ['notification_id'=>$nid, 'ref_id'=>$ref_id]);
                        // cleanup invalid ids if reported in response
                        if (isset($onesignal_result['resp']) && is_array($onesignal_result['resp'])) {
                            $resp = $onesignal_result['resp'];
                            $invalid = [];
                            if (!empty($resp['invalid_player_ids']) && is_array($resp['invalid_player_ids'])) $invalid = $resp['invalid_player_ids'];
                            if (!empty($invalid)) {
                                $del = $pdo->prepare("DELETE FROM push_subscriptions WHERE player_id = ?");
                                foreach ($invalid as $bad) { try { $del->execute([$bad]); } catch (Exception $_) {} }
                            }
                        }
                    }
                } catch (Exception $e) {
                    if ($NOTIF_DEBUG) $onesignal_result = ['ok'=>false,'error'=>'onesignal_send_failed','detail'=>$e->getMessage()];
                }
            }

            // Response including debug info if needed
            if (!$pRes['ok']) {
                if ($NOTIF_DEBUG) json_out(['ok' => true, 'id' => $nid, 'pusher' => $pRes, 'onesignal' => $onesignal_result ?? null]);
                else json_out(['ok' => true, 'id' => $nid]);
            }

            $out = ['ok' => true, 'id' => $nid];
            if ($NOTIF_DEBUG) $out['onesignal'] = $onesignal_result ?? null;
            json_out($out);
        } catch (Exception $e) {
            $detail = $NOTIF_DEBUG ? $e->getMessage() : 'create failed';
            json_out(['ok' => false, 'error' => 'create_failed', 'detail' => $detail], 500);
        }
        exit;
    }

    // debug helper: resend unread notifications as push (for your current user)
    if ($action === 'resend_unread_pushes') {
        // fetch unread notifications and send push for each (throttle minimal)
        try {
            $stmt = $pdo->prepare("SELECT id, message, ref_id FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY id ASC LIMIT 50");
            $stmt->execute([$me_id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $ps = $pdo->prepare("SELECT player_id FROM push_subscriptions WHERE user_id = ?");
            $ps->execute([$me_id]);
            $players = $ps->fetchAll(PDO::FETCH_COLUMN, 0);
            $sent = [];
            $failures = [];
            foreach ($rows as $r) {
                if (empty($players)) { $failures[] = 'no_players'; break; }
                $res = send_one_signal_notification($players, $r['message'], $r['message'], ['notification_id' => (int)$r['id'], 'ref_id' => $r['ref_id']]);
                if (!empty($res) && !empty($res['ok'])) $sent[] = $r['id']; else $failures[] = $res;
            }
            json_out(['ok' => true, 'sent' => $sent, 'failures' => $failures]);
        } catch (Exception $e) {
            $detail = $NOTIF_DEBUG ? $e->getMessage() : 'resend failed';
            json_out(['ok' => false, 'error' => 'resend_failed', 'detail' => $detail], 500);
        }
        exit;
    }

    json_out(['ok' => false, 'error' => 'unknown action'], 400);
}

json_out(['ok' => false, 'error' => 'bad method'], 405);
