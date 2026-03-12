<?php
// admin.php - admin API + UI. Uses Pusher (SDK if installed, HTTP fallback otherwise).
require "config.php";

// ---------------------- helpers ----------------------
function json_err($msg, $code = 400) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["ok" => false, "error" => $msg]);
    exit;
}
function json_ok($data = []) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(["ok" => true], $data));
    exit;
}

function get_table_columns(PDO $pdo, $table) {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table`");
        $stmt->execute();
        $cols = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        return array_map('strtolower', $cols ?: []);
    } catch (Exception $e) {
        return [];
    }
}
function try_exec(PDO $pdo, $sql) {
    try { $pdo->exec($sql); return true; } catch (Exception $e) { return false; }
}
function try_add_column(PDO $pdo, $table, $definition) {
    try { $pdo->exec("ALTER TABLE `$table` ADD COLUMN $definition"); return true; } catch (Exception $e) { return false; }
}

// ---------------------- Pusher trigger / auth helpers ----------------------
/*
 * pusher_trigger_event($channel, $event, $data)
 * - tries Pusher SDK if available (Pusher\Pusher)
 * - otherwise uses an HTTP REST fallback to the Pusher API, properly signing the request
 */
function pusher_trigger_event($channel, $event, $data = []) {
    global $PUSHER_APP_KEY, $PUSHER_APP_SECRET, $PUSHER_APP_ID, $PUSHER_APP_CLUSTER;
    // Quick checks
    if (empty($PUSHER_APP_KEY) || empty($PUSHER_APP_SECRET) || empty($PUSHER_APP_ID)) return false;

    // 1) SDK path
    if (class_exists('Pusher\Pusher')) {
        try {
            $options = ['useTLS' => true];
            if (!empty($PUSHER_APP_CLUSTER)) $options['cluster'] = $PUSHER_APP_CLUSTER;
            $pusher = new Pusher\Pusher($PUSHER_APP_KEY, $PUSHER_APP_SECRET, $PUSHER_APP_ID, $options);
            $pusher->trigger($channel, $event, $data);
            return true;
        } catch (Exception $e) {
            // fall through to HTTP fallback
        }
    }

    // 2) HTTP fallback (Pusher REST API v1 signatures)
    // Build REST URL
    $cluster = $PUSHER_APP_CLUSTER ?? '';
    $host = $cluster ? "api-{$cluster}.pusher.com" : "api.pusherapp.com";
    $urlPath = "/apps/{$PUSHER_APP_ID}/events";
    $body = json_encode(array_merge(['name' => $event, 'channels' => is_array($channel) ? $channel : [$channel], 'data' => $data], []));
    $body_md5 = md5($body);
    $auth_key = $PUSHER_APP_KEY;
    $auth_timestamp = time();
    $auth_version = '1.0';

    // string to sign: method + "\n" + path + "\n" + sorted_query_string
    // query string: auth_key, auth_timestamp, auth_version, body_md5
    $query = "auth_key={$auth_key}&auth_timestamp={$auth_timestamp}&auth_version={$auth_version}&body_md5={$body_md5}";
    $string_to_sign = "POST\n{$urlPath}\n{$query}";
    $auth_signature = hash_hmac('sha256', $string_to_sign, $PUSHER_APP_SECRET);

    $full_url = "https://{$host}{$urlPath}?{$query}&auth_signature={$auth_signature}";

    // Use curl to POST JSON
    $ch = curl_init($full_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $resp = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($httpcode >= 200 && $httpcode < 300) return true;
    // failure
    return false;
}

/*
 * pusher_auth_response($socket_id, $channel_name)
 * - returns JSON (string) used by Pusher private-channel auth
 * - Format: { "auth": "<app_key>:<signature>" , "channel_data": ... } (channel_data optional)
 */
function pusher_auth_response($socket_id, $channel_name) {
    global $PUSHER_APP_SECRET, $PUSHER_APP_KEY;
    if (empty($PUSHER_APP_SECRET) || empty($PUSHER_APP_KEY)) return null;
    $string_to_sign = $socket_id . ':' . $channel_name;
    $signature = hash_hmac('sha256', $string_to_sign, $PUSHER_APP_SECRET);
    $auth = $PUSHER_APP_KEY . ':' . $signature;
    return json_encode(['auth' => $auth]);
}

// ---------------------- admin token utilities ----------------------
$ADMIN_TOKEN_TTL = $ADMIN_TOKEN_TTL ?? 3600;
$ADMIN_TOKEN_SECRET = $ADMIN_TOKEN_SECRET ?? '';
$ADMIN_PASSWORD_HASH = $ADMIN_PASSWORD_HASH ?? '';

// Helper: check password with a few fallbacks so login doesn't fail silently
function admin_check_password($plain) {
    global $ADMIN_PASSWORD_HASH;
    if ($plain === '') return false;
    // If password hash looks like bcrypt or other password_hash output, use password_verify
    if (function_exists('password_verify') && is_string($ADMIN_PASSWORD_HASH) && (strpos($ADMIN_PASSWORD_HASH, '$2y$') === 0 || strpos($ADMIN_PASSWORD_HASH, '$2a$') === 0 || strpos($ADMIN_PASSWORD_HASH, '$argon2') !== false)) {
        return password_verify($plain, $ADMIN_PASSWORD_HASH);
    }
    // If ADMIN_PASSWORD_HASH is a sha256 of the password
    if (hash('sha256', $plain) === $ADMIN_PASSWORD_HASH) return true;
    // As a last resort allow direct equality (if ADMIN_PASSWORD_HASH stored plain — not recommended)
    if ($plain === $ADMIN_PASSWORD_HASH) return true;
    return false;
}

function make_admin_token($secret, $ttl = 3600) {
    $payload = ["exp" => time() + $ttl];
    $json = json_encode($payload);
    $b64 = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    $sig = hash_hmac('sha256', $b64, $secret);
    return $b64 . '.' . $sig;
}
function validate_admin_token($token, $secret) {
    if (!$token || strpos($token, '.') === false) return false;
    list($b64, $sig) = explode('.', $token, 2);
    $check = hash_hmac('sha256', $b64, $secret);
    if (!hash_equals($check, $sig)) return false;
    $json = base64_decode(strtr($b64, '-_', '+/'));
    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['exp'])) return false;
    return $data['exp'] >= time();
}
function get_client_token() {
    if (!empty($_SERVER['HTTP_X_ADMIN_TOKEN'])) return $_SERVER['HTTP_X_ADMIN_TOKEN'];
    if (!empty($_POST['token'])) return $_POST['token'];
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null);
    if ($auth && stripos($auth, 'bearer ') === 0) return trim(substr($auth, 7));
    return null;
}

// ---------------------- role list ----------------------
$ROLE_OPTIONS = [
    "owner","moderator","member",
    "red","orange","amber","yellow","lime","green","teal","cyan","sky","blue","indigo","violet","purple","fuchsia","pink","rose",
    "black","charcoal","slate","grey","silver","white",
    "gold","bronze","olive","mint","pearl","neon"
];

// ---------------------- handle actions ----------------------
$action = $_REQUEST['action'] ?? '';

if ($action === 'login') {
    // POST { code: plain password }
    $code = trim($_POST['code'] ?? '');
    if ($code === '') json_err("Missing code", 400);
    if (admin_check_password($code)) {
        if (empty($ADMIN_TOKEN_SECRET)) json_err("Admin token secret not configured", 500);
        $token = make_admin_token($ADMIN_TOKEN_SECRET, $ADMIN_TOKEN_TTL);
        json_ok(["token" => $token]);
    } else {
        json_err("Invalid code", 403);
    }
}

// All other actions require token
if ($action !== '') {
    $token = get_client_token();
    if (!validate_admin_token($token, $ADMIN_TOKEN_SECRET)) json_err("Not authenticated", 403);

    // ensure small columns exist (non-blocking)
    $users_cols = get_table_columns($pdo, 'users');
    if (!in_array('role', $users_cols)) {
        try_add_column($pdo, 'users', "role VARCHAR(32) NOT NULL DEFAULT 'member'");
        $users_cols = get_table_columns($pdo, 'users');
    }
    if (!in_array('timeout_until', $users_cols)) {
        try_add_column($pdo, 'users', "timeout_until DATETIME NULL DEFAULT NULL");
        $users_cols = get_table_columns($pdo, 'users');
    }

    // ---------- create_room ----------
    if ($action === 'create_room') {
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $cols = get_table_columns($pdo, 'private_rooms');
        if (!in_array('code', $cols) && !in_array('room_code', $cols)) {
            try_add_column($pdo, 'private_rooms', "code VARCHAR(64) NOT NULL DEFAULT ''");
            $cols = get_table_columns($pdo, 'private_rooms');
        }
        if (!in_array('name', $cols)) {
            try_add_column($pdo, 'private_rooms', "name VARCHAR(255) NOT NULL DEFAULT ''");
            $cols = get_table_columns($pdo, 'private_rooms');
        }
        if ($code === '') $code = substr(bin2hex(random_bytes(4)), 0, 10);
        if ($name === '') $name = "Private Room";
        try {
            if (in_array('code', $cols) && in_array('name', $cols)) {
                $stmt = $pdo->prepare("INSERT INTO private_rooms (code, name) VALUES (?, ?)");
                $stmt->execute([$code, $name]);
            } elseif (in_array('room_code', $cols) && in_array('name', $cols)) {
                $stmt = $pdo->prepare("INSERT INTO private_rooms (room_code, name) VALUES (?, ?)");
                $stmt->execute([$code, $name]);
            } elseif (in_array('code', $cols)) {
                $stmt = $pdo->prepare("INSERT INTO private_rooms (code) VALUES (?)");
                $stmt->execute([$code]);
            } elseif (in_array('room_code', $cols)) {
                $stmt = $pdo->prepare("INSERT INTO private_rooms (room_code) VALUES (?)");
                $stmt->execute([$code]);
            } else {
                $pdo->exec("INSERT INTO private_rooms () VALUES ()");
                $id = $pdo->lastInsertId();
                $cols = get_table_columns($pdo, 'private_rooms');
                if (in_array('code', $cols)) {
                    $stmt = $pdo->prepare("UPDATE private_rooms SET code = ? WHERE id = ?");
                    $stmt->execute([$code, $id]);
                } elseif (in_array('room_code', $cols)) {
                    $stmt = $pdo->prepare("UPDATE private_rooms SET room_code = ? WHERE id = ?");
                    $stmt->execute([$code, $id]);
                }
            }
            json_ok(["message" => "Created", "code" => $code]);
        } catch (Exception $e) {
            json_err("Create failed: " . $e->getMessage());
        }
    }

    // ---------- delete_room ----------
    if ($action === 'delete_room') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) json_err("Bad id");
        try {
            $stmt = $pdo->prepare("DELETE FROM private_rooms WHERE id = ?");
            $stmt->execute([$id]);
            json_ok(["message" => "Deleted"]);
        } catch (Exception $e) {
            json_err("Delete failed: " . $e->getMessage());
        }
    }

    // ---------- list_rooms ----------
    if ($action === 'list_rooms') {
        try {
            $cols = get_table_columns($pdo, 'private_rooms');
            if (count($cols) === 0) json_ok(["rooms" => []]);
            $select = [];
            if (in_array('id', $cols)) $select[] = 'id';
            if (in_array('code', $cols)) $select[] = 'code';
            if (in_array('room_code', $cols) && !in_array('code', $cols)) $select[] = 'room_code AS code';
            if (in_array('name', $cols)) $select[] = 'name';
            if (in_array('created_at', $cols)) $select[] = 'created_at';
            $stmt = $pdo->query("SELECT " . implode(", ", $select) . " FROM private_rooms ORDER BY id DESC");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            json_ok(["rooms" => $rows]);
        } catch (Exception $e) {
            json_err("List failed: ".$e->getMessage());
        }
    }

    // ---------- list_users ----------
    if ($action === 'list_users') {
        try {
            $cols = get_table_columns($pdo, 'users');
            $select = ['id', 'username'];
            if (in_array('avatar', $cols)) $select[] = 'avatar';
            if (in_array('role', $cols)) $select[] = 'role';
            if (in_array('color', $cols)) $select[] = 'color';
            if (in_array('timeout_until', $cols)) $select[] = 'timeout_until';
            if (in_array('created_at', $cols)) $select[] = 'created_at';
            $sql = "SELECT " . implode(", ", $select) . " FROM users ORDER BY id DESC LIMIT 500";
            $stmt = $pdo->query($sql);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            json_ok(["users" => $rows, "roles" => array_values($GLOBALS['ROLE_OPTIONS'])]);
        } catch (Exception $e) {
            json_err("List users failed: ".$e->getMessage());
        }
    }

    // ---------- set_role ----------
    if ($action === 'set_role') {
        $uid = (int)($_POST['id'] ?? 0);
        $role = trim($_POST['role'] ?? '');
        if ($uid <= 0 || $role === '') json_err("Bad params");
        if (!in_array($role, $GLOBALS['ROLE_OPTIONS'])) json_err("Invalid role");
        try {
            $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
            $stmt->execute([$role, $uid]);
            json_ok();
        } catch (Exception $e) {
            json_err("Set role failed: ".$e->getMessage());
        }
    }

    // ---------- timeout_user ----------
    if ($action === 'timeout_user') {
        $uid = (int)($_POST['id'] ?? 0);
        $mins = (int)($_POST['minutes'] ?? 0);
        if ($uid <= 0 || $mins <= 0) json_err("Bad params");
        try {
            $dt = (new DateTime())->modify("+{$mins} minutes")->format('Y-m-d H:i:s');
            $stmt = $pdo->prepare("UPDATE users SET timeout_until = ? WHERE id = ?");
            $stmt->execute([$dt, $uid]);
            json_ok(["timeout_until" => $dt]);
        } catch (Exception $e) {
            json_err("Timeout failed: ".$e->getMessage());
        }
    }

    // ---------- clear_timeout ----------
    if ($action === 'clear_timeout') {
        $uid = (int)($_POST['id'] ?? 0);
        if ($uid <= 0) json_err("Bad params");
        try {
            $stmt = $pdo->prepare("UPDATE users SET timeout_until = NULL WHERE id = ?");
            $stmt->execute([$uid]);
            json_ok();
        } catch (Exception $e) {
            json_err("Clear timeout failed: ".$e->getMessage());
        }
    }

    // ---------- list_bans ----------
    if ($action === 'list_bans') {
        try {
            $stmt = $pdo->query("SELECT id, user_id, username, deleted_by, reason, created_at FROM account_deletions ORDER BY id DESC LIMIT 200");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            json_ok(["bans" => $rows]);
        } catch (Exception $e) {
            json_err("List deletions failed: ".$e->getMessage());
        }
    }

    // ---------- add_ban ----------
    if ($action === 'add_ban') {
        $target = trim((string)($_POST['ip'] ?? $_POST['username'] ?? ''));
        $reason = trim($_POST['reason'] ?? '');
        if ($target === '') json_err("Missing username");
        try {
            $stmt = $pdo->prepare("SELECT id, role, role_id, username FROM users WHERE username = ?");
            $stmt->execute([$target]);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($users)) json_ok(["message" => "No user found with that username", "deleted" => [], "anonymized" => []]);

            $deletedIds = []; $anonymizedIds = []; $logEntries = [];
            foreach ($users as $u) {
                $uid = (int)$u['id'];
                $roleName = strtolower((string)($u['role'] ?? ''));
                $roleId = isset($u['role_id']) ? (int)$u['role_id'] : null;
                $isPrivileged = false;
                if ($roleId !== null && $roleId < 3) $isPrivileged = true;
                $checkNames = ['site administrator','site moderator','admin','owner','moderator'];
                foreach ($checkNames as $n) { if (strpos($roleName, $n) !== false) { $isPrivileged = true; break; } }
                if ($isPrivileged) continue;
                try {
                    $pdo->beginTransaction();
                    $delStmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                    $delStmt->execute([$uid]);
                    $pdo->commit();
                    $deletedIds[] = $uid;
                    $log = $pdo->prepare("INSERT INTO account_deletions (user_id, username, deleted_by, reason) VALUES (?, ?, ?, ?)");
                    $log->execute([$uid, $u['username'], null, $reason]);
                    $logEntries[] = $pdo->lastInsertId();
                } catch (Exception $e) {
                    try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Exception $_) {}
                    $userCols = get_table_columns($pdo, 'users');
                    $updateSQL = "UPDATE users SET username = ?, auth_token = NULL, avatar = NULL, role = 'deleted'";
                    if (in_array('password', $userCols)) $updateSQL .= ", password = NULL";
                    if (in_array('password_hash', $userCols)) $updateSQL .= ", password_hash = NULL";
                    if (in_array('passwd', $userCols)) $updateSQL .= ", passwd = NULL";
                    if (in_array('email', $userCols)) $updateSQL .= ", email = NULL";
                    $updateSQL .= " WHERE id = ?";
                    $newUsername = 'deleted_user_' . $uid;
                    $uStmt = $pdo->prepare($updateSQL);
                    $uStmt->execute([$newUsername, $uid]);
                    $anonymizedIds[] = $uid;
                    $log = $pdo->prepare("INSERT INTO account_deletions (user_id, username, deleted_by, reason) VALUES (?, ?, ?, ?)");
                    $log->execute([$uid, $newUsername, null, $reason]);
                    $logEntries[] = $pdo->lastInsertId();
                }
            }
            json_ok(["message" => "Account deletion attempted", "deleted" => $deletedIds, "anonymized" => $anonymizedIds, "log_ids" => $logEntries]);
        } catch (Exception $e) {
            json_err("Delete failed: ".$e->getMessage());
        }
    }

    // ---------- remove_ban ----------
    if ($action === 'remove_ban') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) json_err("Bad id");
        try {
            $stmt = $pdo->prepare("DELETE FROM account_deletions WHERE id = ?");
            $stmt->execute([$id]);
            json_ok();
        } catch (Exception $e) {
            json_err("Remove log entry failed: ".$e->getMessage());
        }
    }

    // ---------- send_modmail (optimized) ----------
    if ($action === 'send_modmail') {
        $title = trim((string)($_POST['title'] ?? ''));
        $subtitle = trim((string)($_POST['subtitle'] ?? ''));
        $body = trim((string)($_POST['body'] ?? ''));
        $from = trim((string)($_POST['from_name'] ?? 'Site Admin'));
        $important = (!empty($_POST['important']) && ($_POST['important'] === '1' || $_POST['important'] === 'true')) ? 1 : 0;
        $for_new = (!empty($_POST['for_new_users']) && ($_POST['for_new_users'] === '1' || $_POST['for_new_users'] === 'true')) ? 1 : 0;
        if ($title === '' || $body === '') json_err("Title and body required");

        try {
            // Insert modmail row (assumes table exists)
            $stmt = $pdo->prepare("INSERT INTO modmail (title, subtitle, body, admin_name, important, for_new_users) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$title, $subtitle, $body, $from, $important, $for_new]);
            $mid = (int)$pdo->lastInsertId();

            // Insert notification rows in single statement (assumes notifications table exists)
            $ins = $pdo->prepare("INSERT INTO notifications (user_id, type, source_user_id, message, context, ref_id, important)
                                  SELECT id, ?, NULL, ?, ?, ?, ? FROM users");
            $ins->execute(['modmail', $title, 'modmail', $mid, $important]);

            // Broadcast a single lightweight public event so clients can refresh quickly
            $payload = ['modmail_id' => $mid, 'title' => $title, 'important' => (int)$important, 'created_at' => gmdate('Y-m-d\TH:i:s\Z')];
            pusher_trigger_event('public-modmail', 'new_modmail', $payload);

            json_ok(["message" => "Modmail sent", "modmail_id" => $mid]);
        } catch (Exception $e) {
            json_err("send_modmail failed: " . $e->getMessage());
        }
    }

    // ---------- images & other endpoints (unchanged, kept for completeness) ----------
    function _safe_filename($s) {
        $b = basename($s); if ($b !== $s) return false;
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $b)) return false;
        return $b;
    }
    $IMAGES_DIR = __DIR__ . '/images';
    if (!is_dir($IMAGES_DIR)) @mkdir($IMAGES_DIR, 0755, true);

    if ($action === 'list_images') {
        try {
            $results = [];
            $hasDB = false;
            try { $cols = get_table_columns($pdo, 'uploaded_images'); if (!empty($cols)) $hasDB = true; } catch (Exception $_) { $hasDB = false; }
            if ($hasDB) {
                $stmt = $pdo->query("SELECT id, filename, original_name, size_bytes, created_at FROM uploaded_images ORDER BY id DESC LIMIT 1000");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $fn = $r['filename']; if (!_safe_filename($fn)) continue;
                    $path = $IMAGES_DIR . '/' . $fn; $exists = file_exists($path);
                    $results[] = [
                        'filename' => $fn, 'original_name' => $r['original_name'] ?? null,
                        'size' => (int)($r['size_bytes'] ?? ($exists ? filesize($path) : 0)),
                        'created_at' => $r['created_at'] ?? null, 'exists' => $exists, 'url' => '/images/' . rawurlencode($fn)
                    ];
                }
            } else {
                $it = @scandir($IMAGES_DIR); if ($it === false) $it = [];
                foreach ($it as $ent) {
                    if ($ent === '.' || $ent === '..') continue; if (!_safe_filename($ent)) continue;
                    $path = $IMAGES_DIR . '/' . $ent; if (!is_file($path)) continue;
                    $results[] = ['filename' => $ent, 'original_name' => null, 'size' => filesize($path), 'created_at' => date('Y-m-d H:i:s', filemtime($path)), 'exists' => true, 'url' => '/images/' . rawurlencode($ent)];
                }
                usort($results, function($a,$b){ return strtotime($b['created_at']) <=> strtotime($a['created_at']); });
            }
            json_ok(['images' => $results]);
        } catch (Exception $e) { json_err("list_images failed: " . $e->getMessage()); }
    }

    if ($action === 'delete_image') {
        $filename = $_POST['filename'] ?? ''; if (!$filename || !_safe_filename($filename)) json_err("Bad filename");
        $path = $IMAGES_DIR . '/' . $filename;
        try { if (file_exists($path)) @unlink($path); try { $stmt = $pdo->prepare("DELETE FROM uploaded_images WHERE filename = ? LIMIT 1"); $stmt->execute([$filename]); } catch (Exception $_) {} json_ok(['message'=>'Deleted']); } catch (Exception $e) { json_err("delete_image failed: " . $e->getMessage()); }
    }

    if ($action === 'purge_images_older') {
        $days = max(1, (int)($_POST['days'] ?? 30)); $cut = time() - ($days * 24 * 3600); $deleted = 0;
        try { $it = @scandir($IMAGES_DIR); if ($it === false) $it = []; foreach ($it as $ent) { if ($ent === '.' || $ent === '..') continue; if (!_safe_filename($ent)) continue; $path = $IMAGES_DIR . '/' . $ent; if (!is_file($path)) continue; if (filemtime($path) < $cut) { @unlink($path); $deleted++; try { $stmt = $pdo->prepare("DELETE FROM uploaded_images WHERE filename = ?"); $stmt->execute([$ent]); } catch (Exception $_) {} } } json_ok(['deleted' => $deleted]); } catch (Exception $e) { json_err("purge_images_older failed: " . $e->getMessage()); }
    }

    if ($action === 'purge_images_all') {
        $deleted = 0;
        try { $it = @scandir($IMAGES_DIR); if ($it === false) $it = []; foreach ($it as $ent) { if ($ent === '.' || $ent === '..') continue; if (!_safe_filename($ent)) continue; $path = $IMAGES_DIR . '/' . $ent; if (!is_file($path)) continue; @unlink($path); $deleted++; } try { $pdo->exec("TRUNCATE TABLE uploaded_images"); } catch (Exception $_) {} json_ok(['deleted' => $deleted]); } catch (Exception $e) { json_err("purge_images_all failed: " . $e->getMessage()); }
    }

    json_err("Unknown action");
}

// ---------------------- No action -> serve HTML UI ----------------------
header_remove("Content-Type");
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Admin Panel</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
:root{--bg:#0f1113;--panel:#18191b;--muted:#cfcfcf;--accent:#3b82f6;--modmail-blue:#2b6fb2}
body{margin:0;font-family:Inter,Arial,Helvetica,sans-serif;background:var(--bg);color:white}
.container{max-width:1100px;margin:24px auto;padding:18px;background:var(--panel);border-radius:10px}
.header{display:flex;align-items:center;justify-content:space-between;gap:12px}
.h1{font-size:18px;font-weight:700}
.controls{display:flex;gap:8px;align-items:center}
.btn{background:var(--accent);border:0;color:white;padding:8px 10px;border-radius:6px;cursor:pointer}
.btn.warn{background:#d9534f}
.grid{display:grid;grid-template-columns:1fr 380px;gap:16px;margin-top:16px}
.panel{background:#0b0c0d;padding:12px;border-radius:8px}
.small{font-size:13px;color:var(--muted)}
.table{width:100%;border-collapse:collapse}
.table th,.table td{padding:8px;border-bottom:1px solid #222;text-align:left;font-size:14px}
.formRow{display:flex;gap:8px;align-items:center;margin-bottom:8px}
.input,select,textarea{padding:8px;border-radius:6px;border:0;background:#151617;color:white}
textarea{min-height:120px;resize:vertical}
.roleTag{display:inline-block;padding:4px 8px;border-radius:6px;font-size:13px;margin-right:6px}
.actions{display:flex;gap:8px}
.userAvatar{width:36px;height:36px;border-radius:50%;object-fit:cover;border:2px solid #111}
.avatarLarge{width:64px;height:64px;border-radius:50%;object-fit:cover;border:3px solid #111}

/* modmail preview bubble */
.modmailPreview{background:linear-gradient(180deg, rgba(43,111,178,0.08), rgba(43,111,178,0.02));padding:14px;border-radius:12px;border-left:4px solid var(--modmail-blue);margin-top:12px}
.modmailTitle{font-weight:800;font-size:18px;color:var(--modmail-blue)}
.modmailSubtitle{color:#dbeaff;margin-top:6px}
.modmailBody{margin-top:8px;color:#e9f3ff;line-height:1.5;white-space:pre-wrap}

/* images panel */
.imagesGrid { display:grid; gap:8px; grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); align-items:start; }
.imageCard { background: rgba(255,255,255,0.02); padding:8px; border-radius:8px; display:flex; flex-direction:column; gap:8px; align-items:center; text-align:center; }
.imageCard img { width:100%; height:80px; object-fit:cover; border-radius:6px; border:1px solid rgba(255,255,255,0.03); }
.imageMeta { font-size:12px; color:var(--muted); word-break:break-all; max-width:100%; }

/* small responsive */
@media (max-width:900px){ .grid{grid-template-columns:1fr} }
</style>
</head>
<body>
<div class="container" id="app">
    <div class="header">
        <div>
            <div class="h1">Admin Panel</div>
            <div class="small">Manage rooms, users, roles, timeouts & send modmail</div>
        </div>
        <div class="controls" id="authArea">
            <!-- login/logout buttons injected by JS -->
        </div>
    </div>

    <div id="mainContent" style="display:none">
        <div class="grid">
            <div>
                <div class="panel" style="margin-bottom:12px">
                    <div style="display:flex;justify-content:space-between;align-items:center">
                        <div><strong>Rooms</strong><div class="small">Create / delete private chat rooms</div></div>
                        <div><button class="btn" id="refreshRooms">Refresh</button></div>
                    </div>

                    <div style="margin-top:10px" id="createRoomArea">
                        <div class="formRow">
                            <input id="newRoomCode" class="input" placeholder="room code (optional)">
                            <input id="newRoomName" class="input" placeholder="room name (optional)">
                            <button class="btn" id="createRoomBtn">Create</button>
                        </div>
                        <div class="small">If code blank -> auto-generate. Compatible with `code` or `room_code` column.</div>
                    </div>

                    <div style="margin-top:12px; max-height:360px; overflow:auto">
                        <table class="table" id="roomsTable">
                            <thead><tr><th>ID</th><th>Code</th><th>Name</th><th>Created</th><th>Actions</th></tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>

                <div class="panel">
                    <div style="display:flex;justify-content:space-between;align-items:center">
                        <div><strong>Users</strong><div class="small">View users, assign roles & timeouts</div></div>
                        <div><button class="btn" id="refreshUsers">Refresh</button></div>
                    </div>

                    <div style="margin-top:10px; max-height:420px; overflow:auto">
                        <table class="table" id="usersTable">
                            <thead><tr><th></th><th>Username</th><th>Role</th><th>Timeout</th><th>Actions</th></tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>

            </div>

            <div>
                <div class="panel">
                    <div style="display:flex;justify-content:space-between;align-items:center">
                        <div><strong>Delete Account</strong><div class="small">Enter username to delete (or anonymize)</div></div>
                        <div><button class="btn" id="refreshBans">Refresh</button></div>
                    </div>

                    <div style="margin-top:10px">
                        <div class="formRow">
                            <input id="banIp" class="input" placeholder="Username to delete (exact match)">
                            <input id="banReason" class="input" placeholder="Reason (optional)">
                            <button class="btn warn" id="addBanBtn">Delete</button>
                        </div>
                        <div style="max-height:300px; overflow:auto; margin-top:8px">
                            <table class="table" id="bansTable">
                                <thead><tr><th>Log ID</th><th>User ID</th><th>Username</th><th>Deleted By</th><th>Reason</th><th>Created</th><th>Action</th></tr></thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Modmail panel -->
                <div class="panel" style="margin-top:12px">
                    <div style="display:flex;justify-content:space-between;align-items:center">
                        <div><strong>Send Modmail</strong><div class="small">Message will be delivered to all users via notifications</div></div>
                    </div>

                    <div style="margin-top:10px">
                        <div class="formRow" style="flex-direction:column;align-items:stretch">
                            <input id="modmailFrom" class="input" placeholder="From (optional, default: Site Admin)" style="margin-bottom:8px">
                            <input id="modmailTitle" class="input" placeholder="Title" style="margin-bottom:8px">
                            <input id="modmailSubtitle" class="input" placeholder="Subtitle (optional)" style="margin-bottom:8px">
                            <textarea id="modmailBody" class="input" placeholder="Message body (full text)"></textarea>
                            <div style="display:flex;gap:8px;margin-top:8px;align-items:center">
                                <label style="display:flex;gap:6px;align-items:center"><input type="checkbox" id="modmailImportant"> <span class="small">Mark as IMPORTANT</span></label>
                                <label style="display:flex;gap:6px;align-items:center"><input type="checkbox" id="modmailForNew"> <span class="small">Send to future new users (orientation)</span></label>
                                <div style="flex:1"></div>
                                <button class="btn" id="sendModmailBtn">Send</button>
                                <button class="btn" id="previewModmailBtn">Preview</button>
                            </div>
                            <div id="modmailStatus" class="small" style="margin-top:8px;color:#ddd"></div>

                            <div id="modmailPreview" style="display:none">
                              <div class="modmailPreview" id="modmailPreviewBubble">
                                <div style="display:flex;gap:12px;align-items:center">
                                  <img src="root/favicon.ico" style="width:48px;height:48px;border-radius:8px" alt="admin">
                                  <div>
                                    <div class="modmailTitle" id="previewTitle"></div>
                                    <div class="modmailSubtitle" id="previewSubtitle"></div>
                                    <div style="margin-top:6px;font-size:12px;color:#bcd6ff" id="previewMeta"></div>
                                  </div>
                                </div>
                                <div class="modmailBody" id="previewBody"></div>
                              </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Image purge panel -->
                <div class="panel" style="margin-top:12px">
                    <div style="display:flex;justify-content:space-between;align-items:center">
                        <div><strong>Purge Images</strong><div class="small">View and remove files from /images</div></div>
                        <div><button class="btn" id="refreshImages">Refresh</button></div>
                    </div>

                    <div style="margin-top:12px">
                        <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px">
                            <input id="purgeDays" class="input" placeholder="Days older than (default 30)" style="width:160px">
                            <button class="btn warn" id="purgeOlderBtn">Purge Older</button>
                            <div style="flex:1"></div>
                            <button class="btn warn" id="purgeAllBtn">Purge All</button>
                        </div>

                        <div id="imagesArea" style="max-height:300px;overflow:auto;padding-top:6px">
                            <div class="imagesGrid" id="imagesGrid">
                                <div style="color:var(--muted)">Loading…</div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <div id="loginArea" style="margin-top:18px">
        <div class="panel">
            <div style="display:flex;justify-content:space-between;align-items:center">
                <div><strong>Admin login</strong><div class="small">Enter admin password</div></div>
            </div>
            <div style="margin-top:10px">
                <input id="adminCode" class="input" placeholder="admin password" type="password" style="width:260px">
                <button class="btn" id="adminLoginBtn">Login</button>
                <div id="loginMsg" class="small" style="margin-top:8px;color:#ddd"></div>
            </div>
        </div>
    </div>

</div>

<script>
/*
  Client flow:
   - POST action=login -> server returns { ok:true, token }
   - client stores token in localStorage 'admin_token'
   - subsequent API calls send X-Admin-Token header
*/

const API = (async (action, data={}) => {
    const token = localStorage.getItem('admin_token') || '';
    const form = new FormData();
    form.append('action', action);
    for (const k in data) form.append(k, data[k]);
    const headers = {};
    if (token) headers['X-Admin-Token'] = token;
    const resp = await fetch('admin.php', { method: 'POST', body: form, headers, credentials: 'same-origin' });
    return resp.json();
});

function el(q){ return document.querySelector(q); }

const loginArea = el('#loginArea');
const mainContent = el('#mainContent');
const authArea = el('#authArea');

function showLogoutBtn(){
    authArea.innerHTML = `<button class="btn" id="logoutBtn">Logout</button>`;
    document.getElementById('logoutBtn').addEventListener('click', ()=>{
        localStorage.removeItem('admin_token');
        loginArea.style.display = '';
        mainContent.style.display = 'none';
    });
}

async function tryCheckAuth(){
    try {
        const res = await API('list_rooms');
        if (res.ok) {
            loginArea.style.display = 'none';
            mainContent.style.display = 'block';
            showLogoutBtn();
            refreshAll();
        } else {
            loginArea.style.display = '';
            mainContent.style.display = 'none';
            localStorage.removeItem('admin_token');
        }
    } catch (e) {
        loginArea.style.display = '';
        mainContent.style.display = 'none';
        localStorage.removeItem('admin_token');
    }
}

async function doLogin(){
    const pw = el('#adminCode').value.trim();
    if (!pw) { el('#loginMsg').innerText = 'Enter password'; return; }
    const form = new FormData();
    form.append('action','login');
    form.append('code', pw);
    const resp = await fetch('admin.php', { method: 'POST', body: form, credentials: 'same-origin' });
    const res = await resp.json();
    if (res.ok && res.token) {
        localStorage.setItem('admin_token', res.token);
        el('#loginMsg').innerText = 'Logged in';
        tryCheckAuth();
    } else {
        el('#loginMsg').innerText = res.error || 'Login failed';
    }
}

// Rooms
const roomsTableBody = el('#roomsTable tbody');
async function refreshRooms(){
    const res = await API('list_rooms');
    if (!res.ok) { roomsTableBody.innerHTML = '<tr><td colspan="5">Unable to load</td></tr>'; return; }
    roomsTableBody.innerHTML = '';
    for (const r of res.rooms){
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${r.id ?? ''}</td><td>${r.code ?? ''}</td><td>${r.name ?? ''}</td><td>${r.created_at ?? ''}</td>
            <td><div class="actions">
              <button class="btn" data-code="${r.code}">Open</button>
              <button class="btn warn" data-del="${r.id}">Delete</button>
            </div></td>`;
        roomsTableBody.appendChild(tr);
    }
    // handlers
    roomsTableBody.querySelectorAll('button[data-del]').forEach(b=>{
        b.addEventListener('click', async ()=>{
            if (!confirm('Delete this room?')) return;
            const id = b.getAttribute('data-del');
            await API('delete_room', { id });
            refreshRooms();
        });
    });
    roomsTableBody.querySelectorAll('button[data-code]').forEach(b=>{
        b.addEventListener('click', ()=>{
            const code = b.getAttribute('data-code');
            if (!code) return alert('No code');
            window.open('private.php?code=' + encodeURIComponent(code), '_blank');
        });
    });
}
el('#createRoomBtn').addEventListener('click', async ()=>{
    const code = el('#newRoomCode').value.trim();
    const name = el('#newRoomName').value.trim();
    const res = await API('create_room', { code, name });
    if (res.ok) {
        el('#newRoomCode').value = '';
        el('#newRoomName').value = '';
        refreshRooms();
        alert('Created: ' + (res.code || ''));
    } else alert(res.error || 'error');
});

// Users
const usersTableBody = el('#usersTable tbody');
async function refreshUsers(){
    const res = await API('list_users');
    if (!res.ok) { usersTableBody.innerHTML = '<tr><td colspan="5">Unable to load</td></tr>'; return; }
    usersTableBody.innerHTML = '';
    const roles = res.roles || [];
    for (const u of res.users){
        const uid = u.id;
        const avatar = u.avatar ? `<img src="avatars/${u.avatar}" class="userAvatar">` : `<div class="userAvatar" style="background:#5865F2"></div>`;
        const role = u.role ?? 'member';
        const timeout = u.timeout_until ?? '';
        const sel = document.createElement('select');
        sel.className = 'input roleSelect';
        sel.setAttribute('data-uid', uid);
        for (const r of roles) {
            const opt = document.createElement('option');
            opt.value = r;
            opt.innerText = r;
            if (r === role) opt.selected = true;
            sel.appendChild(opt);
        }
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${avatar}</td>
            <td>${u.username}</td>
            <td></td>
            <td>${timeout ? timeout : ''}</td>
            <td>
                <div class="actions">
                    <input placeholder="mins" class="input timeoutInput" style="width:60px" data-uid="${uid}">
                    <button class="btn" data-timeout="${uid}">Timeout</button>
                    <button class="btn" data-clear="${uid}">Clear</button>
                </div>
            </td>`;
        tr.children[2].appendChild(sel); // insert select into 3rd cell
        usersTableBody.appendChild(tr);
    }

    // attach handlers
    usersTableBody.querySelectorAll('.roleSelect').forEach(sel=>{
        sel.addEventListener('change', async ()=>{
            const uid = sel.getAttribute('data-uid');
            await API('set_role', { id: uid, role: sel.value });
            refreshUsers();
        });
    });

    usersTableBody.querySelectorAll('button[data-timeout]').forEach(b=>{
        b.addEventListener('click', async ()=>{
            const uid = b.getAttribute('data-timeout');
            const input = document.querySelector('.timeoutInput[data-uid="'+uid+'"]');
            const mins = parseInt(input.value || 0);
            if (!mins || mins < 1) return alert('Enter minutes');
            await API('timeout_user', { id: uid, minutes: mins });
            refreshUsers();
        });
    });
    usersTableBody.querySelectorAll('button[data-clear]').forEach(b=>{
        b.addEventListener('click', async ()=>{
            const uid = b.getAttribute('data-clear');
            if (!confirm('Clear timeout?')) return;
            await API('clear_timeout', { id: uid });
            refreshUsers();
        });
    });
}

// "Bans" panel repurposed to deletion log
const bansTableBody = el('#bansTable tbody');
async function refreshBans(){
    const res = await API('list_bans');
    if (!res.ok) { bansTableBody.innerHTML = '<tr><td colspan="7">Unable to load</td></tr>'; return; }
    bansTableBody.innerHTML = '';
    for (const b of res.bans) {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${b.id}</td><td>${b.user_id ?? ''}</td><td>${b.username ?? ''}</td><td>${b.deleted_by ?? ''}</td><td>${b.reason ?? ''}</td><td>${b.created_at ?? ''}</td>
            <td><button class="btn warn" data-ban="${b.id}">Remove Log</button></td>`;
        bansTableBody.appendChild(tr);
    }
    bansTableBody.querySelectorAll('button[data-ban]').forEach(b=>{
        b.addEventListener('click', async ()=>{
            if (!confirm('Remove this log entry?')) return;
            const id = b.getAttribute('data-ban');
            await API('remove_ban', { id });
            refreshBans();
        });
    });
}
el('#addBanBtn').addEventListener('click', async ()=>{
    const username = el('#banIp').value.trim(); // kept id for compatibility
    const reason = el('#banReason').value.trim();
    if (!username) return alert('Enter username');
    const res = await API('add_ban', { username, reason });
    if (!res.ok) return alert(res.error || 'Failed');
    el('#banIp').value = '';
    el('#banReason').value = '';
    refreshBans();
    if ((res.deleted && res.deleted.length) || (res.anonymized && res.anonymized.length)) {
        alert('Deleted accounts: ' + (res.deleted||[]).join(', ') + '\nAnonymized (fallback): ' + (res.anonymized||[]).join(', '));
        refreshUsers();
    } else {
        alert(res.message || 'Done');
    }
});

// Modmail: send and preview
el('#sendModmailBtn').addEventListener('click', async ()=>{
    const from = el('#modmailFrom').value.trim() || 'Site Admin';
    const title = el('#modmailTitle').value.trim();
    const subtitle = el('#modmailSubtitle').value.trim();
    const body = el('#modmailBody').value.trim();
    const important = el('#modmailImportant').checked ? '1' : '0';
    const forNew = el('#modmailForNew').checked ? '1' : '0';
    if (!title || !body) {
        el('#modmailStatus').textContent = 'Title and message body required';
        return;
    }
    el('#modmailStatus').textContent = 'Sending...';
    const res = await API('send_modmail', { title, subtitle, body, from_name: from, important, for_new_users: forNew });
    if (res.ok) {
        el('#modmailStatus').textContent = 'Sent to all users (modmail id: ' + (res.modmail_id || '') + ')';
        el('#modmailTitle').value = '';
        el('#modmailSubtitle').value = '';
        el('#modmailBody').value = '';
        el('#modmailImportant').checked = false;
        el('#modmailForNew').checked = false;
        el('#modmailPreview').style.display = 'none';
    } else {
        el('#modmailStatus').textContent = res.error || 'Failed';
    }
});
el('#previewModmailBtn').addEventListener('click', ()=>{
    const from = el('#modmailFrom').value.trim() || 'Site Admin';
    const title = el('#modmailTitle').value.trim();
    const subtitle = el('#modmailSubtitle').value.trim();
    const body = el('#modmailBody').value.trim();
    if (!title || !body) { alert('Please enter title and body to preview'); return; }
    el('#previewTitle').textContent = title;
    el('#previewSubtitle').textContent = subtitle;
    el('#previewMeta').textContent = 'From: ' + from + (el('#modmailImportant').checked ? ' • IMPORTANT' : '');
    el('#previewBody').textContent = body;
    el('#modmailPreview').style.display = 'block';
});

// Images UI
const imagesGrid = el('#imagesGrid');
async function refreshImages(){
    imagesGrid.innerHTML = '<div style="color:var(--muted)">Loading…</div>';
    const res = await API('list_images');
    if (!res.ok) { imagesGrid.innerHTML = '<div style="color:#f66">Unable to load</div>'; return; }
    const imgs = res.images || [];
    if (imgs.length === 0) { imagesGrid.innerHTML = '<div style="color:var(--muted)">No images found</div>'; return; }
    imagesGrid.innerHTML = '';
    for (const im of imgs) {
        const card = document.createElement('div'); card.className = 'imageCard';
        const img = document.createElement('img');
        img.src = im.url;
        img.alt = im.filename;
        img.loading = 'lazy';
        card.appendChild(img);
        const meta = document.createElement('div'); meta.className = 'imageMeta';
        meta.innerHTML = `<div title="${im.filename}">${im.filename}</div><div>${(im.size/1024|0)} KB</div><div>${im.created_at ?? ''}</div>`;
        card.appendChild(meta);
        const row = document.createElement('div'); row.style.display='flex'; row.style.gap='6px';
        const del = document.createElement('button'); del.className='btn warn'; del.textContent='Delete'; del.style.padding='6px 8px';
        del.addEventListener('click', async ()=>{
            if (!confirm('Delete this image?')) return;
            const r = await API('delete_image', { filename: im.filename });
            if (r.ok) { card.remove(); } else alert(r.error || 'Delete failed');
        });
        row.appendChild(del);
        card.appendChild(row);
        imagesGrid.appendChild(card);
    }
}

el('#refreshImages').addEventListener('click', refreshImages);

el('#purgeOlderBtn').addEventListener('click', async ()=>{
    const days = parseInt(el('#purgeDays').value || '30',10) || 30;
    if (!confirm('Purge images older than ' + days + ' days? This cannot be undone.')) return;
    const res = await API('purge_images_older', { days: days });
    if (res.ok) { alert('Deleted: ' + (res.deleted || 0)); refreshImages(); } else alert(res.error || 'Failed');
});
el('#purgeAllBtn').addEventListener('click', async ()=>{
    if (!confirm('Purge ALL images? This will delete all files in /images and cannot be undone.')) return;
    const res = await API('purge_images_all', {});
    if (res.ok) { alert('Deleted: ' + (res.deleted || 0)); refreshImages(); } else alert(res.error || 'Failed');
});

// wiring
el('#refreshRooms').addEventListener('click', refreshRooms);
el('#refreshUsers').addEventListener('click', refreshUsers);
el('#refreshBans').addEventListener('click', refreshBans);
el('#adminLoginBtn').addEventListener('click', doLogin);

async function refreshAll(){
    await Promise.all([refreshRooms(), refreshUsers(), refreshBans(), refreshImages()]);
}

// initial check
tryCheckAuth();
</script>
</body>
</html>
