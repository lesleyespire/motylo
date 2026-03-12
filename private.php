<?php
// private.php - combined UI & AJAX endpoints for private rooms + moderation
require "config.php";

/* ---------------- DEBUG / ROBUSTNESS ---------------- */
// Turn on verbose errors while debugging. Turn off in production.
@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// small file-based debug logger (ensure /tmp is writable)
function mod_debug_log($msg) {
    $fn = '/tmp/private_mod_debug.log';
    @file_put_contents($fn, date('[Y-m-d H:i:s] ') . (is_scalar($msg) ? $msg : var_export($msg, true)) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/* capture fatal errors and return JSON for moderation requests */
register_shutdown_function(function(){
    $err = error_get_last();
    if (!$err) return;
    $fatalTypes = [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE];
    if (in_array($err['type'], $fatalTypes, true)) {
        mod_debug_log("[FATAL] " . json_encode($err));
        if (isset($_GET['action']) && $_GET['action'] === 'moderate') {
            @header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>false,'error'=>'fatal','msg'=>$err['message'],'file'=>$err['file'],'line'=>$err['line']]);
        } else {
            // simple debug page
            @header('Content-Type: text/plain; charset=utf-8');
            echo "Fatal error: {$err['message']} in {$err['file']} on line {$err['line']}\n";
        }
        exit;
    }
});

/* ------------------- helpers ------------------- */
function getRequestData() {
    // prefer $_POST
    if (!empty($_POST)) return $_POST;
    // read JSON body
    $raw = @file_get_contents('php://input');
    if ($raw !== false && trim($raw) !== '') {
        $j = @json_decode($raw, true);
        if (is_array($j)) return $j;
        $arr = [];
        parse_str($raw, $arr);
        if (!empty($arr)) return $arr;
    }
    return $_GET ?? [];
}

function table_exists($pdo, $name) {
    try {
        $q = $pdo->prepare("SHOW TABLES LIKE ?");
        $q->execute([$name]);
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
function roleColumnExists($pdo, $col) {
    try {
        $cols = get_table_columns($pdo, 'community_roles');
        return in_array(strtolower($col), $cols, true);
    } catch (Exception $e) { return false; }
}

function user_has_role($pdo, $community_id, $user_id, $role_id) {
    if ($community_id <= 0 || !$role_id) return false;
    if (table_exists($pdo, 'community_member_roles')) {
        $q = $pdo->prepare("SELECT 1 FROM community_member_roles WHERE community_id = ? AND user_id = ? AND role_id = ? LIMIT 1");
        $q->execute([$community_id, $user_id, $role_id]);
        return (bool)$q->fetchColumn();
    }
    $q = $pdo->prepare("SELECT 1 FROM community_members WHERE community_id = ? AND user_id = ? AND role_id = ? LIMIT 1");
    $q->execute([$community_id, $user_id, $role_id]);
    return (bool)$q->fetchColumn();
}
function user_has_perm($pdo, $community_id, $user_id, $perm_col) {
    if ($community_id <= 0) return false;
    if (table_exists($pdo, 'community_member_roles')) {
        $q = $pdo->prepare("SELECT 1 FROM community_member_roles mr JOIN community_roles cr ON cr.id = mr.role_id WHERE mr.community_id = ? AND mr.user_id = ? AND cr.{$perm_col} = 1 LIMIT 1");
        $q->execute([$community_id, $user_id]);
        return (bool)$q->fetchColumn();
    }
    $q = $pdo->prepare("SELECT cr.{$perm_col} FROM community_members cm JOIN community_roles cr ON cr.id = cm.role_id WHERE cm.community_id = ? AND cm.user_id = ? LIMIT 1");
    $q->execute([$community_id, $user_id]);
    return (bool)$q->fetchColumn();
}

function get_active_timeout_until($pdo, $community_id, $user_id) {
    if ($community_id <= 0 || !table_exists($pdo, 'community_timeouts')) return null;
    $cols = get_table_columns($pdo, 'community_timeouts');
    // support either 'until_at' or 'timeout_until'
    $col = in_array('until_at', $cols, true) ? 'until_at' : (in_array('timeout_until', $cols, true) ? 'timeout_until' : null);
    if (!$col) return null;
    $q = $pdo->prepare("SELECT {$col} FROM community_timeouts WHERE community_id = ? AND user_id = ? AND {$col} IS NOT NULL ORDER BY {$col} DESC LIMIT 1");
    $q->execute([$community_id, $user_id]);
    $until = $q->fetchColumn();
    if (!$until) return null;
    $ts = strtotime($until);
    if ($ts === false || $ts <= time()) return null;
    return $until;
}

function is_user_banned($pdo, $community_id, $user_id) {
    if ($community_id <= 0 || !table_exists($pdo, 'community_bans')) return false;
    $cols = get_table_columns($pdo, 'community_bans');
    if (in_array('until_at', $cols, true)) {
        $q = $pdo->prepare("SELECT 1 FROM community_bans WHERE community_id = ? AND user_id = ? AND (until_at IS NULL OR until_at > NOW()) LIMIT 1");
        $q->execute([$community_id, $user_id]);
        return (bool)$q->fetchColumn();
    }
    $q = $pdo->prepare("SELECT 1 FROM community_bans WHERE community_id = ? AND user_id = ? LIMIT 1");
    $q->execute([$community_id, $user_id]);
    return (bool)$q->fetchColumn();
}

/* ------------------ AJAX: get_user_card ------------------ */
if (isset($_GET['action']) && $_GET['action'] === 'get_user_card') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (empty($_COOKIE['auth_token'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'not_logged_in']); exit; }

        $meStmt = $pdo->prepare("SELECT id FROM users WHERE auth_token = ? LIMIT 1");
        $meStmt->execute([$_COOKIE['auth_token']]);
        $me = $meStmt->fetch(PDO::FETCH_ASSOC);
        if (!$me) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'not_logged_in']); exit; }

        $username = trim((string)($_GET['username'] ?? ''));
        if ($username === '') { echo json_encode(['ok'=>false,'error'=>'missing_username']); exit; }
        $community_id = isset($_GET['community_id']) && ctype_digit((string)$_GET['community_id']) ? (int)$_GET['community_id'] : null;

        $userQ = $pdo->prepare("
            SELECT u.id, u.username, u.avatar, u.bio, u.timeout_until,
                   u.role_id,
                   r.name AS global_role, r.color AS role_color, r.badge AS role_badge
            FROM users u
            LEFT JOIN roles r ON r.id = u.role_id
            WHERE u.username = ? LIMIT 1
        ");
        $userQ->execute([$username]);
        $user = $userQ->fetch(PDO::FETCH_ASSOC);
        if (!$user) { echo json_encode(['ok'=>false,'error'=>'not_found']); exit; }

        $local_roles = [];
        if ($community_id) {
            if (table_exists($pdo, 'community_member_roles')) {
                $qr = $pdo->prepare("SELECT cr.id, cr.name, cr.color, cr.badge, cr.priority FROM community_member_roles mr JOIN community_roles cr ON cr.id = mr.role_id WHERE mr.community_id = ? AND mr.user_id = ?");
                $qr->execute([$community_id, (int)$user['id']]);
                $local_roles = $qr->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $qr2 = $pdo->prepare("SELECT cr.id, cr.name, cr.color, cr.badge, cr.priority FROM community_members cm LEFT JOIN community_roles cr ON cr.id = cm.role_id WHERE cm.community_id = ? AND cm.user_id = ? LIMIT 1");
                $qr2->execute([$community_id, (int)$user['id']]);
                $tmp = $qr2->fetch(PDO::FETCH_ASSOC);
                if ($tmp && !empty($tmp['id'])) $local_roles = [$tmp];
            }
            usort($local_roles, function($a,$b){
                $pa = isset($a['priority']) ? (int)$a['priority'] : 0;
                $pb = isset($b['priority']) ? (int)$b['priority'] : 0;
                if ($pa === $pb) return strcmp($a['name'] ?? '', $b['name'] ?? '');
                return $pb - $pa;
            });
        }

        $outUser = [
            'id' => (int)$user['id'],
            'username' => $user['username'],
            'avatar' => $user['avatar'] ?? null,
            'bio' => $user['bio'] ?? null,
            'global_role' => $user['global_role'] ?? null,
            'role_color' => $user['role_color'] ?? null,
            'role_badge' => $user['role_badge'] ?? null,
            'timeout_until' => $user['timeout_until'] ?? null
        ];

        echo json_encode(['ok'=>true,'user'=>$outUser,'local_roles'=>$local_roles]);
        exit;
    } catch (Exception $e) {
        mod_debug_log("[get_user_card] " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>'server','msg'=>$e->getMessage()]);
        exit;
    }
}

/* ------------------ AJAX: list_roles ------------------ */
if (isset($_GET['action']) && $_GET['action'] === 'list_roles') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (empty($_COOKIE['auth_token'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'not_logged_in']); exit; }

        $community_id = isset($_GET['community_id']) && ctype_digit((string)$_GET['community_id']) ? (int)$_GET['community_id'] : null;
        if (!$community_id) { echo json_encode(['ok'=>false,'error'=>'missing_community']); exit; }

        $cols = "id, community_id, name, badge, color, is_admin, can_delete_messages, can_timeout, can_ban, can_edit_channels, can_view_locked, priority";
        if (roleColumnExists($pdo,'can_assign_roles')) $cols .= ", can_assign_roles";
        $qr = $pdo->prepare("SELECT $cols FROM community_roles WHERE community_id = ? ORDER BY priority DESC, id ASC");
        $qr->execute([$community_id]);
        $roles = $qr->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok'=>true,'roles'=>$roles]);
        exit;
    } catch (Exception $e) {
        mod_debug_log("[list_roles] " . $e->getMessage());
        http_response_code(500); echo json_encode(['ok'=>false,'error'=>'server','msg'=>$e->getMessage()]); exit;
    }
}

/* ------------------ AJAX: get_my_mod_info ------------------ */
if (isset($_GET['action']) && $_GET['action'] === 'get_my_mod_info') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (empty($_COOKIE['auth_token'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'not_logged_in']); exit; }
        $community_id = isset($_GET['community_id']) && ctype_digit((string)$_GET['community_id']) ? (int)$_GET['community_id'] : null;

        $meStmt = $pdo->prepare("SELECT id, username FROM users WHERE auth_token = ? LIMIT 1");
        $meStmt->execute([$_COOKIE['auth_token']]);
        $me = $meStmt->fetch(PDO::FETCH_ASSOC);
        if (!$me) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'not_logged_in']); exit; }
        $me_id = (int)$me['id'];

        $actorMaxPriority = 0;
        $permissions = ['is_owner'=>false, 'is_admin'=>false, 'can_ban'=>false, 'can_timeout'=>false, 'can_assign_roles'=>false];
        if ($community_id) {
            if (table_exists($pdo, 'community_member_roles')) {
                $q = $pdo->prepare("SELECT cr.priority, cr.is_admin, cr.can_ban, cr.can_timeout" . (roleColumnExists($pdo,'can_assign_roles') ? ", cr.can_assign_roles" : "") . " FROM community_member_roles mr JOIN community_roles cr ON cr.id = mr.role_id WHERE mr.community_id = ? AND mr.user_id = ?");
                $q->execute([$community_id, $me_id]);
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $rr) {
                    $actorMaxPriority = max($actorMaxPriority, (int)($rr['priority'] ?? 0));
                    if (!empty($rr['is_admin'])) $permissions['is_admin'] = true;
                    if (!empty($rr['can_ban'])) $permissions['can_ban'] = true;
                    if (!empty($rr['can_timeout'])) $permissions['can_timeout'] = true;
                    if (roleColumnExists($pdo,'can_assign_roles') && !empty($rr['can_assign_roles'])) $permissions['can_assign_roles'] = true;
                }
            } else {
                $q2 = $pdo->prepare("SELECT cm.role_id, cr.priority, cr.is_admin, cr.can_ban, cr.can_timeout" . (roleColumnExists($pdo,'can_assign_roles') ? ", cr.can_assign_roles" : "") . " FROM community_members cm LEFT JOIN community_roles cr ON cr.id = cm.role_id WHERE cm.community_id = ? AND cm.user_id = ? LIMIT 1");
                $q2->execute([$community_id, $me_id]);
                $r2 = $q2->fetch(PDO::FETCH_ASSOC);
                if ($r2) {
                    $actorMaxPriority = (int)($r2['priority'] ?? 0);
                    if (!empty($r2['is_admin'])) $permissions['is_admin'] = true;
                    if (!empty($r2['can_ban'])) $permissions['can_ban'] = true;
                    if (!empty($r2['can_timeout'])) $permissions['can_timeout'] = true;
                    if (roleColumnExists($pdo,'can_assign_roles') && !empty($r2['can_assign_roles'])) $permissions['can_assign_roles'] = true;
                }
            }
            $oq = $pdo->prepare("SELECT owner_id FROM communities WHERE id = ? LIMIT 1");
            $oq->execute([$community_id]); $oc = $oq->fetch(PDO::FETCH_ASSOC);
            if ($oc && (int)($oc['owner_id'] ?? 0) === $me_id) $permissions['is_owner'] = true;
        }
        echo json_encode(['ok'=>true,'actorMaxPriority'=>$actorMaxPriority,'permissions'=>$permissions]);
        exit;
    } catch (Exception $e) {
        mod_debug_log("[get_my_mod_info] " . $e->getMessage());
        http_response_code(500); echo json_encode(['ok'=>false,'error'=>'server','msg'=>$e->getMessage()]); exit;
    }
}

/* --- MODERATION BLOCK START --- */
/* ---------------- POST: moderation endpoint ---------------- */
if (isset($_GET['action']) && $_GET['action'] === 'moderate') {
    header('Content-Type: application/json; charset=utf-8');

    // parse request
    $data = (array) getRequestData();
    $debugFlag = (isset($_GET['debug']) && $_GET['debug'] === '1') || (isset($data['debug']) && ($data['debug'] === '1' || $data['debug'] === 1 || $data['debug'] === true));

    if (empty($_COOKIE['auth_token'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'not_logged_in']); exit; }
    // ensure we have a mode from any source
    $mode = $data['mode'] ?? ($_POST['mode'] ?? ($_GET['mode'] ?? null));
    if (!$mode) {
        $raw = @file_get_contents('php://input');
        mod_debug_log("[moderate] missing mode; rawInput=" . substr($raw ?? '',0,2000) . " ; parsed=" . substr(json_encode($data),0,2000));
        http_response_code(400);
        $r = ['ok'=>false,'error'=>'missing_mode'];
        if ($debugFlag) $r['_dbg'] = ['raw' => substr($raw ?? '',0,2000), 'parsed' => array_slice($data,0,40)];
        echo json_encode($r);
        exit;
    }

    try {
        $meStmt = $pdo->prepare("SELECT id, username FROM users WHERE auth_token = ? LIMIT 1");
        $meStmt->execute([$_COOKIE['auth_token']]);
        $me = $meStmt->fetch(PDO::FETCH_ASSOC);
        if (!$me) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'not_logged_in']); exit; }
        $me_id = (int)$me['id'];

        $community_id = (isset($data['community_id']) && ctype_digit((string)$data['community_id'])) ? (int)$data['community_id'] : null;
        $target_user_id = (isset($data['target_user_id']) && ctype_digit((string)$data['target_user_id'])) ? (int)$data['target_user_id'] : null;

        // minimal table checks
        if ($community_id && !table_exists($pdo,'communities')) {
            http_response_code(500);
            echo json_encode(['ok'=>false,'error'=>'server_missing_table','msg'=>'communities table required']); exit;
        }
        if (!table_exists($pdo,'users')) {
            http_response_code(500);
            echo json_encode(['ok'=>false,'error'=>'server_missing_table','msg'=>'users table required']); exit;
        }

        // determine if actor is community owner/admin/global staff
        $hasCommunityAdmin = false;
        if ($community_id) {
            $q = $pdo->prepare("SELECT owner_id FROM communities WHERE id = ? LIMIT 1");
            $q->execute([$community_id]);
            $crow = $q->fetch(PDO::FETCH_ASSOC);
            if ($crow && (int)($crow['owner_id'] ?? 0) === $me_id) $hasCommunityAdmin = true;

            if (!$hasCommunityAdmin) {
                if (table_exists($pdo,'community_member_roles')) {
                    $qa = $pdo->prepare("SELECT 1 FROM community_member_roles mr JOIN community_roles cr ON cr.id = mr.role_id WHERE mr.community_id = ? AND mr.user_id = ? AND (cr.is_admin = 1 OR cr.is_owner = 1) LIMIT 1");
                    $qa->execute([$community_id, $me_id]);
                    if ($qa->fetchColumn()) $hasCommunityAdmin = true;
                } else {
                    if (table_exists($pdo,'community_members') && table_exists($pdo,'community_roles')) {
                        $qa2 = $pdo->prepare("SELECT cr.is_admin FROM community_members cm LEFT JOIN community_roles cr ON cr.id = cm.role_id WHERE cm.community_id = ? AND cm.user_id = ? LIMIT 1");
                        $qa2->execute([$community_id, $me_id]);
                        if ($qa2->fetchColumn()) $hasCommunityAdmin = true;
                    }
                }
            }
        } else {
            if (table_exists($pdo,'roles')) {
                $staffQ = $pdo->prepare("SELECT r.name FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ? LIMIT 1");
                $staffQ->execute([$me_id]);
                $st = strtolower((string)$staffQ->fetchColumn());
                if (strpos($st,'admin') !== false || strpos($st,'moderator') !== false) $hasCommunityAdmin = true;
            }
        }

        // gather actor local roles to compute priority & permission set
        $actorMaxPriority = 0;
        $actorPermissions = [];
        if ($community_id) {
            if (table_exists($pdo,'community_member_roles')) {
                $q = $pdo->prepare("SELECT cr.* FROM community_member_roles mr JOIN community_roles cr ON cr.id = mr.role_id WHERE mr.community_id = ? AND mr.user_id = ?");
                $q->execute([$community_id, $me_id]);
                $rows = $q->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $p = isset($r['priority']) ? (int)$r['priority'] : 0;
                    $actorMaxPriority = max($actorMaxPriority, $p);
                    foreach (['can_ban','can_timeout','can_assign_roles','is_admin','can_delete_messages','can_edit_channels','can_view_locked'] as $c) {
                        if (!isset($actorPermissions[$c])) $actorPermissions[$c] = (!empty($r[$c]) ? 1 : 0);
                    }
                }
            } else {
                if (table_exists($pdo,'community_members') && table_exists($pdo,'community_roles')) {
                    $q2 = $pdo->prepare("SELECT cr.* FROM community_members cm LEFT JOIN community_roles cr ON cr.id = cm.role_id WHERE cm.community_id = ? AND cm.user_id = ? LIMIT 1");
                    $q2->execute([$community_id, $me_id]);
                    $r = $q2->fetch(PDO::FETCH_ASSOC);
                    if ($r) {
                        $actorMaxPriority = isset($r['priority']) ? (int)$r['priority'] : 0;
                        foreach (['can_ban','can_timeout','can_assign_roles','is_admin','can_delete_messages','can_edit_channels','can_view_locked'] as $c) {
                            $actorPermissions[$c] = (!empty($r[$c]) ? 1 : 0);
                        }
                    }
                }
            }
        }
        if ($hasCommunityAdmin) {
            foreach (['can_ban','can_timeout','can_assign_roles','is_admin','can_delete_messages','can_edit_channels','can_view_locked'] as $c) {
                $actorPermissions[$c] = 1;
            }
        }

        // validate target exists early if provided
        if ($target_user_id) {
            $tu = $pdo->prepare("SELECT id, username FROM users WHERE id = ? LIMIT 1");
            $tu->execute([$target_user_id]);
            $targetUserRow = $tu->fetch(PDO::FETCH_ASSOC);
            if (!$targetUserRow) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'invalid_target']); exit; }
        }

        // perform requested action
        $now = date('Y-m-d H:i:s');
        $mode = trim((string)$mode);

        switch ($mode) {
            case 'timeout':
                if (empty($actorPermissions['can_timeout'])) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'no_permission']); exit; }
                if (!$target_user_id) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing_target']); exit; }

                // compute target priority
                $targetMax = 0;
                if ($community_id && table_exists($pdo,'community_roles')) {
                    if (table_exists($pdo,'community_member_roles')) {
                        $q = $pdo->prepare("SELECT cr.priority FROM community_member_roles mr JOIN community_roles cr ON cr.id = mr.role_id WHERE mr.community_id = ? AND mr.user_id = ?");
                        $q->execute([$community_id, $target_user_id]);
                        $arr = $q->fetchAll(PDO::FETCH_COLUMN);
                        foreach ($arr as $v) $targetMax = max($targetMax, (int)$v);
                    } else if (table_exists($pdo,'community_members')) {
                        $q2 = $pdo->prepare("SELECT cr.priority FROM community_members cm LEFT JOIN community_roles cr ON cr.id = cm.role_id WHERE cm.community_id = ? AND cm.user_id = ? LIMIT 1");
                        $q2->execute([$community_id, $target_user_id]);
                        $v = $q2->fetchColumn();
                        $targetMax = $v ? (int)$v : 0;
                    }
                }
                // owner?
                $isOwner = false;
                if ($community_id) {
                    $oq = $pdo->prepare("SELECT owner_id FROM communities WHERE id = ? LIMIT 1");
                    $oq->execute([$community_id]); $oc = $oq->fetch(PDO::FETCH_ASSOC);
                    if ($oc && (int)($oc['owner_id'] ?? 0) === $target_user_id) $isOwner = true;
                }
                if (!$isOwner && ($targetMax >= $actorMaxPriority) && !$hasCommunityAdmin) {
                    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'target_higher_or_equal_priority']); exit;
                }

                // compute until
                if (!empty($data['until_at'])) $until_at = date('Y-m-d H:i:s', strtotime($data['until_at']));
                else if (!empty($data['duration_seconds']) && ctype_digit((string)$data['duration_seconds'])) $until_at = date('Y-m-d H:i:s', time() + (int)$data['duration_seconds']);
                else $until_at = date('Y-m-d H:i:s', time() + 600);

                $reason = trim((string)($data['reason'] ?? ''));

                try {
                    $pdo->beginTransaction();
                    if (table_exists($pdo,'community_timeouts')) {
                        // insert local timeout
                        $ins = $pdo->prepare("INSERT INTO community_timeouts (community_id, user_id, actor_user, reason, until_at, created_at) VALUES (?, ?, ?, ?, ?, ?)");
                        $ins->execute([$community_id, $target_user_id, $me_id, $reason, $until_at, $now]);
                    } else {
                        // fallback: update users.timeout_until
                        $up = $pdo->prepare("UPDATE users SET timeout_until = ? WHERE id = ?");
                        $up->execute([$until_at, $target_user_id]);
                    }
                    if (table_exists($pdo,'community_audit')) {
                        $ai = $pdo->prepare("INSERT INTO community_audit (community_id, action, actor_user, target_user, target_message, reason, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $ai->execute([$community_id, 'timeout', $me_id, $target_user_id, null, $reason, $now]);
                    }
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    mod_debug_log("[moderate:timeout] " . $e->getMessage());
                    http_response_code(500);
                    $resp = ['ok'=>false,'error'=>'server','msg'=>'timeout_failed'];
                    if ($debugFlag) $resp['_ex'] = $e->getMessage();
                    echo json_encode($resp); exit;
                }

                echo json_encode(['ok'=>true,'until'=>$until_at]); exit;

            case 'untimeout':
                if (empty($actorPermissions['can_timeout'])) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'no_permission']); exit; }
                if (!$target_user_id) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing_target']); exit; }

                // priority checks (same as timeout)
                $targetMax = 0;
                if ($community_id && table_exists($pdo,'community_roles')) {
                    if (table_exists($pdo,'community_member_roles')) {
                        $q = $pdo->prepare("SELECT cr.priority FROM community_member_roles mr JOIN community_roles cr ON cr.id = mr.role_id WHERE mr.community_id = ? AND mr.user_id = ?");
                        $q->execute([$community_id, $target_user_id]);
                        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $v) $targetMax = max($targetMax,(int)$v);
                    } else if (table_exists($pdo,'community_members')) {
                        $q2 = $pdo->prepare("SELECT cr.priority FROM community_members cm LEFT JOIN community_roles cr ON cr.id = cm.role_id WHERE cm.community_id = ? AND cm.user_id = ? LIMIT 1");
                        $q2->execute([$community_id, $target_user_id]);
                        $v = $q2->fetchColumn();
                        $targetMax = $v ? (int)$v : 0;
                    }
                }
                $isOwner = false;
                if ($community_id) {
                    $oq = $pdo->prepare("SELECT owner_id FROM communities WHERE id = ? LIMIT 1");
                    $oq->execute([$community_id]); $oc = $oq->fetch(PDO::FETCH_ASSOC);
                    if ($oc && (int)($oc['owner_id'] ?? 0) === $target_user_id) $isOwner = true;
                }
                if (!$isOwner && ($targetMax >= $actorMaxPriority) && !$hasCommunityAdmin) {
                    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'target_higher_or_equal_priority']); exit;
                }

                try {
                    $pdo->beginTransaction();
                    if (table_exists($pdo,'community_timeouts')) {
                        $del = $pdo->prepare("DELETE FROM community_timeouts WHERE community_id = ? AND user_id = ?");
                        $del->execute([$community_id, $target_user_id]);
                    } else {
                        $up = $pdo->prepare("UPDATE users SET timeout_until = NULL WHERE id = ?");
                        $up->execute([$target_user_id]);
                    }
                    if (table_exists($pdo,'community_audit')) {
                        $ai = $pdo->prepare("INSERT INTO community_audit (community_id, action, actor_user, target_user, target_message, reason, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $ai->execute([$community_id, 'untimeout', $me_id, $target_user_id, null, 'untimeout by moderator', $now]);
                    }
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    mod_debug_log("[moderate:untimeout] " . $e->getMessage());
                    http_response_code(500);
                    $resp = ['ok'=>false,'error'=>'server','msg'=>'untimeout_failed'];
                    if ($debugFlag) $resp['_ex'] = $e->getMessage();
                    echo json_encode($resp); exit;
                }
                echo json_encode(['ok'=>true]); exit;

            case 'ban':
                if (empty($actorPermissions['can_ban'])) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'no_permission']); exit; }
                if (!$target_user_id) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing_target']); exit; }

                // priority check
                $targetMax = 0;
                if ($community_id && table_exists($pdo,'community_roles')) {
                    if (table_exists($pdo,'community_member_roles')) {
                        $q = $pdo->prepare("SELECT cr.priority FROM community_member_roles mr JOIN community_roles cr ON cr.id = mr.role_id WHERE mr.community_id = ? AND mr.user_id = ?");
                        $q->execute([$community_id, $target_user_id]);
                        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $v) $targetMax = max($targetMax,(int)$v);
                    } else if (table_exists($pdo,'community_members')) {
                        $q2 = $pdo->prepare("SELECT cr.priority FROM community_members cm LEFT JOIN community_roles cr ON cr.id = cm.role_id WHERE cm.community_id = ? AND cm.user_id = ? LIMIT 1");
                        $q2->execute([$community_id, $target_user_id]);
                        $v = $q2->fetchColumn(); $targetMax = $v ? (int)$v : 0;
                    }
                }
                $isOwner = false;
                if ($community_id) {
                    $oq = $pdo->prepare("SELECT owner_id FROM communities WHERE id = ? LIMIT 1");
                    $oq->execute([$community_id]); $oc = $oq->fetch(PDO::FETCH_ASSOC);
                    if ($oc && (int)($oc['owner_id'] ?? 0) === $target_user_id) $isOwner = true;
                }
                if (!$isOwner && ($targetMax >= $actorMaxPriority) && !$hasCommunityAdmin) {
                    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'target_higher_or_equal_priority']); exit;
                }

                if (!table_exists($pdo,'community_bans')) {
                    http_response_code(500);
                    echo json_encode(['ok'=>false,'error'=>'server_missing_table','msg'=>'community_bans table not found']); exit;
                }

                try {
                    $reason = trim((string)($data['reason'] ?? ''));
                    $until_at = null;
                    if (!empty($data['permanent']) && ($data['permanent'] === '1' || $data['permanent'] === 1 || $data['permanent'] === true)) $until_at = null;
                    else if (!empty($data['until_at'])) $until_at = date('Y-m-d H:i:s', strtotime($data['until_at']));
                    else if (!empty($data['duration_seconds']) && ctype_digit((string)$data['duration_seconds'])) $until_at = date('Y-m-d H:i:s', time() + (int)$data['duration_seconds']);
                    else $until_at = null;

                    $uQ = $pdo->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
                    $uQ->execute([$target_user_id]); $target_un = $uQ->fetchColumn();

                    $bi = $pdo->prepare("INSERT INTO community_bans (community_id, user_id, username, banned_by, reason, until_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $bi->execute([$community_id, $target_user_id, $target_un, $me_id, $reason, $until_at, $now]);

                    if (table_exists($pdo,'community_audit')) {
                        $ai = $pdo->prepare("INSERT INTO community_audit (community_id, action, actor_user, target_user, target_message, reason, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $ai->execute([$community_id, 'ban', $me_id, $target_user_id, null, $reason, $now]);
                    }
                } catch (Throwable $e) {
                    mod_debug_log("[moderate:ban] " . $e->getMessage());
                    http_response_code(500);
                    $resp = ['ok'=>false,'error'=>'server','msg'=>'ban_failed'];
                    if ($debugFlag) $resp['_ex'] = $e->getMessage();
                    echo json_encode($resp); exit;
                }

                echo json_encode(['ok'=>true,'until'=>$until_at]); exit;

			case 'assign_roles':
    			// Allow assignment/removal for:
    			//  - users with explicit can_assign_roles permission, OR
    			//  - community admins/owners, OR
    			//  - users with a non-zero actorMaxPriority (they hold local roles) — but only limited to roles with priority < actorMaxPriority
    			$allowAssign = !empty($actorPermissions['can_assign_roles']) || $hasCommunityAdmin || ($actorMaxPriority > 0);
    			if (!$allowAssign) {
       			 http_response_code(403);
        			echo json_encode(['ok'=>false,'error'=>'no_permission']);
        			exit;
    			}
    			if (!$target_user_id) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing_target']); exit; }

    			// Normalize incoming role_ids (array or comma-string)
    			$role_ids = [];
    			if (isset($data['role_ids']) && is_array($data['role_ids'])) $role_ids = $data['role_ids'];
    			elseif (!empty($data['role_ids']) && is_string($data['role_ids'])) $role_ids = array_filter(explode(',', $data['role_ids']));
    			$role_ids = array_values(array_filter(array_map(function($v){ return ctype_digit((string)$v) ? (int)$v : null; }, $role_ids)));

    			// Helper: fetch current roles assigned to target (id => priority)
    			$targetCurrentRoles = [];
    			if (table_exists($pdo,'community_member_roles')) {
        			$q = $pdo->prepare("SELECT cr.id AS role_id, cr.priority FROM community_member_roles mr JOIN community_roles cr ON cr.id = mr.role_id WHERE mr.community_id = ? AND mr.user_id = ?");
        			$q->execute([$community_id, $target_user_id]);
        				foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $rr) {
            			$targetCurrentRoles[(int)$rr['role_id']] = isset($rr['priority']) ? (int)$rr['priority'] : 0;
        			}
    			} elseif (table_exists($pdo,'community_members')) {
        			$q2 = $pdo->prepare("SELECT cr.id AS role_id, cr.priority FROM community_members cm LEFT JOIN community_roles cr ON cr.id = cm.role_id WHERE cm.community_id = ? AND cm.user_id = ? LIMIT 1");
        			$q2->execute([$community_id, $target_user_id]);
        			$r2 = $q2->fetch(PDO::FETCH_ASSOC);
        			if ($r2 && !empty($r2['role_id'])) $targetCurrentRoles[(int)$r2['role_id']] = isset($r2['priority']) ? (int)$r2['priority'] : 0;
    			}

    			// If clearing roles (no role_ids provided) -> ensure actor is allowed to remove each existing role
    			if (count($role_ids) === 0) {
       			 // Removal check: each existing target role must have priority < actorMaxPriority OR actor must be community admin
        			foreach ($targetCurrentRoles as $rid => $rprio) {
           			 if (!$hasCommunityAdmin && ($rprio >= $actorMaxPriority) && $actorMaxPriority > 0) {
                			http_response_code(403);
                			echo json_encode(['ok'=>false,'error'=>'cannot_remove_role','msg'=>'Cannot remove role with equal or higher priority than yours']);
                			exit;
            			}
            			if (!$hasCommunityAdmin && $actorMaxPriority <= 0) {
                			// actor has no local priority and is not admin -> cannot remove roles
                			http_response_code(403);
                			echo json_encode(['ok'=>false,'error'=>'no_permission_remove_roles']);
                			exit;
            			}
        			}

        			// Perform removal
        			try {
            			if (table_exists($pdo,'community_member_roles')) {
                			$del = $pdo->prepare("DELETE FROM community_member_roles WHERE community_id = ? AND user_id = ?");
                			$del->execute([$community_id, $target_user_id]);
            			} elseif (table_exists($pdo,'community_members')) {
                			$up = $pdo->prepare("UPDATE community_members SET role_id = NULL WHERE community_id = ? AND user_id = ?");
                			$up->execute([$community_id, $target_user_id]);
            			}
            			if (table_exists($pdo,'community_audit')) {
                			$ai = $pdo->prepare("INSERT INTO community_audit (community_id, action, actor_user, target_user, target_message, reason, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
                			$ai->execute([$community_id, 'assign_roles', $me_id, $target_user_id, json_encode([]), 'cleared roles', $now]);
            			}
           			 echo json_encode(['ok'=>true,'assigned'=>[]]); exit;
        			} catch (Throwable $e) {
            			mod_debug_log("[assign_roles:clear] " . $e->getMessage());
            			http_response_code(500);
            			echo json_encode(['ok'=>false,'error'=>'server','msg'=>'assign_roles_failed']);
            			exit;
        			}
    			}

    			// Validate that target role ids exist in this community and fetch their priorities
    			if (!table_exists($pdo,'community_roles')) {
        			http_response_code(500);
        			echo json_encode(['ok'=>false,'error'=>'server_missing_table','msg'=>'community_roles table required']);
        			exit;
    			}
    			$placeholders = implode(',', array_fill(0, count($role_ids), '?'));
    			$qr = $pdo->prepare("SELECT id, priority FROM community_roles WHERE community_id = ? AND id IN ($placeholders)");
    			$params = array_merge([$community_id], $role_ids);
    			$qr->execute($params);
    			$allowedRoles = $qr->fetchAll(PDO::FETCH_ASSOC);

    			// Ensure all requested role_ids actually belong to this community
    			$allowedIds = array_map(function($r){ return (int)$r['id']; }, $allowedRoles);
    			foreach ($role_ids as $rid) {
        			if (!in_array($rid, $allowedIds, true)) {
            			http_response_code(400);
            			echo json_encode(['ok'=>false,'error'=>'invalid_role','role_id'=>$rid]);
            			exit;
        			}
    			}

    			// Check assignment priority: each new role must have priority < actorMaxPriority (strictly) unless actor is community admin
    			foreach ($allowedRoles as $r) {
        			$rprio = isset($r['priority']) ? (int)$r['priority'] : 0;
        			if (!$hasCommunityAdmin && $actorMaxPriority > 0 && $rprio >= $actorMaxPriority) {
            			http_response_code(403);
            			echo json_encode(['ok'=>false,'error'=>'role_too_high','msg'=>'Cannot assign role with equal or higher priority than yours']);
            			exit;
        			}
        			if (!$hasCommunityAdmin && $actorMaxPriority <= 0) {
            			// actor has no assign capability (no local priority and not admin)
            			http_response_code(403);
            			echo json_encode(['ok'=>false,'error'=>'no_permission_assign_roles']);
            			exit;
        			}
    			}			

    			// Check removals: For any roles currently on the target that will be removed (i.e. exist on target but not included in $role_ids),
    			// ensure they are strictly lower priority than actor (or actor is admin)
    			$toRemove = [];
    			foreach ($targetCurrentRoles as $rid => $rprio) {
        			if (!in_array($rid, $role_ids, true)) $toRemove[$rid] = $rprio;
   			 }
    			foreach ($toRemove as $rid => $rprio) {
        			if (!$hasCommunityAdmin && ($rprio >= $actorMaxPriority) && $actorMaxPriority > 0) {
            			http_response_code(403);
            			echo json_encode(['ok'=>false,'error'=>'cannot_remove_role','msg'=>'Cannot remove role with equal or higher priority than yours']);
          				exit;
        			}			
        			if (!$hasCommunityAdmin && $actorMaxPriority <= 0) {
            			http_response_code(403);
            			echo json_encode(['ok'=>false,'error'=>'no_permission_remove_roles']);
            			exit;
        			}
   			 }

    			// All checks passed; perform assignment (replace roles for target)
    			try {
        			if (table_exists($pdo,'community_member_roles')) {
            			$pdo->beginTransaction();
            			// delete existing
            			$del = $pdo->prepare("DELETE FROM community_member_roles WHERE community_id = ? AND user_id = ?");
            			$del->execute([$community_id, $target_user_id]);
            			// insert new
            			$ins = $pdo->prepare("INSERT INTO community_member_roles (community_id, user_id, role_id) VALUES (?, ?, ?)");
            			foreach ($allowedRoles as $r) {
                			$ins->execute([$community_id, $target_user_id, $r['id']]);
            			}
            			if (table_exists($pdo,'community_audit')) {
                			$ai = $pdo->prepare("INSERT INTO community_audit (community_id, action, actor_user, target_user, target_message, reason, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
                			$ai->execute([$community_id, 'assign_roles', $me_id, $target_user_id, json_encode(array_column($allowedRoles,'id')), 'assigned roles', $now]);
            			}
            			$pdo->commit();
            				echo json_encode(['ok'=>true,'assigned'=>array_column($allowedRoles,'id')]);
            			exit;
        			} else {
            			// fallback single-role model: pick the highest-priority requested role (but must satisfy priority check already done)
            			usort($allowedRoles, function($a,$b){ return (($b['priority'] ?? 0) - ($a['priority'] ?? 0)); });
            			if (count($allowedRoles)) {
                			$newRoleId = $allowedRoles[0]['id'];
                			$up = $pdo->prepare("UPDATE community_members SET role_id = ? WHERE community_id = ? AND user_id = ?");
                			$up->execute([$newRoleId, $community_id, $target_user_id]);
                			if (table_exists($pdo,'community_audit')) {
                    			$ai = $pdo->prepare("INSERT INTO community_audit (community_id, action, actor_user, target_user, target_message, reason, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    			$ai->execute([$community_id, 'assign_roles', $me_id, $target_user_id, $newRoleId, 'assigned single role fallback', $now]);
               			 }
                			echo json_encode(['ok'=>true,'assigned'=>[$newRoleId]]);
                			exit;
            			} else {
               			 echo json_encode(['ok'=>false,'error'=>'no_allowed_roles']);
               			 exit;
           			 }
        			}
    			} catch (Throwable $e) {
        			if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        			mod_debug_log("[moderate:assign_roles] " . $e->getMessage());
        			http_response_code(500);
        			$resp = ['ok'=>false,'error'=>'server','msg'=>'assign_roles_failed'];
        			if ($debugFlag) $resp['_ex'] = $e->getMessage();
       			 echo json_encode($resp); exit;
    			}
    			break;

            case 'create_role':
                if (empty($actorPermissions['is_admin'])) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'no_permission']); exit; }
                if (!table_exists($pdo,'community_roles')) {
                    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'server_missing_table','msg'=>'community_roles table required']); exit;
                }
                $name = trim((string)($data['name'] ?? ''));
                if ($name === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing_name']); exit; }
                $badge = trim((string)($data['badge'] ?? ''));
                $color = trim((string)($data['color'] ?? '#cccccc'));
                $priority = isset($data['priority']) && (string)$data['priority'] !== '' && is_numeric($data['priority']) ? (int)$data['priority'] : 0;

                $cols = "community_id, name, badge, color, admin, created_at, is_admin, can_delete_messages, can_timeout, can_ban, can_edit_channels, can_view_locked, priority";
                $vals = "?, ?, ?, ?, 0, ?, 0, 0, 0, 0, 0, 0, ?";
                if (roleColumnExists($pdo,'can_assign_roles')) { $cols .= ", can_assign_roles"; $vals .= ", 0"; }
                $sql = "INSERT INTO community_roles ($cols) VALUES ($vals)";
                try {
                    $ins = $pdo->prepare($sql);
                    $params = [$community_id, $name, $badge, $color, $now, $priority];
                    $ins->execute($params);
                    $newId = $pdo->lastInsertId();
                    if (table_exists($pdo,'community_audit')) {
                        $ai = $pdo->prepare("INSERT INTO community_audit (community_id, action, actor_user, target_user, target_message, reason, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $ai->execute([$community_id, 'create_role', $me_id, null, null, 'created role ' . $name, $now]);
                    }
                    echo json_encode(['ok'=>true,'role_id'=>$newId]); exit;
                } catch (Throwable $e) {
                    mod_debug_log("[moderate:create_role] " . $e->getMessage());
                    http_response_code(500);
                    $resp = ['ok'=>false,'error'=>'server','msg'=>'create_role_failed'];
                    if ($debugFlag) $resp['_ex'] = $e->getMessage();
                    echo json_encode($resp); exit;
                }
                break;

            default:
                http_response_code(400);
                echo json_encode(['ok'=>false,'error'=>'unknown_mode','mode'=>$mode]);
                exit;
        }

    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        mod_debug_log("[moderate] top-level exception: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        http_response_code(500);
        $resp = ['ok'=>false,'error'=>'server','msg'=>'exception'];
        if ($debugFlag) { $resp['_ex'] = $e->getMessage(); $resp['_trace'] = array_slice(explode("\n",$e->getTraceAsString()),0,10); }
        echo json_encode($resp);
        exit;
    }
}
/* --- MODERATION BLOCK END --- */

/* ------------------ private_interface.php compatibility block ------------------ */
/* This replicates the behavior expected by the client side long-poll endpoint used in previous versions.
   If you have a separate private_interface.php running, you can remove or keep this for compatibility. */

if (isset($_GET['ajax']) && $_GET['ajax'] === 'room_data') {
    header('Content-Type: application/json; charset=utf-8');
    // expects ?room={id|code}&since={id}
    // We reuse some logic from your earlier private_interface.php while keeping this file standalone for convenience.
    try {
        if (empty($_COOKIE['auth_token'])) { echo json_encode(['error'=>'not logged in']); exit; }
        $roomParam = $_GET['room'] ?? '';
        if ($roomParam === '') { echo json_encode(['error'=>'invalid room']); exit; }

        // find room row (detect code or id)
        if (ctype_digit((string)$roomParam)) {
            $stmt = $pdo->prepare("SELECT id, code, name, community_id FROM private_rooms WHERE id = ?");
            $stmt->execute([(int)$roomParam]);
        } else {
            $stmt = $pdo->prepare("SELECT id, code, name, community_id FROM private_rooms WHERE code = ?");
            $stmt->execute([$roomParam]);
        }
        $roomRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$roomRow) { echo json_encode(['error'=>'invalid room']); exit; }
        $room_id = (int)$roomRow['id'];
        $room_code = $roomRow['code'];
        $room_name = $roomRow['name'] ?? 'Private Room';
        $room_community_id = isset($roomRow['community_id']) ? (int)$roomRow['community_id'] : null;

        // get current user & role
        $stmt = $pdo->prepare("
            SELECT u.id, u.username, u.avatar, u.role_id, u.timeout_until,
                   r.name AS role, r.color AS role_color, r.badge AS role_badge
            FROM users u
            LEFT JOIN roles r ON r.id = u.role_id
            WHERE u.auth_token = ?
        ");
        $stmt->execute([$_COOKIE['auth_token']]);
        $current_user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$current_user) { echo json_encode(['error'=>'bad login']); exit; }
        $uid = (int)$current_user['id'];

        // detect whether private_messages uses room_id or room_code
        $colCheck = $pdo->query("SHOW COLUMNS FROM private_messages")->fetchAll(PDO::FETCH_COLUMN);
        $has_room_id = in_array("room_id", $colCheck, true);
        $has_room_code = in_array("room_code", $colCheck, true);
        if (!$has_room_id && !$has_room_code) { echo json_encode(['error'=>'server misconfiguration']); exit; }
        $pm_column = $has_room_id ? "room_id" : "room_code";
        $pm_value  = $has_room_id ? $room_id : $room_code;

        // ensure columns exist
        $pm_cols = get_table_columns($pdo, 'private_messages');
        if (!in_array('edited_at', $pm_cols)) {
            try { $pdo->exec("ALTER TABLE private_messages ADD COLUMN edited_at DATETIME NULL DEFAULT NULL"); } catch (Exception $e) {}
            $pm_cols = get_table_columns($pdo, 'private_messages');
        }
        if (!in_array('deleted_at', $pm_cols)) {
            try { $pdo->exec("ALTER TABLE private_messages ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL"); } catch (Exception $e) {}
            $pm_cols = get_table_columns($pdo, 'private_messages');
        }
        if (!in_array('deleted_by', $pm_cols)) {
            try { $pdo->exec("ALTER TABLE private_messages ADD COLUMN deleted_by INT NULL DEFAULT NULL"); } catch (Exception $e) {}
            $pm_cols = get_table_columns($pdo, 'private_messages');
        }
        if (!in_array('reply_to', $pm_cols)) {
            try { $pdo->exec("ALTER TABLE private_messages ADD COLUMN reply_to INT NULL DEFAULT NULL"); } catch (Exception $e) {}
            $pm_cols = get_table_columns($pdo, 'private_messages');
        }

        // fetch messages
        $since = isset($_GET["since"]) ? (int)$_GET["since"] : 0;
        $longpoll = $since > 0;
        $timeout_seconds = 25;
        $interval_usec = 500000;
        $messages = [];

        if ($longpoll && $since > 0) {
            $start = time();
            while ((time() - $start) < $timeout_seconds) {
                $sql = "
                    SELECT m.id, m.username, m.user_id, m.message, m.created_at, m.edited_at, m.deleted_at, m.deleted_by, m.reply_to,
                           rm.username AS reply_to_username, rm.message AS reply_to_message,
                           u.avatar, r.name AS role, r.color AS role_color, r.badge AS role_badge
                    FROM private_messages m
                    LEFT JOIN private_messages rm ON rm.id = m.reply_to
                    LEFT JOIN users u ON u.id = m.user_id
                    LEFT JOIN roles r ON r.id = u.role_id
                    WHERE m." . $pm_column . " = :roomval AND m.id > :since
                    ORDER BY m.id ASC
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([":roomval" => $pm_value, ":since" => $since]);
                $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($messages)) break;
                usleep($interval_usec);
            }
        } else {
            $sql = "
                SELECT m.id, m.username, m.user_id, m.message, m.created_at, m.edited_at, m.deleted_at, m.deleted_by, m.reply_to,
                       rm.username AS reply_to_username, rm.message AS reply_to_message,
                       u.avatar, r.name AS role, r.color AS role_color, r.badge AS role_badge
                FROM private_messages m
                LEFT JOIN private_messages rm ON rm.id = m.reply_to
                LEFT JOIN users u ON u.id = m.user_id
                LEFT JOIN roles r ON r.id = u.role_id
                WHERE m." . $pm_column . " = :roomval
                ORDER BY m.id DESC
                LIMIT 50
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([":roomval" => $pm_value]);
            $messages = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
        }

        // typing list
        $typing = [];
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
            if (!empty($m['reply_to_message'])) $m['reply_to_excerpt'] = mb_substr($m['reply_to_message'], 0, 240);
            else $m['reply_to_excerpt'] = null;
        }
        unset($m);

        // make user object safe
        $current_user_safe = $current_user;
        if (isset($current_user_safe['timeout_until']) && $current_user_safe['timeout_until'] !== null) {
            $ts = strtotime($current_user_safe['timeout_until']);
            if ($ts !== false) $current_user_safe['timeout_until'] = gmdate('Y-m-d\TH:i:s\Z', $ts);
        }

        echo json_encode(["user" => $current_user_safe, "messages" => $messages, "typing" => $typing]);
        exit;
    } catch (Exception $e) {
        mod_debug_log("[ajax room_data] " . $e->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "server"]);
        exit;
    }
}

/* ------------------ Main page rendering ------------------ */
/* This renders the private.php UI. It enforces community locks, bans, displays "Access denied" pages, etc.
   The client-side JS expects certain endpoints above; we keep the page simple and focused. */

$code = $_GET['code'] ?? '';
if ($code === '') { die("No private code provided."); }

try {
    $cols = $pdo->query("SHOW COLUMNS FROM private_rooms")->fetchAll(PDO::FETCH_COLUMN);
    $select = "id, code";
    $lower = array_map('strtolower', $cols);
    if (in_array('community_id', $lower, true)) $select .= ", community_id";
    if (in_array('required_role_id', $lower, true)) $select .= ", required_role_id";
    $stmt = $pdo->prepare("SELECT $select FROM private_rooms WHERE code = ? LIMIT 1");
    $stmt->execute([$code]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $room = false;
}

if (!$room) { die("Invalid private code."); }
$room_id = (int)$room['id'];
$room_code = $room['code'];
$room_community_id = isset($room['community_id']) ? (int)$room['community_id'] : null;
$room_required_role = isset($room['required_role_id']) ? (int)$room['required_role_id'] : 0;

// optional background (keeps previous behavior)
$background_url = null;
try {
    $cols = $pdo->query("SHOW COLUMNS FROM private_rooms")->fetchAll(PDO::FETCH_COLUMN);
    $candidates = ['background', 'background_url', 'background_image', 'bg'];
    $found = null;
    foreach ($candidates as $c) { if (in_array($c, $cols, true)) { $found = $c; break; } }
    if ($found) {
        $s = $pdo->prepare("SELECT `$found` AS bg FROM private_rooms WHERE id = ?");
        $s->execute([$room_id]);
        $rv = $s->fetch(PDO::FETCH_ASSOC);
        if ($rv && !empty($rv['bg'])) {
            $bg = trim($rv['bg']);
            if (stripos($bg,'http://')===0 || stripos($bg,'https://')===0) $background_url = $bg;
            else {
                $localPath = __DIR__ . '/backgrounds/' . $bg;
                if (file_exists($localPath)) $background_url = 'backgrounds/' . rawurlencode($bg);
            }
        }
    }
} catch (Exception $e) { /* ignore */ }

// auth
if (empty($_COOKIE['auth_token'])) { header("Location:index.php"); exit; }
$stmt = $pdo->prepare("SELECT id, username, avatar, timeout_until FROM users WHERE auth_token = ? LIMIT 1");
$stmt->execute([$_COOKIE['auth_token']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { header("Location:index.php"); exit; }
$username = $user['username'];
$avatar = $user['avatar'] ?? null;
$my_id = (int)$user['id'];
$my_timeout_until = $user['timeout_until'] ?? null;

// community ban check (block viewing)
if ($room_community_id) {
    try {
        if (table_exists($pdo,'community_bans')) {
            $qb = $pdo->prepare("SELECT id, community_id, user_id, username, banned_by, reason, until_at, created_at FROM community_bans WHERE community_id = ? AND (user_id = ? OR username = ?) ORDER BY id DESC LIMIT 1");
            $qb->execute([$room_community_id, $my_id, $username]);
            $banRow = $qb->fetch(PDO::FETCH_ASSOC);
            if ($banRow) {
                $until = $banRow['until_at'];
                $isBanned = false;
                if ($until === null || $until === '') $isBanned = true;
                else if (strtotime($until) > time()) $isBanned = true;
                if ($isBanned) {
                    http_response_code(403);
                    $reasonEsc = htmlspecialchars($banRow['reason'] ?? 'No reason provided', ENT_QUOTES);
                    $untilShow = $banRow['until_at'] ? htmlspecialchars($banRow['until_at'], ENT_QUOTES) : 'Permanent';
                    echo "<!doctype html><html><head><meta charset='utf-8'><title>Banned</title></head><body style='background:#0b0b0b;color:#fff;font-family:Inter,Arial,Helvetica,sans-serif;padding:36px'><h2>Access denied — You are banned</h2><p>Reason: {$reasonEsc}</p><p>Banned until: {$untilShow}</p><p>If you believe this is an error contact the moderators.</p><p><a href='room.php' style='color:#7db3ff'>Return to Rooms</a></p></body></html>";
                    exit;
                }
            }
        }
    } catch (Exception $e) {
        // ignore ban check errors
    }
}

// permission enforcement for community-locked rooms
if ($room_community_id && $room_required_role) {
    $has_access = false;

    // owner
    try {
        $q = $pdo->prepare("SELECT owner_id FROM communities WHERE id = ? LIMIT 1");
        $q->execute([$room_community_id]);
        $crow = $q->fetch(PDO::FETCH_ASSOC);
        if ($crow && (int)($crow['owner_id'] ?? 0) === $my_id) $has_access = true;
    } catch (Exception $e) {}

    // community admin
    if (!$has_access) {
        try {
            if (table_exists($pdo,'community_member_roles')) {
                $qa = $pdo->prepare("SELECT 1 FROM community_member_roles mr JOIN community_roles cr ON cr.id = mr.role_id WHERE mr.community_id = ? AND mr.user_id = ? AND (cr.is_admin = 1 OR cr.admin = 1) LIMIT 1");
                $qa->execute([$room_community_id, $my_id]);
                if ($qa->fetchColumn()) $has_access = true;
            } else {
                $qa2 = $pdo->prepare("SELECT cr.is_admin, cr.admin FROM community_members cm LEFT JOIN community_roles cr ON cr.id = cm.role_id WHERE cm.community_id = ? AND cm.user_id = ? LIMIT 1");
                $qa2->execute([$room_community_id, $my_id]);
                $v = $qa2->fetch(PDO::FETCH_ASSOC);
                if ($v && (!empty($v['is_admin']) || !empty($v['admin']))) $has_access = true;
            }
        } catch (Exception $e) {}
    }

    // explicit role or can_view_locked
    if (!$has_access) {
        try {
            if (table_exists($pdo,'community_member_roles')) {
                $qb = $pdo->prepare("SELECT cr.id, cr.can_view_locked FROM community_member_roles mr JOIN community_roles cr ON cr.id = mr.role_id WHERE mr.community_id = ? AND mr.user_id = ?");
                $qb->execute([$room_community_id, $my_id]);
                $foundRoles = $qb->fetchAll(PDO::FETCH_ASSOC);
                foreach ($foundRoles as $fr) {
                    if (!empty($fr['can_view_locked'])) { $has_access = true; break; }
                    if ((int)$fr['id'] === (int)$room_required_role) { $has_access = true; break; }
                }
            } else {
                $qb2 = $pdo->prepare("SELECT cm.role_id, cr.can_view_locked FROM community_members cm LEFT JOIN community_roles cr ON cr.id = cm.role_id WHERE cm.community_id = ? AND cm.user_id = ? LIMIT 1");
                $qb2->execute([$room_community_id, $my_id]);
                $r2 = $qb2->fetch(PDO::FETCH_ASSOC);
                if ($r2) {
                    if (!empty($r2['can_view_locked'])) $has_access = true;
                    elseif ((int)($r2['role_id'] ?? 0) === (int)$room_required_role) $has_access = true;
                }
            }
        } catch (Exception $e) {
            $has_access = false;
        }
    }

    if (!$has_access) {
        http_response_code(403);
        echo "<!doctype html><html><head><meta charset='utf-8'><title>Access denied</title></head><body style='background:#0b0b0b;color:#fff;font-family:Inter,Arial,Helvetica,sans-serif;padding:36px'><h2>Access restricted</h2><p>You do not have the role required to view this channel.</p><p><a href='room.php' style='color:#7db3ff'>Return to Rooms</a></p></body></html>";
        exit;
    }
}
/* ---------------- Render page HTML ---------------- */
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Private Room — <?= htmlspecialchars($room_code, ENT_QUOTES) ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="icon" href="root/favicon.ico" id="favicon">
<style>
:root{--accent:#5865F2; --bg:#0e0e0e; --muted:#9aa3b8; --card:#111; --muted2:#cbd5e1}
html,body{height:100%;margin:0;background:var(--bg);color:#fff;font-family:Inter,Arial,Helvetica,sans-serif}
<?php if ($background_url): ?>
body{background-image:url('<?= htmlspecialchars($background_url, ENT_QUOTES) ?>');background-size:cover;background-position:center;background-repeat:no-repeat}
.bg-overlay{position:fixed;left:0;top:0;right:0;bottom:0;background:rgba(0,0,0,0.45);pointer-events:none;z-index:0}
<?php endif; ?>
/* top */
#top{position:relative;z-index:10;background:#181818;border-bottom:1px solid #222;padding:10px 14px;display:flex;justify-content:space-between;align-items:center}
.topLeft{display:flex;align-items:center;gap:12px}
.topBtns{display:flex;gap:8px;align-items:center}
.topBtn{background:var(--accent);color:#fff;border:0;border-radius:6px;padding:7px 10px;cursor:pointer}
.avatarWrap{position:relative;width:42px;height:42px;flex:0 0 42px}
.avatarImg img{width:42px;height:42px;border-radius:50%;object-fit:cover;border:2px solid #111;display:block}
.avatarPlaceholder{width:42px;height:42px;border-radius:50%;background:#5865F2;display:flex;align-items:center;justify-content:center;font-weight:700;border:2px solid #111}
.roleBadge{position:absolute;right:-8px;top:-8px;min-width:18px;height:18px;line-height:18px;border-radius:10px;padding:0 6px;font-size:12px;color:#000;box-shadow:0 1px 0 rgba(0,0,0,0.3)}
/* chat */
#chat{position:relative;z-index:5;height:calc(100vh - 190px);overflow:auto;padding:30px 20px}
.msgRow{display:flex;gap:10px;margin-bottom:8px;align-items:flex-start;position:relative}
.msgBubble{background:rgba(30,30,30,0.95);padding:10px;border-radius:10px;max-width:70%;box-sizing:border-box;word-break:break-word}
.username{font-weight:bold;margin-bottom:6px}
.userLink, .avatarLink { color: inherit; text-decoration: none; } /* prevent purple underlines */
.msgTime{font-size:11px;color:#888;min-width:25px;text-align:right;align-self:flex-end}
.msgActions{position:absolute;right:6px;top:6px;display:flex;gap:6px;opacity:0;transition:opacity .12s}
.msgRow:hover .msgActions{opacity:1}
.actionBtn{background:rgba(0,0,0,.35);border:0;color:#fff;padding:4px 6px;border-radius:6px;cursor:pointer;font-size:12px}
.actionBtn:hover{background:rgba(255,255,255,.06)}
.big-emoji{font-size:50px;vertical-align:middle;margin:0 2px}
/* typing / status */
#typing{position:fixed;bottom:60px;left:0;right:0;padding:6px 20px;font-style:italic;color:#ccc;z-index:6}
#statusBar{position:fixed;bottom:116px;left:12px;background:rgba(0,0,0,.6);padding:6px 10px;border-radius:6px;font-size:13px;display:none;z-index:6}
/* input */
#input{position:fixed;bottom:0;left:0;right:0;background:#111;padding:10px;display:flex;gap:8px;align-items:center;z-index:10}
#msg{flex:1;background:#222;color:white;border:0;padding:10px;border-radius:8px;font-size:15px;height:18px}
#emojiBtn{background:var(--accent);border:0;border-radius:6px;padding:6px 10px;font-size:18px;color:white;cursor:pointer}
#imageBtn{background:#2baf7f;border:0;border-radius:6px;padding:6px 10px;font-size:16px;color:white;cursor:pointer}
.sendBtn{background:var(--accent);border:0;border-radius:10px;color:white;padding:10px 18px;cursor:pointer}
#emojiPicker{display:none;position:absolute;bottom:62px;left:12px;width:360px;max-height:260px;overflow:auto;background:#222;border:1px solid #444;border-radius:8px;padding:8px;z-index:20}
#emojiPicker span{cursor:pointer;padding:6px;font-size:26px;display:inline-block}

/* notification bell (room-style additions) */
.bell1 { position:relative; cursor:pointer; padding:6px 8px; border-radius:8px; background:rgba(255,255,255,0.02); }
.badge { position:absolute; top:-6px; right:-6px; background:#ff4d4f; color:white; border-radius:12px; padding:2px 6px; font-size:12px; min-width:24px; text-align:center; }
.notifBox{position:absolute; right:12px; top:64px; background:#0b1114; border-radius:8px; padding:12px; min-width:360px; max-width:520px; box-shadow:0 8px 24px rgba(0,0,0,.6); display:none; z-index:1000}
.notifRow{padding:8px 6px;border-bottom:1px solid rgba(255,255,255,0.03);font-size:13px}
.notifRow:last-child{border-bottom:0}    

/* grouped notification styles */
.notifGroup{display:flex;gap:10px;align-items:center;padding:8px;border-radius:8px;cursor:pointer;transition:background .12s;position:relative}
.notifGroup.unread{background:rgba(255,255,255,0.02)}
.notifGroup.modmail{border-left:4px solid var(--accent); background: linear-gradient(90deg, rgba(43,111,178,0.06), transparent)}
.notifGroup .avatar{width:44px;height:44px;border-radius:8px;background:#222;display:flex;align-items:center;justify-content:center;color:#fff;flex:0 0 44px;overflow:hidden}
.notifGroup .meta{flex:1;min-width:0}
.notifGroup .meta .title{font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.notifGroup .meta .msg{color:var(--muted);font-size:13px;margin-top:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.notifGroup .time{font-size:12px;color:#9aa1b0;min-width:80px;text-align:right}
.notifCount{background:#ff4d4f;color:#fff;padding:4px 8px;border-radius:999px;font-weight:700;font-size:12px;min-width:36px;text-align:center}
.unreadDot{width:10px;height:10px;border-radius:50%;background:#ff4d4f;margin-left:8px;box-shadow:0 0 0 3px rgba(255,77,79,0.06)}   
    
.markAll{display:flex;justify-content:flex-end;margin-bottom:8px}    
    
/* hover card */
#hoverCard{position:absolute;z-index:500;min-width:220px;background:var(--card);border:1px solid #222;padding:12px;border-radius:8px;color:#fff;box-shadow:0 6px 24px rgba(0,0,0,.6);display:none}
#hoverCard .hc-row{display:flex;gap:12px;align-items:flex-start}
#hoverCard .hc-avatar{width:64px;height:64px;border-radius:12px;overflow:hidden}
#hoverCard .hc-avatar img{width:64px;height:64px;object-fit:cover;border-radius:12px}
#hoverCard .hc-name{font-weight:700;font-size:18px}
#hoverCard .hc-global{display:flex;gap:8px;align-items:center;margin-top:6px}
#hoverCard .hc-role{font-size:13px;color:var(--muted);background:rgba(255,255,255,0.03);padding:4px 8px;border-radius:8px}
#hoverCard .hc-bio{margin-top:8px;color:var(--muted2);font-size:13px;line-height:1.35;max-height:9.5em;overflow:auto;white-space:pre-wrap}
#hoverCard .hc-local-roles{margin-top:10px;display:flex;flex-wrap:wrap;gap:6px}
#hoverCard .hc-local-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;width:100%;margin-top:10px}
#hoverCard .hc-local-grid .pill{padding:6px 8px;border-radius:8px;font-size:12px;color:#000;display:inline-flex;align-items:center;gap:6px}
#hoverCard a.profileLink{display:inline-block;margin-top:8px;color:var(--accent);text-decoration:none;font-size:13px}

/* reply snippet */
.replySnippet{border-left:3px solid rgba(255,255,255,0.06);padding:6px;margin-bottom:6px;color:#ccc;font-size:13px;border-radius:6px;background:rgba(0,0,0,0.04);overflow:hidden;max-width:100%}
.replySnippet .r-user{font-weight:700;margin-bottom:3px;color:#fff}

/* image modal */
#imageModal{position:fixed;left:0;top:0;right:0;bottom:0;display:none;background:rgba(0,0,0,0.85);align-items:center;justify-content:center;z-index:2000}
#imageModal img{max-width:94%;max-height:94%;border-radius:10px;box-shadow:0 12px 40px rgba(0,0,0,0.6)}
.chatImage { display:block; width:100%; max-width:420px; max-height:420px; border-radius:12px; object-fit:cover; margin-top:8px; border:1px solid rgba(255,255,255,0.03) }
.chatImageSmall { max-width:100%; max-height:420px; }

/* reply preview */
#replyPreview{position:fixed;bottom:90px;left:12px;right:12px;background:#151515;border:1px solid #2a2a2a;padding:8px;border-radius:8px;color:#ddd;display:none;align-items:center;gap:10px;z-index:30}
#replyPreview .rp-title{font-weight:700;margin-right:8px}
#replyPreview .rp-cancel{margin-left:auto;background:transparent;border:0;color:#f66;cursor:pointer}    
    
/* context menu */
#ctxMenu{position:fixed;display:none;z-index:5000;background:#0f1113;border:1px solid #222;border-radius:8px;padding:6px;box-shadow:0 8px 40px rgba(0,0,0,0.6)}
#ctxMenu .ctxItem{padding:8px;border-radius:6px;cursor:pointer}
#ctxMenu .ctxItem:hover{background:rgba(255,255,255,0.03)}

/* overlays */
.overlay{position:fixed;left:0;top:0;right:0;bottom:0;display:none;align-items:center;justify-content:center;background:rgba(0,0,0,0.5);z-index:4000}
.modal{width:520px;max-width:94%;background:#0f1113;border-radius:10px;padding:14px;border:1px solid #222;color:#fff}
.grid-roles{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.role-card{padding:6px;border-radius:8px;background:#0b0c0d;display:flex;align-items:center;gap:8px}
.small{font-size:12px;color:var(--muted)}
.center{display:flex;align-items:center;justify-content:center}
.hidden{display:none}
</style>
</head>
<body>
<?php if ($background_url): ?><div class="bg-overlay"></div><?php endif; ?>

<audio id="bell" preload="auto"><source src="root/bell.mp3" type="audio/mpeg"></audio>
<audio id="bell2" preload="auto"><source src="root/bell_2.mp3" type="audio/mpeg"></audio>

<div id="top">
  <div class="topLeft">
    <?php if ($avatar): ?>
      <div class="avatarWrap"><a class="avatarLink" data-username="<?= htmlspecialchars($username,ENT_QUOTES) ?>" data-avatar="<?= htmlspecialchars($avatar,ENT_QUOTES) ?>" href="user.php?username=<?= rawurlencode($username) ?>"><div class="avatarImg"><img id="myAvatarImg" src="avatars/<?= htmlspecialchars($avatar,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>" alt=""></div></a></div>
    <?php else: ?>
      <div class="avatarWrap"><a class="avatarLink" data-username="<?= htmlspecialchars($username,ENT_QUOTES) ?>" href="user.php?username=<?= rawurlencode($username) ?>"><div class="avatarPlaceholder" id="myAvatarPlaceholder"><?= strtoupper($username[0]) ?></div></a></div>
    <?php endif; ?>
    <div><div style="font-weight:700"><?= htmlspecialchars($username,ENT_QUOTES) ?></div><div style="font-size:12px;color:#bbb">⋆˚꩜｡ Room: <strong><?= htmlspecialchars($room_code,ENT_QUOTES) ?></strong></div></div>
  </div>

  <div class="topBtns" id="topBtnsBlock">
    <button class="topBtn" onclick="location.href='private_voice.php?room=<?= rawurlencode($room_code) ?>'">🎤 Voice Chat</button>

    <!-- notification bell -->
    <div id="notifBell" class="bell1" title="Notifications" style="margin-left:6px">
      🔔
      <span id="notifBadge" class="badge" style="display:none">0</span>
    </div>

    <form action="upload_avatar.php" method="POST" enctype="multipart/form-data" style="display:inline">
      <input type="file" name="avatar" accept="image/*" required>
      <button class="topBtn" type="submit">Avatar</button>
    </form>
  </div>
</div>
<div id="typing"></div>
<div id="statusBar" role="status" aria-live="polite"></div>

<div id="replyPreview" role="status" aria-live="polite" style="display:none">
  <span class="rp-title">Replying to <strong id="rpUser"></strong>:</span>
  <span id="rpText" style="opacity:.9"></span>
  <button class="rp-cancel" id="rpCancel" title="Cancel reply">✖</button>
</div>

<div id="imageModal" onclick="document.getElementById('imageModal').style.display='none'">
  <img id="imageModalImg" src="">
</div>

<div id="input">
  <input id="imageInput" type="file" accept="image/png,image/jpeg,image/webp,image/gif" style="display:none" />
  <input id="msg" placeholder="Send your message here..." autocomplete="off" aria-label="Message" maxlength="750" />
  <button id="emojiBtn">😝</button>
  <button id="imageBtn" title="Upload image">📷</button>
  <button class="sendBtn" id="sendBtn">Send</button>
  <div id="charCount">0/750</div>
</div>

<div id="emojiPicker" aria-hidden="true"></div>
<div id="hoverCard" role="tooltip" aria-hidden="true"></div>

<!-- context menu -->
<div id="ctxMenu" style="display:none"><div id="ctxMenuItems"></div></div>

<!-- moderation overlay -->
<div id="modOverlay" class="overlay" style="display:none">
  <div class="modal" id="modModal">
    <h3 id="modTitle">Moderation</h3>
    <div id="modBody"></div>
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px">
      <button id="modCancel" style="background:transparent;border:1px solid #222;padding:8px;border-radius:8px;color:#fff">Cancel</button>
      <button id="modConfirm" style="background:var(--accent);border:0;padding:8px 10px;border-radius:8px;color:#fff">Confirm</button>
    </div>
  </div>
</div>

<!-- roles overlay -->
<div id="rolesOverlay" class="overlay" style="display:none">
  <div class="modal">
    <h3 style="margin:0 0 8px 0">Manage Roles for <span id="rolesTargetName">...</span></h3>
    <div id="rolesListArea" style="max-height:320px;overflow:auto;padding:6px 0">Loading roles…</div>
    <div style="margin-top:10px;border-top:1px solid #222;padding-top:10px">
      <h4 style="margin:0 0 6px 0;font-size:14px">Create new role</h4>
      <div style="display:flex;gap:8px">
        <input id="newRoleName" placeholder="Role name" style="flex:1;padding:8px;border-radius:6px;background:#0b0c0d;border:1px solid #222;color:#fff" />
        <input id="newRoleBadge" placeholder="Badge" style="width:90px;padding:8px;border-radius:6px;background:#0b0c0d;border:1px solid #222;color:#fff" />
      </div>
      <div style="display:flex;gap:8px;margin-top:8px;align-items:center">
        <label style="color:var(--muted);font-size:12px">Priority (higher = more powerful)</label>
        <input id="newRolePriority" type="number" value="0" style="width:90px;padding:8px;border-radius:6px;background:#0b0c0d;border:1px solid #222;color:#fff" />
        <input id="newRoleColor" type="color" value="#aabbcc" style="width:56px;height:34px;border-radius:6px;border:1px solid #222" />
        <button id="createRoleBtn" style="background:var(--accent);border:0;padding:8px 12px;border-radius:8px;color:#fff;cursor:pointer">Create role</button>
      </div>
    </div>
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px">
      <button id="rolesCancelBtn" style="background:transparent;border:1px solid #222;padding:8px 10px;border-radius:8px;color:#fff">Cancel</button>
      <button id="rolesSaveBtn" style="background:var(--accent);border:0;padding:8px 10px;border-radius:8px;color:#fff">Save</button>
    </div>
  </div>
</div>
<!-- notification dropdown -->
<div id="notifDropdown" class="notifBox" aria-hidden="true" style="display:none">
  <div class="markAll"><button id="markAllBtn" style="background:transparent;border:0;color:var(--accent);cursor:pointer">Mark all read</button></div>
  <div id="notifList" style="max-height:420px;overflow:auto">
    <div style="padding:12px;color:var(--muted)">Loading…</div>
  </div>
</div>

<div id="chat" aria-live="polite"></div>
<div id="typing"></div>
<div id="statusBar"></div>

<div id="replyPreview" role="status" aria-live="polite">
  <span class="rp-title">Replying to <strong id="rpUser"></strong>:</span>
  <span id="rpText" style="opacity:.9"></span>
  <button class="rp-cancel" id="rpCancel" title="Cancel reply">✖</button>
</div>

<!-- image modal -->
<div id="imageModal" onclick="document.getElementById('imageModal').style.display='none'">
  <img id="imageModalImg" src="">
</div>

<script>
/* ---------- constants & helpers ---------- */
const ROOM_CODE = <?= json_encode($room_code) ?>;
const COMMUNITY_ID = <?= $room_community_id ? json_encode($room_community_id) : 'null' ?>;
const NOTIF_ENDPOINT = 'notifications.php';
const MAX_MESSAGE_LENGTH = 750;
const MAX_IMAGE_UPLOAD_BYTES = 6 * 1024 * 1024;
const EMOJIS = "😡,😭,🙄,😒,😝,😖,☹️,😢,😀,😁,😂,🤣,😃,😄,😅,😆,😉,😊,🙂,🙃,😍,😘,😗,😙,😚,😋,😜,🤪,🤨,🧐,🤓,😎,🤩,🥳,🤗,🤔,🤭,🤫,🤥,😶,😐,😑,😬,🙄,😯,😦,😧,😮,😲,😴,🤤,😪,😵,🤐,🥴,🤢,🤮,🤧,😷,🤒,🤕,😇,🥰,💩,👻,💀,🤖,🎃,😺,😸,😹,😻,😼,🙈,🙉,🙊,👍,👎,👏,🙌,🙏,💪,🤝,👑,⭐,✨,🔥,💥,💫,🌟,💯,✔️,❌,❤️,💛,💚,💙,💜,🖤,💔,💕,☀️,🌤️,⛅,🌧️,🌩️,🌨️,🌈,🍕,🍔,🍟,🍣,☕,🍺,🍷,🎂,🍩,🍪,⚽,🏀,🏈,🎮,🎧,🎵,🎶,🎸,🎹".split(',');  
    
function escapeHtml(s){ if (s==null) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }
function parseServerTS(ts){ if (!ts) return null; if (/[zZ]|[+\-]\d{2}:\d{2}$/.test(ts)) return new Date(ts); return new Date(ts + 'Z'); }
function relativeTimeObj(ts){ const d = parseServerTS(ts); if (!d || isNaN(d)) return null; const now = Date.now(); const diff = Math.round((now - d.getTime())/1000); let txt=''; if (diff < 5) txt='just now'; else if (diff < 60) txt = diff + 's'; else if (diff < 3600) txt = Math.round(diff/60) + 'm'; else if (diff < 86400) txt = Math.round(diff/3600) + 'h'; else if (diff < 7*86400) txt = Math.round(diff/86400) + 'd'; else txt = d.toLocaleDateString(); return { label: txt, title: d.toLocaleString() }; }
function escapeForRegex(s){ return s.replace(/[.*+?^${}()|[\]\\]/g,'\\$&'); }
const emojiRegex = new RegExp('(' + EMOJIS.map(escapeForRegex).join('|') + ')', 'g');
function highlightEmojisEscaped(escapedText){ return escapedText.replace(emojiRegex, '<span class="big-emoji">$1</span>'); }
function showAccessError(msg){
  const safe = msg === 'banned' ? 'You are banned from this community.' : (msg === 'denied' ? 'You do not have permission to view this channel.' : 'Access denied.');
  document.body.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100vh;background:#0e0e0e;color:#fff;font-family:Inter,Arial,Helvetica,sans-serif"><div style="background:#141414;border:1px solid #222;border-radius:12px;padding:24px;max-width:520px;text-align:center"><h1 style="margin:0 0 10px;font-size:24px">Access Restricted</h1><p style="margin:0;color:#bfbfbf">' + safe + '</p></div></div>';
  running = false;
}
    
document.addEventListener('pointerdown', ()=> audioUnlocked = true, {once:true});

/* ---------- state ---------- */
let lastId = 0;
let lastUsernameInDOM = null;
let currentUser = null;
let audioUnlocked = false;
let running = true;
let pollInFlight = false;
let replyingTo = null;
const bell = document.getElementById('bell');
const bell2 = document.getElementById('bell2');  

/* ---------- char count ---------- */
const charCountEl = document.getElementById('charCount');
const msgInputEl = document.getElementById('msg');
function updateCharCount(){ const len = Array.from(msgInputEl.value).length; charCountEl.textContent = len + '/' + MAX_MESSAGE_LENGTH; if (len > MAX_MESSAGE_LENGTH) charCountEl.style.color = '#ff6b6b'; else if (len > (MAX_MESSAGE_LENGTH*0.8)) charCountEl.style.color = '#ffd166'; else charCountEl.style.color = '#bdbdbd'; }
msgInputEl.addEventListener('input', updateCharCount);

    
(function hideTopButtonsIfEmbedded(){
  try {
    if (window.self !== window.top) {
      const topBtns = document.getElementById('topBtnsBlock');
      if (topBtns) topBtns.style.display = 'none';
      const topLeft = document.querySelector('.topLeft');
      if (topLeft) {
        const small = topLeft.querySelector('div div[style*="font-size:12px"]');
        if (small) small.style.display = 'none';
      }
    }
  } catch (e) {}
})();   
   
/* ---------- hover card ---------- */
const hoverCard = document.getElementById('hoverCard');
let hoverTimeout = null;
   
function renderHoverCardFromData(data, x, y) {
  if (!data || !data.username) return hideHoverCard();
  hoverCard.innerHTML = '';
  const row = document.createElement('div'); row.className='hc-row';
  const av = document.createElement('div'); av.className='hc-avatar';
  if (data.avatar) {
    const img = document.createElement('img'); img.src = 'avatars/' + encodeURIComponent(data.avatar); img.alt = data.username;
    av.appendChild(img);
  } else {
    const ph = document.createElement('div');
    ph.style.width='64px'; ph.style.height='64px'; ph.style.display='flex'; ph.style.alignItems='center'; ph.style.justifyContent='center';
    ph.style.background='#5865F2'; ph.style.borderRadius='12px'; ph.style.color='#fff'; ph.style.fontWeight='700';
    ph.textContent = (data.username||'?')[0].toUpperCase();
    av.appendChild(ph);
  }
  row.appendChild(av);

  const info = document.createElement('div'); info.style.minWidth = '160px';
  const name = document.createElement('div'); name.className='hc-name'; name.textContent = data.username || '';
  info.appendChild(name);

  // show global role below the username (full name + optional badge)
  const globalHolder = document.createElement('div'); globalHolder.className='hc-global';
  const roleSpan = document.createElement('div'); roleSpan.className='hc-role';
  roleSpan.textContent = (data.role_badge ? (data.role_badge + ' ') : '') + (data.global_role || 'Member');
  globalHolder.appendChild(roleSpan);

  info.appendChild(globalHolder);

  const bioDiv = document.createElement('div'); bioDiv.className='hc-bio';
  bioDiv.textContent = ''; // populated via fetch
  info.appendChild(bioDiv);

  const grid = document.createElement('div'); grid.className = 'hc-local-grid';
  info.appendChild(grid);

  const link = document.createElement('a'); link.className='profileLink'; link.href='user.php?username=' + encodeURIComponent(data.username||''); link.textContent='View profile';
  info.appendChild(link);

  row.appendChild(info);
  hoverCard.appendChild(row);

  // positioning
  const pad = 12, cardW = 340, cardH = 220;
  let left = x + 12, top = y + 18;
  if (left + cardW + pad > window.innerWidth) left = window.innerWidth - cardW - pad;
  if (top + cardH + pad > window.innerHeight) top = window.innerHeight - cardH - pad;
  hoverCard.style.left = left + 'px'; hoverCard.style.top = top + 'px'; hoverCard.style.display='block'; hoverCard.setAttribute('aria-hidden','false');

  if (hoverTimeout) clearTimeout(hoverTimeout);
  hoverTimeout = setTimeout(async () => {
    try {
      const params = new URLSearchParams();
      params.set('action','get_user_card');
      params.set('username', data.username);
      if (typeof COMMUNITY_ID === 'number' && COMMUNITY_ID) params.set('community_id', String(COMMUNITY_ID));
      const res = await fetch('private.php?' + params.toString(), { credentials: 'same-origin' });
      if (!res.ok) return;
      const j = await res.json();
      if (!j || !j.ok || !j.user) return;
      // show bio (preserve whitespace). Limit to 7 newline breaks visually due to CSS max-height.
      bioDiv.textContent = j.user.bio || '';
      grid.innerHTML = '';
      if (Array.isArray(j.local_roles) && j.local_roles.length) {
        j.local_roles.forEach(r => {
          const pill = document.createElement('div');
          pill.className = 'pill';
          pill.textContent = (r.badge ? (r.badge + ' ') : '') + (r.name || '');
          pill.style.background = r.color || '#ddd';
          pill.title = r.name;
          grid.appendChild(pill);
        });
      }
    } catch (e) { /* ignore */ }
  }, 120);
}
function hideHoverCard(){ if (hoverTimeout) clearTimeout(hoverTimeout); hoverCard.style.display='none'; hoverCard.setAttribute('aria-hidden','true'); }

/* ---------- moderator helper (client) ---------- */
function isModeratorForClient(user) {
  if (!user) return false;
  if (typeof user.is_admin !== 'undefined' && user.is_admin) return true;
  const roleName = (user.role || '').toLowerCase();
  if (typeof user.role_id === 'number' && user.role_id < 3) return true;
  const checks = ['site administrator','site moderator','owner','admin','moderator'];
  return checks.some(c => roleName.includes(c));
}

/* ---------- message rendering ---------- */
const IMAGE_MD_RE = /!\[image\]\((\/images\/[^\s)]+)\)/g;
function renderMessageContentRaw(msg) {
  if (!msg) return '';
  let escaped = escapeHtml(msg);
  escaped = escaped.replace(IMAGE_MD_RE, function(full, path) {
    if (!path || !path.startsWith('/images/')) return full;
    const encodedPath = '/images/' + encodeURIComponent(path.slice('/images/'.length));
    return '<img src="' + encodedPath + '" class="chatImage chatImageSmall" loading="lazy" onclick="openImageModal(this.src)" />';
  });
  escaped = highlightEmojisEscaped(escaped);
  return escaped;
}

// helper: detect image markdown pattern: ![image](/images/filename.ext)

function renderMessageContentRaw(msg) {
  if (!msg) return '';
  let escaped = escapeHtml(msg);

  // simple formatting tags at start: <b> <i> <u> <big> <small>
  // if message starts with "<b>" we'll strip it and wrap content in <strong>
  let prefixHtmlOpen = '', prefixHtmlClose = '';
  const lower = (msg||'').toLowerCase();
  if (lower.startsWith('<b>')) { prefixHtmlOpen = '<strong>'; prefixHtmlClose = '</strong>'; escaped = escapeHtml(msg.slice(3)); }
  else if (lower.startsWith('<i>')) { prefixHtmlOpen = '<em>'; prefixHtmlClose = '</em>'; escaped = escapeHtml(msg.slice(3)); }
  else if (lower.startsWith('<u>')) { prefixHtmlOpen = '<u>'; prefixHtmlClose = '</u>'; escaped = escapeHtml(msg.slice(3)); }
  else if (lower.startsWith('<big>')) { prefixHtmlOpen = '<span style="font-size:2em">'; prefixHtmlClose = '</span>'; escaped = escapeHtml(msg.slice(5)); }
  else if (lower.startsWith('<small>')) { prefixHtmlOpen = '<span style="font-size:0.65em">'; prefixHtmlClose = '</span>'; escaped = escapeHtml(msg.slice(7)); }

  escaped = escaped.replace(IMAGE_MD_RE, function(full, path) {
    if (!path || !path.startsWith('/images/')) return full;
    const encoded = '/images/' + encodeURIComponent(path.slice('/images/'.length));
    return '<img src="' + encoded + '" class="chatImage chatImageSmall" loading="lazy" onclick="openImageModal(this.src)" />';
  });
  escaped = highlightEmojisEscaped(escaped);
  return prefixHtmlOpen + escaped + prefixHtmlClose;
}    
    
function openImageModal(src) {
  const modal = document.getElementById('imageModal');
  const img = document.getElementById('imageModalImg');
  img.src = src;
  modal.style.display = 'flex';
}


/* ---------- append messages ---------- */
function appendMessages(messages) {
  if (!Array.isArray(messages) || messages.length === 0) return;
  lastUsernameInDOM = lastUsernameInDOM || (chat.querySelector('.msgRow:last-child') ? chat.querySelector('.msgRow:last-child').dataset.username : null);

  let appended = false;
  for (const m of messages) {
    if (!m || !m.id) continue;
    if (m.id <= lastId) continue;
    appended = true;

    const sameAsPrevious = (m.username && lastUsernameInDOM && m.username === lastUsernameInDOM);
    const role_color = m.role_color || '#9bbcff';
    const badgeText = m.role_badge || '';
    const isMine = currentUser && ((Number(currentUser.id) === Number(m.user_id)) || (currentUser.username === m.username));
    const canEdit = isMine && !m.deleted_at; // removed edit window expired - edits allowed anytime
    const isMod = isModeratorForClient(currentUser);

    const row = document.createElement('div');
    row.className = 'msgRow';
    row.dataset.id = m.id;
    row.dataset.userId = m.user_id || '';
    row.dataset.username = m.username || '';
    row.dataset.created = m.created_at || '';
    row.dataset.edited = m.edited_at || '';
    row.dataset.deleted = m.deleted_at || '';

    // left avatar
    if (!sameAsPrevious) {
      const avatarWrap = document.createElement('div'); avatarWrap.className='avatarWrap';
      const profileUrl = 'user.php?username=' + encodeURIComponent(m.username||'');
      const a = document.createElement('a'); a.className='avatarLink'; a.href = profileUrl;
      a.dataset.username = m.username || ''; a.dataset.avatar = m.avatar || ''; a.dataset.role = m.role || ''; a.dataset.roleColor = role_color; a.dataset.roleBadge = badgeText;
      if (m.avatar) a.innerHTML = `<div class="avatarImg"><img src="avatars/${escapeHtml(m.avatar)}" style="border-color:${escapeHtml(role_color)}" alt=""></div>`;
      else a.innerHTML = `<div class="avatarPlaceholder" style="border-color:${escapeHtml(role_color)}">${escapeHtml((m.username||'?')[0].toUpperCase())}</div>`;
      avatarWrap.appendChild(a);
      if (badgeText) { const b = document.createElement('div'); b.className='roleBadge'; b.style.background = role_color; b.textContent = badgeText; avatarWrap.appendChild(b); }

      // timeout/banned indicators - if user is currently timeouted or banned (server returns timeout_until and is_banned)
      const time_until = m.timeout_until || null;
      const isBanned = m.is_banned == 1 || m.is_banned === true;
      if (time_until && Date.parse(time_until) > Date.now()) {
        // show a thin red bar overlay
        const bar = document.createElement('div');
        bar.style.position = 'absolute';
        bar.style.left = '0';
        bar.style.right = '0';
        bar.style.top = '0';
        bar.style.height = '4px';
        bar.style.background = 'linear-gradient(90deg,#ff6b6b,#ff0000)';
        bar.style.borderTopLeftRadius = '6px';
        bar.style.borderTopRightRadius = '6px';
        avatarWrap.appendChild(bar);
      }
      if (isBanned) {
        // grey/black avatar - simple overlay
        const ov = document.createElement('div');
        ov.style.position='absolute'; ov.style.left='0'; ov.style.top='0'; ov.style.width='100%'; ov.style.height='100%'; ov.style.background='rgba(0,0,0,0.7)'; ov.style.borderRadius='70%';
        avatarWrap.appendChild(ov);
      }

      row.appendChild(avatarWrap);
    } else {
      const spacer = document.createElement('div'); spacer.style.width = '42px'; row.appendChild(spacer);
    }

    // bubble
    const bubble = document.createElement('div'); bubble.className='msgBubble';

    if (!sameAsPrevious && m.username) {
      const nameDiv = document.createElement('div'); nameDiv.className='username'; nameDiv.style.color = role_color;
      const nameA = document.createElement('a'); nameA.className='userLink'; nameA.href = 'user.php?username=' + encodeURIComponent(m.username||''); nameA.dataset.username = m.username || ''; nameA.dataset.avatar = m.avatar || ''; nameA.dataset.role = m.role || ''; nameA.textContent = m.username;
      nameDiv.appendChild(nameA); bubble.appendChild(nameDiv);
    }

    if (m.reply_to_username || m.reply_to_message || m.reply_to_excerpt) {
      const snippet = document.createElement('div'); snippet.className='replySnippet';
      const ruser = document.createElement('div'); ruser.className='r-user'; ruser.textContent = m.reply_to_username || '…'; snippet.appendChild(ruser);
      const rex = document.createElement('div'); const excerpt = m.reply_to_excerpt || m.reply_to_message || (m.reply_to_message ? m.reply_to_message.slice(0,120) : ''); rex.textContent = excerpt || ''; snippet.appendChild(rex);
      bubble.appendChild(snippet);
    }

    const content = document.createElement('div'); content.className='msgText';
    if (m.deleted_at) { content.style.opacity='.6'; content.style.fontStyle='italic'; content.textContent = m.message || ''; }
    else {
      content.innerHTML = renderMessageContentRaw(m.message || '');
    }
    bubble.appendChild(content);

    const actions = document.createElement('div'); actions.className='msgActions';
    if (!m.deleted_at) {
      const replyBtn = document.createElement('button'); replyBtn.className='actionBtn replyBtn'; replyBtn.dataset.id = m.id; replyBtn.dataset.user = m.username || ''; replyBtn.dataset.excerpt = (m.message || '').slice(0,140); replyBtn.textContent='Reply'; actions.appendChild(replyBtn);
    }
    if (canEdit) { const editBtn = document.createElement('button'); editBtn.className='actionBtn editBtn'; editBtn.dataset.id = m.id; editBtn.textContent='Edit'; actions.appendChild(editBtn); }
    if (isMod && !m.deleted_at) { const delBtn = document.createElement('button'); delBtn.className='actionBtn deleteBtn'; delBtn.dataset.id = m.id; delBtn.textContent='Delete'; actions.appendChild(delBtn); }
    bubble.appendChild(actions);

    row.appendChild(bubble);

    const timeDiv = document.createElement('div'); timeDiv.className='msgTime';
    const rel = relativeTimeObj(m.created_at);
    timeDiv.title = rel ? rel.title : (m.created_at || '');
    timeDiv.textContent = (rel ? rel.label : (m.created_at || '')) + (m.edited_at ? ' • edited' : '');
    row.appendChild(timeDiv);

    chat.appendChild(row);

    lastUsernameInDOM = m.username || null;
  }

  if (appended) {
    const ids = Array.from(document.querySelectorAll('.msgRow')).map(r => Number(r.dataset.id || 0));
    const max = ids.length ? Math.max(...ids) : lastId;
    lastId = Math.max(lastId, max);
    chat.scrollTop = chat.scrollHeight;
  }
}


/* ---------- delegated hover (avatar/name) ---------- *./
/* delegated hover (avatar & username) */
document.addEventListener('mouseover', (ev)=>{ const el = ev.target.closest('.userLink, .avatarLink'); if (!el) return; const data = { username: el.getAttribute('data-username') || el.dataset.username || el.textContent || '', avatar: el.getAttribute('data-avatar') || el.dataset.avatar || '', global_role: el.getAttribute('data-role') || el.dataset.role || '', role_badge: el.getAttribute('data-role-badge') || el.dataset.roleBadge || '', role_color: el.getAttribute('data-role-color') || el.dataset.roleColor || '' }; renderHoverCardFromData(data, ev.pageX, ev.pageY); });
document.addEventListener('mouseout', (ev)=>{ if (!ev.relatedTarget || !ev.relatedTarget.closest || !ev.target.closest('.userLink, .avatarLink')) hideHoverCard(); });

/* ---------- delegated clicks (reply/edit/delete) ---------- */
document.getElementById('chat').addEventListener('click', async (ev)=>{
  const btn = ev.target.closest('.replyBtn, .editBtn, .deleteBtn');
  if (!btn) return;
  const id = btn.dataset.id; if (!id) return;

  if (btn.classList.contains('replyBtn')) { replyingTo = { id: id, username: btn.dataset.user || '', excerpt: btn.dataset.excerpt || '' }; showReplyPreview(replyingTo); document.getElementById('msg').focus(); return; }

  if (btn.classList.contains('editBtn')) {
    const row = btn.closest('.msgRow'); if (!row) return; if (row.querySelector('.editArea')) return;
    const textDiv = row.querySelector('.msgText'); const orig = textDiv ? textDiv.textContent : '';
    textDiv.style.display = 'none';
    const editArea = document.createElement('div'); editArea.className='editArea';
    const ta = document.createElement('textarea'); ta.value = orig; ta.style.width='100%'; ta.style.minHeight='80px'; editArea.appendChild(ta);
    const save = document.createElement('button'); save.className='btn'; save.textContent='Save';
    const cancel = document.createElement('button'); cancel.className='btn'; cancel.textContent='Cancel';
    editArea.appendChild(save); editArea.appendChild(cancel);
    row.querySelector('.msgBubble').appendChild(editArea); ta.focus();

    let pending=false;
    save.addEventListener('click', async ()=> {
      if (pending) return;
      const newText = ta.value.trim();
      if (!newText) return alert('Message cannot be blank');
      if (Array.from(newText).length > MAX_MESSAGE_LENGTH) return alert('Message too long (' + MAX_MESSAGE_LENGTH + ' chars)');
      pending = true; save.disabled = cancel.disabled = true;
      try {
        const body = 'id=' + encodeURIComponent(id) + '&message=' + encodeURIComponent(newText);
        const resp = await fetch('private_interface.php?room=' + encodeURIComponent(ROOM_CODE) + '&mode=edit', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body, credentials:'same-origin' });
        const j = await resp.json();
        if (j && j.ok) {
          textDiv.innerHTML = renderMessageContentRaw(newText);
          textDiv.style.display = '';
          row.dataset.edited = new Date().toISOString();
          const tdiv = row.querySelector('.msgTime'); if (tdiv) tdiv.textContent = (tdiv.title || '') + ' • edited';
          editArea.remove();
          await immediatePoll();
        } else {
          alert(j.error || 'Edit failed');
          save.disabled = cancel.disabled = false;
        }
      } catch (e) { alert('Edit failed'); save.disabled = cancel.disabled = false; }
      pending = false;
    });
    cancel.addEventListener('click', ()=> { editArea.remove(); textDiv.style.display=''; });
    return;
  }

  if (btn.classList.contains('deleteBtn')) {
    if (!confirm('Delete this message?')) return;
    try {
      const body = 'id=' + encodeURIComponent(id);
      const resp = await fetch('private_interface.php?room=' + encodeURIComponent(ROOM_CODE) + '&mode=delete', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body, credentials:'same-origin' });
      const j = await resp.json();
      if (j && j.ok) {
        const row = btn.closest('.msgRow');
        if (row) {
          const content = row.querySelector('.msgText');
          if (content) { content.style.opacity='.6'; content.style.fontStyle='italic'; content.textContent = 'Message removed by a site moderator'; }
          row.dataset.deleted = new Date().toISOString();
          const actions = row.querySelector('.msgActions'); if (actions) actions.remove();
          const tdiv = row.querySelector('.msgTime'); if (tdiv) tdiv.textContent = (tdiv.title || '') + ' • deleted';
        }
      } else alert(j.error || 'Delete failed');
    } catch(e){ alert('Delete failed'); }
    return;
  }
});

/* ---------- reply preview ---------- */
const rpEl = document.getElementById('replyPreview');
const rpUser = document.getElementById('rpUser');
const rpText = document.getElementById('rpText');
const rpCancel = document.getElementById('rpCancel');
rpCancel.addEventListener('click', ()=> { clearReply(); });

function showReplyPreview(obj) { if (!obj) return clearReply(); rpEl.style.display='flex'; rpUser.textContent = obj.username || '…'; rpText.textContent = (obj.excerpt || '').slice(0,240); }
function clearReply() { replyingTo = null; rpEl.style.display='none'; rpUser.textContent=''; rpText.textContent=''; }

/* ---------- typing ---------- */
let lastTypingAt = 0;
function sendTypingIfNeeded(){ const now = Date.now(); if (now - lastTypingAt < 1000) return; lastTypingAt = now; navigator.sendBeacon ? navigator.sendBeacon('private_interface.php?room=' + encodeURIComponent(ROOM_CODE) + '&mode=typing') : fetch('private_interface.php?room=' + encodeURIComponent(ROOM_CODE) + '&mode=typing', { method:'POST', keepalive:true, credentials:'same-origin' }).catch(()=>{}); }
msgInputEl.addEventListener('input', sendTypingIfNeeded);

/* ---------- send ---------- */
document.getElementById('sendBtn').addEventListener('click', send);
msgInputEl.addEventListener('keydown', (e)=>{ if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } });

async function send(){
  const ta = document.getElementById('msg'); const text = (ta.value||'').trim();
  if (!text) return;
  if (Array.from(text).length > MAX_MESSAGE_LENGTH) { alert('Message too long (' + MAX_MESSAGE_LENGTH + ' chars)'); return; }
  if (currentUser && currentUser.timeout_until && Date.parse(currentUser.timeout_until) > Date.now()){ alert('You are timed out until ' + currentUser.timeout_until); return; }
  try {
    let body = 'message=' + encodeURIComponent(text);
    if (replyingTo && replyingTo.id) body += '&reply_to=' + encodeURIComponent(replyingTo.id);
    const resp = await fetch('private_interface.php?room=' + encodeURIComponent(ROOM_CODE) + '&mode=send', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body, credentials:'same-origin' });
    const j = await resp.json().catch(()=>null);
    if (j && j.error) {
      if (j.error === 'timed_out') {
        alert('You are timed out until ' + (j.until || 'some time'));
        // refresh user state
        await immediatePoll();
        return;
      }
      alert(j.error);
    }
  } catch(e){ console.error('send failed', e); }
  ta.value=''; updateCharCount(); clearReply(); await immediatePoll();
}

/* ---------- image upload ---------- */
const imageInput = document.getElementById('imageInput'); const imageBtn = document.getElementById('imageBtn');
imageBtn.addEventListener('click', ()=> imageInput.click());
imageInput.addEventListener('change', async (e) => {
  const f = (e.target.files && e.target.files[0]) ? e.target.files[0] : null;
  if (!f) return;
  await uploadAndSendImage(f);
  imageInput.value = '';
});
async function uploadAndSendImage(file) {
  try {
    if (!file) return;
    if (file.size > MAX_IMAGE_UPLOAD_BYTES) { alert('Image too large.'); return; }
    const allowed = ['image/png','image/jpeg','image/webp','image/gif'];
    if (!allowed.includes(file.type)) { alert('Unsupported image type'); return; }
    if (!confirm('Upload and send this image?')) return;
    const fd = new FormData();
    fd.append('image', file);
    const status = document.createElement('div'); status.textContent = 'Uploading image…';
    status.style.position='fixed'; status.style.left='50%'; status.style.transform='translateX(-50%)'; status.style.bottom='140px';
    status.style.background='rgba(0,0,0,0.7)'; status.style.padding='8px 12px'; status.style.borderRadius='8px'; status.style.zIndex=2000;
    document.body.appendChild(status);
    const resp = await fetch('upload_image.php', { method:'POST', body: fd, credentials:'same-origin' });
    const j = await resp.json();
    document.body.removeChild(status);
    if (!j || !j.ok) { alert('Upload failed: ' + (j && j.error ? j.error : 'unknown')); return; }
    const imageUrl = j.url;
    const message = `![image](${imageUrl})`;
    await fetch('private_interface.php?room=' + encodeURIComponent(ROOM_CODE) + '&mode=send', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: 'message=' + encodeURIComponent(message), credentials:'same-origin' });
    await immediatePoll();
  } catch (err) { console.error('upload error', err); alert('Upload failed'); }
}

// initial load & long-poll loop
async function initialLoad(){
  try {
    const r = await fetch('private_interface.php?room=' + encodeURIComponent(ROOM_CODE), { credentials:'same-origin' });
    const j = await r.json();
    if (j.error) { console.error('initial load err', j); showAccessError(j.error); return; }
    if (j.user) currentUser = j.user;
    const messages = j.messages || [];
    const chat = document.getElementById('chat'); chat.innerHTML = ''; lastId = 0; lastUsernameInDOM = null;
    appendMessages(messages);
    updateTyping(j.typing || []);
    applyUserStatus();
    updateCharCount();
  } catch(e){ console.error('initialLoad', e); }
}

function updateTyping(list){ const names = (list || []).map(t=> t.username || t).filter(Boolean); document.getElementById('typing').innerText = names.length ? names.join(', ') + ' typing…' : ''; }

function applyUserStatus() {
  const statusBar = document.getElementById('statusBar');
  const msgInput = document.getElementById('msg');
  const aImg = document.getElementById('myAvatarImg');
  const aPh = document.getElementById('myAvatarPlaceholder');
  const color = currentUser ? (currentUser.role_color || '#9bbcff') : '#111';
  if (aImg) aImg.style.borderColor = color;
  if (aPh) aPh.style.borderColor = color;
  const t = currentUser && currentUser.timeout_until;
  if (t && Date.parse(t) > Date.now()) {
    statusBar.style.display='block';
    updateTimeoutCountdown(t);
    msgInput.disabled = true;
  } else {
    statusBar.style.display='none';
    msgInput.disabled = false;
  }
}

/* countdown for timeout */
let timeoutInterval = null;
function updateTimeoutCountdown(untilISO) {
  const statusBar = document.getElementById('statusBar');
  if (timeoutInterval) { clearInterval(timeoutInterval); timeoutInterval = null; }
  const until = Date.parse(untilISO);
  if (isNaN(until)) { statusBar.style.display='none'; return; }
  function tick() {
    const now = Date.now();
    if (now >= until) {
      statusBar.style.display='none'; document.getElementById('msg').disabled = false;
      clearInterval(timeoutInterval); timeoutInterval = null;
      immediatePoll();
      return;
    }
    const diff = Math.max(0, Math.floor((until - now)/1000));
    const h = Math.floor(diff/3600), m = Math.floor((diff%3600)/60), s = diff%60;
    const txt = (h>0? (h+'h ') : '') + (m>0? (m+'m ') : '') + s + 's';
    statusBar.textContent = 'Timed out — sending disabled for: ' + txt;
    document.getElementById('msg').disabled = true;
  }
  tick();
  timeoutInterval = setInterval(tick, 1000);
}

/* ---------- immediate poll ---------- */
async function immediatePoll(){ if (pollInFlight) return; try { pollInFlight = true; const r = await fetch('private_interface.php?room=' + encodeURIComponent(ROOM_CODE) + '&since=' + encodeURIComponent(lastId), { credentials:'same-origin' }); pollInFlight = false; if (!r.ok) return; const j = await r.json(); if (j.user) { currentUser = j.user; try { if (currentUser.timeout_until) currentUser.timeout_until = new Date(currentUser.timeout_until).toISOString(); } catch(e){} applyUserStatus(); } if (Array.isArray(j.messages) && j.messages.length) appendMessages(j.messages); updateTyping(j.typing || []); if (j.messages && j.messages.length && audioUnlocked) { try { bell.currentTime = 0; bell.play().catch(()=>{}); } catch(e){} } } catch(e){ pollInFlight = false; console.error('immediatePoll error', e); } }

/* ---------- long poll loop ---------- */
let visible = !document.hidden;
document.addEventListener('visibilitychange', ()=> visible = !document.hidden);
async function longPollLoop(){ const hiddenDelay = 30000; while (running) { try { if (!visible) await new Promise(r => setTimeout(r, hiddenDelay)); pollInFlight = true; const resp = await fetch('private_interface.php?room=' + encodeURIComponent(ROOM_CODE) + '&since=' + encodeURIComponent(lastId), { credentials:'same-origin' }); pollInFlight = false; if (!resp.ok) { await new Promise(r=>setTimeout(r,2000)); continue; } const j = await resp.json(); if (j.user) { currentUser = j.user; try { if (currentUser.timeout_until) currentUser.timeout_until = new Date(currentUser.timeout_until).toISOString(); } catch(e){} applyUserStatus(); } if (Array.isArray(j.messages) && j.messages.length) appendMessages(j.messages); updateTyping(j.typing || []); if (j.messages && j.messages.length && audioUnlocked) { try { bell.currentTime = 0; bell.play().catch(()=>{}); } catch(e){} } } catch(err) { pollInFlight = false; console.error('longPollLoop error', err); await new Promise(r=>setTimeout(r,2000)); } } }

/* ---------- context menu & moderation overlays ---------- */
const ctxMenu = document.getElementById('ctxMenu');
const ctxMenuItems = document.getElementById('ctxMenuItems');
let ctxTargetUserId = null;
let ctxTargetUsername = null;

document.addEventListener('click', ()=> ctxMenu.style.display = 'none');

function showContextMenu(x, y, items) {
  ctxMenuItems.innerHTML = '';
  items.forEach(it => {
    const div = document.createElement('div'); div.className = 'ctxItem'; div.textContent = it.label;
    div.addEventListener('click', (ev)=>{ ev.stopPropagation(); it.onClick(); ctxMenu.style.display='none'; });
    ctxMenuItems.appendChild(div);
  });
  ctxMenu.style.left = x + 'px'; ctxMenu.style.top = y + 'px'; ctxMenu.style.display = 'block';
}

async function onUserContextAsync(userId, username, ev, timeoutUntil) {
  ev.preventDefault();
  ctxTargetUserId = userId; ctxTargetUsername = username;
  // fetch target info and my mod info
  let targetData = null, myInfo = null;
  try {
    const tparams = new URLSearchParams();
    tparams.set('action','get_user_card'); tparams.set('username', username);
    if (typeof COMMUNITY_ID === 'number' && COMMUNITY_ID) tparams.set('community_id', String(COMMUNITY_ID));
    const tRes = await fetch('private.php?' + tparams.toString(), { credentials:'same-origin' });
    if (tRes.ok) targetData = await tRes.json();
  } catch (e) { /* ignore */ }
  try {
    const mparams = new URLSearchParams();
    mparams.set('action','get_my_mod_info');
    if (typeof COMMUNITY_ID === 'number' && COMMUNITY_ID) mparams.set('community_id', String(COMMUNITY_ID));
    const mRes = await fetch('private.php?' + mparams.toString(), { credentials:'same-origin' });
    if (mRes.ok) myInfo = await mRes.json();
  } catch (e) { /* ignore */ }

  // compute target max priority
  let targetMax = 0;
  if (targetData && Array.isArray(targetData.local_roles)) {
    for (const r of targetData.local_roles) { targetMax = Math.max(targetMax, (r.priority ? parseInt(r.priority,10) : 0)); }
  }
  // compute my priority
  let actorMax = 0;
  let actorPermissions = {};
  if (myInfo && myInfo.ok) {
    actorMax = parseInt(myInfo.actorMaxPriority || 0, 10);
    actorPermissions = myInfo.permissions || {};
  }

  const isOwner = actorPermissions && actorPermissions.is_owner;
  const canTimeout = actorPermissions && actorPermissions.can_timeout;
  const canBan = actorPermissions && actorPermissions.can_ban;
  const canAssign = actorPermissions && actorPermissions.can_assign_roles;
  const isTargetTimeouted = (targetData && targetData.user && targetData.user.timeout_until && Date.parse(targetData.user.timeout_until) > Date.now());

  const items = [];
  // Timeout item (only if we have permission and actor priority > target priority OR owner)
  if (canTimeout && (isOwner || actorMax > targetMax)) {
    items.push({ label: 'Timeout user', onClick: ()=> openModerationOverlay('timeout', userId, username) });
  }
  if (isTargetTimeouted && canTimeout && (isOwner || actorMax > targetMax)) {
    items.push({ label: 'Untimeout user', onClick: ()=> doUntimeout(userId) });
  }
  if (canBan && (isOwner || actorMax > targetMax)) {
    items.push({ label: 'Ban user', onClick: ()=> openModerationOverlay('ban', userId, username) });
  }
  if (canAssign) {
    // only allow manage roles if actorMax > targetMax OR owner
    if (isOwner || actorMax > targetMax) items.push({ label: 'Manage roles', onClick: ()=> openRolesOverlay(userId, username) });
  }

  // fallback: if empty, show a minimal item
  if (items.length === 0) items.push({ label: 'No moderation actions available', onClick: ()=>{} });

  showContextMenu(ev.pageX, ev.pageY, items);
}

// delegated right-click
document.addEventListener('contextmenu', (ev)=>{
  const el = ev.target.closest('.avatarLink, .userLink');
  if (!el) return;
  const row = el.closest('.msgRow');
  const uid = row ? row.dataset.userId : null;
  const uname = el.getAttribute('data-username') || el.dataset.username || el.textContent || null;
  if (!uid) return;
  onUserContextAsync(uid, uname, ev, row ? row.dataset.timeout : null).catch(()=>{});
});

/* moderation overlay handlers */
const modOverlay = document.getElementById('modOverlay');
const modBody = document.getElementById('modBody');
const modTitle = document.getElementById('modTitle');
const modCancel = document.getElementById('modCancel');
const modConfirm = document.getElementById('modConfirm');
modCancel.addEventListener('click', ()=> modOverlay.style.display='none');

function openModerationOverlay(mode, userId, username) {
  modOverlay.style.display='flex';
  modTitle.textContent = mode === 'ban' ? 'Ban user' : (mode === 'timeout' ? 'Timeout user' : 'Moderation');
  modBody.innerHTML = '';
  const info = document.createElement('div'); info.textContent = 'Target: ' + username + ' (id:' + userId + ')'; modBody.appendChild(info);
  if (mode === 'timeout') {
    const dur = document.createElement('div'); dur.style.marginTop='8px';
    dur.innerHTML = '<label>Duration (seconds): <input id="mod_timeout_seconds" type="number" value="600" style="width:160px;padding:6px;border-radius:6px;background:#0b0c0d;border:1px solid #222;color:#fff"></label>';
    modBody.appendChild(dur);
    const reason = document.createElement('textarea'); reason.id='mod_timeout_reason'; reason.placeholder='Reason (optional)'; reason.style.width='100%'; reason.style.marginTop='8px'; modBody.appendChild(reason);
    modConfirm.onclick = async ()=> {
      const sec = document.getElementById('mod_timeout_seconds').value || '600';
      const rsn = document.getElementById('mod_timeout_reason').value || '';
      try {
        const resp = await fetch('private.php?action=moderate', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ mode:'timeout', target_user_id: String(userId), duration_seconds: String(sec), reason: rsn, community_id: COMMUNITY_ID }) });
        const j = await resp.json();
        if (j && j.ok) { alert('User timed out until ' + j.until); modOverlay.style.display='none'; immediatePoll(); } else alert('Failed: ' + (j && j.error ? j.error : 'unknown'));
      } catch (e) { alert('Failed'); }
    };
  } else if (mode === 'ban') {
    const perm = document.createElement('div'); perm.style.marginTop='8px';
    perm.innerHTML = '<label><input type="checkbox" id="mod_ban_perm"> Permanent ban</label>';
    modBody.appendChild(perm);
    const dur = document.createElement('div'); dur.style.marginTop='8px';
    dur.innerHTML = '<label>Until (optional): <input id="mod_ban_until" type="text" placeholder="YYYY-MM-DD HH:MM" style="width:240px;padding:6px;border-radius:6px;background:#0b0c0d;border:1px solid #222;color:#fff"></label>';
    modBody.appendChild(dur);
    const reason = document.createElement('textarea'); reason.id='mod_ban_reason'; reason.placeholder='Reason (optional)'; reason.style.width='100%'; reason.style.marginTop='8px'; modBody.appendChild(reason);
    modConfirm.onclick = async ()=> {
      const permanent = document.getElementById('mod_ban_perm').checked;
      const until = document.getElementById('mod_ban_until').value || '';
      const rsn = document.getElementById('mod_ban_reason').value || '';
      try {
        const resp = await fetch('private.php?action=moderate', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ mode:'ban', target_user_id: String(userId), permanent: permanent ? 1 : 0, until_at: until, reason: rsn, community_id: COMMUNITY_ID }) });
        const j = await resp.json();
        if (j && j.ok) { alert('User banned'); modOverlay.style.display='none'; } else alert('Failed: ' + (j && j.error ? j.error : 'unknown'));
      } catch (e) { alert('Failed'); }
    };
  } else {
    modBody.innerHTML = '<div>Unknown mode</div>';
    modConfirm.onclick = ()=> { modOverlay.style.display='none'; };
  }
}

/* untimeout helper */
async function doUntimeout(userId) {
  if (!confirm('Remove timeout for this user?')) return;
  try {
    const resp = await fetch('private.php?action=moderate', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ mode:'untimeout', target_user_id: String(userId), community_id: COMMUNITY_ID }) });
    const j = await resp.json();
    if (j && j.ok) { alert('Timeout removed'); immediatePoll(); } else alert('Failed: ' + (j && j.error ? j.error : 'unknown'));
  } catch (e) { alert('Failed'); }
}

/* ---------- roles overlay functions ---------- */
const rolesOverlay = document.getElementById('rolesOverlay');
const rolesListArea = document.getElementById('rolesListArea');
const rolesTargetName = document.getElementById('rolesTargetName');
const rolesCancelBtn = document.getElementById('rolesCancelBtn');
const rolesSaveBtn = document.getElementById('rolesSaveBtn');
const createRoleBtn = document.getElementById('createRoleBtn');
const newRoleName = document.getElementById('newRoleName');
const newRoleBadge = document.getElementById('newRoleBadge');
const newRoleColor = document.getElementById('newRoleColor');
const newRolePriority = document.getElementById('newRolePriority');

let rolesLoaded = [];
let rolesSelected = new Set();
let rolesTargetId = null;

rolesCancelBtn.addEventListener('click', ()=> { rolesOverlay.style.display='none'; rolesSelected.clear(); rolesLoaded = []; });

rolesSaveBtn.addEventListener('click', async ()=> {
  const ids = Array.from(rolesSelected.values()).map(x=>String(x));
  try {
    const resp = await fetch('private.php?action=moderate', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ mode:'assign_roles', target_user_id: String(rolesTargetId), role_ids: ids, community_id: COMMUNITY_ID }) });
    const j = await resp.json();
    if (j && j.ok) { alert('Roles updated'); rolesOverlay.style.display='none'; await immediatePoll(); } else alert('Failed: ' + (j && j.error ? j.error : 'unknown'));
  } catch (e) { alert('Failed'); }
});

createRoleBtn.addEventListener('click', async ()=> {
  const name = newRoleName.value.trim();
  if (!name) return alert('Provide a role name');
  if (!COMMUNITY_ID) return alert('No community context');
  const badge = newRoleBadge.value.trim();
  const color = newRoleColor.value;
  const priority = parseInt(newRolePriority.value || '0', 10);
  try {
    const resp = await fetch('private.php?action=moderate', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ mode:'create_role', name: name, badge: badge, color: color, priority: String(priority), community_id: COMMUNITY_ID }) });
    const j = await resp.json();
    if (j && j.ok) {
      alert('Role created');
      newRoleName.value = ''; newRoleBadge.value = '';
      await loadRolesForUser(rolesTargetId);
    } else alert('Create failed: ' + (j && j.error ? j.error : 'unknown'));
  } catch (e) { alert('Create failed'); }
});

async function openRolesOverlay(userId, username) {
  rolesTargetId = userId;
  rolesTargetName.textContent = username + ' (id:' + userId + ')';
  rolesOverlay.style.display = 'flex';
  rolesListArea.innerHTML = 'Loading roles…';
  await loadRolesForUser(userId);
}

async function loadRolesForUser(userId) {
  rolesLoaded = []; rolesSelected.clear();
  try {
    const res = await fetch('private.php?action=list_roles&community_id=' + encodeURIComponent(COMMUNITY_ID), { credentials:'same-origin' });
    const j = await res.json();
    if (!j || !j.ok) { rolesListArea.innerHTML = '<div style="color:#f66">Failed to load roles</div>'; return; }
    rolesLoaded = j.roles || [];
    // fetch user's local roles
    const targetUsername = rolesTargetName.textContent.split(' (')[0];
    const cardResp = await fetch('private.php?action=get_user_card&username=' + encodeURIComponent(targetUsername) + '&community_id=' + encodeURIComponent(COMMUNITY_ID), { credentials:'same-origin' });
    const cardJson = await cardResp.json();
    let userRoles = [];
    if (cardJson && cardJson.ok && Array.isArray(cardJson.local_roles)) userRoles = cardJson.local_roles.map(r => r.id ? String(r.id) : null).filter(Boolean);

    rolesListArea.innerHTML = '';
    const grid = document.createElement('div'); grid.className='grid-roles';
    for (const r of rolesLoaded) {
      const item = document.createElement('label'); item.className='role-card';
      const cb = document.createElement('input'); cb.type='checkbox'; cb.value = String(r.id);
      if (userRoles.includes(String(r.id))) { cb.checked = true; rolesSelected.add(String(r.id)); }
      cb.addEventListener('change', (ev)=> { if (ev.target.checked) rolesSelected.add(ev.target.value); else rolesSelected.delete(ev.target.value); });
      const colorBox = document.createElement('div'); colorBox.style.width='18px'; colorBox.style.height='18px'; colorBox.style.borderRadius='6px'; colorBox.style.background = r.color || '#ddd';
      const lbl = document.createElement('div'); lbl.textContent = (r.badge ? (r.badge + ' ') : '') + r.name; lbl.style.flex='1'; lbl.style.overflow='hidden';
      item.appendChild(cb); item.appendChild(colorBox); item.appendChild(lbl);
      grid.appendChild(item);
    }
    rolesListArea.appendChild(grid);
  } catch (e) { rolesListArea.innerHTML = '<div style="color:#f66">Failed to load roles</div>'; }
}

// emoji picker
const emojiPicker = document.getElementById('emojiPicker'), emojiBtn = document.getElementById('emojiBtn');
function populateEmojiPicker(){ emojiPicker.innerHTML = EMOJIS.map(e=>' <span>'+e+'</span>').join(''); emojiPicker.querySelectorAll('span').forEach(s=>s.addEventListener('click', ()=>{ const ta=document.getElementById('msg'); const start=ta.selectionStart,end=ta.selectionEnd; ta.value = ta.value.slice(0,start)+s.innerText+ta.value.slice(end); ta.focus(); ta.selectionStart=ta.selectionEnd = start + s.innerText.length; emojiPicker.style.display='none'; updateCharCount(); })); }
emojiBtn.addEventListener('click', ()=>{ if (emojiPicker.style.display==='none' || !emojiPicker.style.display){ populateEmojiPicker(); emojiPicker.style.display='block'; } else { emojiPicker.style.display='none'; } });
document.addEventListener('click', e=>{ if (!emojiPicker.contains(e.target) && e.target !== emojiBtn) emojiPicker.style.display='none'; });

    
// -------------- notifications bell (grouped) ----------------
const notifBell = document.getElementById('notifBell');
const notifBadge = document.getElementById('notifBadge');
const notifDropdown = document.getElementById('notifDropdown');
const notifList = document.getElementById('notifList');
const markAllBtn = document.getElementById('markAllBtn');

let lastUnread = 0;
let polling = null;
const POLL_INTERVAL = 30000;
const API = NOTIF_ENDPOINT;
const marked = new Set();

async function fetchNotifications(limit=100) {
  try {
    const res = await fetch(`${API}?limit=${encodeURIComponent(limit)}`, { credentials: 'same-origin' });
    if (!res.ok) throw new Error('failed fetch');
    return await res.json();
  } catch (e) { console.error('fetchNotifications', e); return { notifications: [], unread_count: 0 }; }
}

function groupNotifications(rows) {
  const groups = new Map();
  for (const n of rows) {
    let key;
    if ((n.type || '') === 'modmail' && n.ref_id) {
      key = 'modmail|ref|' + (n.ref_id || 0);
    } else {
      key = (n.type || '') + '|' + (n.source_user_id || 0);
    }

    if (!groups.has(key)) groups.set(key, { key, type: n.type, source_user_id: n.source_user_id, source_username: n.source_username, source_avatar: n.source_avatar, ids: [], latest: null, count: 0, firstCreated: n.created_at, important: (n.important||0), ref_id: n.ref_id || null });
    const g = groups.get(key);
    g.ids.push(n.id);
    g.count++;
    if (!g.latest || n.id > g.latest.id) g.latest = n;
    if (!g.firstCreated || new Date(n.created_at) > new Date(g.firstCreated)) g.firstCreated = n.created_at;
    if (n.important) g.important = 1;
  }
  const arr = Array.from(groups.values()).sort((a,b) => (b.latest?.id || 0) - (a.latest?.id || 0));
  return arr;
}

function renderGroupElement(g) {
  const el = document.createElement('div');
  el.className = 'notifGroup' + (g.latest && !g.latest.is_read ? ' unread' : '');
  if (g.type === 'modmail') el.classList.add('modmail');

  el.dataset.ids = g.ids.join(',');
  const av = document.createElement('div'); av.className='avatar';
  if (g.source_avatar) {
    const img = document.createElement('img');
    img.src = (g.source_avatar.indexOf('/') === 0 || g.source_avatar.startsWith('http')) ? g.source_avatar : 'avatars/' + encodeURIComponent(g.source_avatar);
    img.style.width='100%'; img.style.height='100%'; img.style.objectFit='cover';
    av.appendChild(img);
  } else {
    av.textContent = (g.source_username ? g.source_username[0].toUpperCase() : '?');
  }
  const meta = document.createElement('div'); meta.className='meta';
  const title = document.createElement('div'); title.className='title';
  let titleText = '';
  if (g.type === 'dm') {
    titleText = g.source_username ? `${g.source_username}` : 'Someone';
    if (g.count > 1) titleText += ` — ${g.count} messages`;
    else titleText += ' — sent you a message';
  } else if (g.type === 'friend_request') {
    titleText = g.source_username ? `${g.source_username} sent you a friend request` : 'Friend request';
  } else if (g.type === 'friend_accept' || g.type === 'friend_acceptance') {
    titleText = g.source_username ? `${g.source_username} accepted your request` : 'Friend accepted';
  } else if (g.type === 'modmail') {
    titleText = (g.important ? '🔔 IMPORTANT — ' : '') + (g.latest.message || 'Modmail');
    if (g.latest && g.latest.message && g.latest.message.length > 80) titleText = titleText.slice(0, 80) + '…';
  } else {
    titleText = g.latest && g.latest.message ? String(g.latest.message) : (g.type || 'Notification');
    if (g.count > 1) titleText += ` (${g.count})`;
  }
  title.textContent = titleText;
  const msg = document.createElement('div'); msg.className='msg';
  msg.textContent = g.latest && g.latest.created_at ? new Date(g.latest.created_at).toLocaleString() : '';
  meta.appendChild(title); meta.appendChild(msg);

  const right = document.createElement('div'); right.style.display='flex'; right.style.flexDirection='column'; right.style.alignItems='flex-end';
  const time = document.createElement('div'); time.className='time'; time.textContent = g.latest && g.latest.created_at ? new Date(g.latest.created_at).toLocaleTimeString() : '';
  const cnt = document.createElement('div'); cnt.className='notifCount'; cnt.textContent = g.count > 1 ? g.count : '';
  if (g.count <= 1) cnt.style.display = 'none';
  right.appendChild(cnt); right.appendChild(time);

  el.appendChild(av);
  el.appendChild(meta);
  el.appendChild(right);

  if (g.latest && !g.latest.is_read) {
    const dot = document.createElement('div'); dot.className = 'unreadDot';
    el.appendChild(dot);
  }

  el.addEventListener('click', async (ev) => {
    ev.stopPropagation();
    for (const id of g.ids) {
      if (!marked.has(id)) {
        try { await fetch(`${API}`, { method:'POST', credentials:'same-origin', body: new URLSearchParams({ action:'mark_read', id: id }) }); marked.add(id); } catch (e) {}
      }
    }
    if (g.type === 'modmail' && g.latest && g.latest.ref_id) {
      window.location.href = 'modmail.php?id=' + encodeURIComponent(g.latest.ref_id);
    } else if (g.source_username) {
      window.location.href = 'message.php?user=' + encodeURIComponent(g.source_username);
    } else {
      toggleNotifDropdown(false);
    }
  });

  return el;
}

async function markIdsRead(ids) {
  for (const id of ids) {
    if (marked.has(id)) continue;
    try { await fetch(`${API}`, { method:'POST', credentials:'same-origin', body: new URLSearchParams({ action:'mark_read', id: id }) }); marked.add(id); } catch (e) { console.error('markIdsRead', e); }
  }
}

async function loadNotifications(opened=false) {
  try {
    const j = await fetchNotifications(200);
    const unread = j.unread_count || 0;
    if (unread > (lastUnread || 0) && lastUnread !== 0) {
      try { if (audioUnlocked) { bell2.currentTime = 0; bell2.play().catch(()=>{}); } } catch(e){}
    }
    lastUnread = unread;
    if (unread > 0) { notifBadge.style.display = 'inline-block'; notifBadge.textContent = unread > 99 ? '99+' : String(unread); }
    else notifBadge.style.display = 'none';

    const rows = Array.isArray(j.notifications) ? j.notifications : [];
    const groups = groupNotifications(rows);

    notifList.innerHTML = '';
    if (groups.length === 0) {
      const div = document.createElement('div'); div.style.padding='12px'; div.style.color='var(--muted)'; div.textContent = 'No notifications';
      notifList.appendChild(div);
      return;
    }

    groups.forEach(g => { const el = renderGroupElement(g); el.dataset.ids = g.ids.join(','); notifList.appendChild(el); });

    setupObserver();

  } catch (e) { notifList.innerHTML = '<div style="padding:12px;color:#f66">Failed to load notifications</div>'; console.error('loadNotifications', e); }
}

let observer = null;
function setupObserver() {
  if (observer) { observer.disconnect(); observer = null; }
  const opts = { root: notifList, rootMargin: '0px', threshold: 0.6 };
  observer = new IntersectionObserver(async (entries) => {
    for (const ent of entries) {
      if (!ent.isIntersecting) continue;
      const el = ent.target;
      const idsStr = el.dataset.ids || '';
      if (!idsStr) continue;
      const ids = idsStr.split(',').map(s => parseInt(s,10)).filter(Boolean);
      const toMark = ids.filter(id => !marked.has(id));
      if (toMark.length > 0) {
        await markIdsRead(toMark);
        el.classList.remove('unread');
        const dot = el.querySelector('.unreadDot');
        if (dot) dot.remove();
        lastUnread = Math.max(0, lastUnread - toMark.length);
        if (lastUnread > 0) notifBadge.textContent = lastUnread > 99 ? '99+' : String(lastUnread);
        else notifBadge.style.display = 'none';
      }
      observer.unobserve(el);
    }
  }, opts);

  const items = notifList.querySelectorAll('.notifGroup');
  items.forEach(it => {
    const ids = (it.dataset.ids || '').split(',').map(s=>parseInt(s,10)).filter(Boolean);
    const anyUnmarked = ids.some(id => !marked.has(id));
    if (anyUnmarked) observer.observe(it);
  });
}

function toggleNotifDropdown(force) {
  const current = notifDropdown.style.display === 'block';
  const next = (typeof force === 'boolean') ? force : !current;
  notifDropdown.style.display = next ? 'block' : 'none';
  if (next) loadNotifications(true);
}
notifBell.addEventListener('click', (e)=> { e.stopPropagation(); toggleNotifDropdown(); });

markAllBtn.addEventListener('click', async (e)=>{
  e.stopPropagation();
  try {
    await fetch(`${API}`, { method:'POST', credentials:'same-origin', body: new URLSearchParams({ action:'mark_all_read' }) });
    const items = document.querySelectorAll('.notifGroup'); items.forEach(it => { it.classList.remove('unread'); const dot = it.querySelector('.unreadDot'); if (dot) dot.remove(); });
    lastUnread = 0; notifBadge.style.display='none';
    const j = await fetchNotifications(200);
    if (Array.isArray(j.notifications)) { for (const n of j.notifications) marked.add(n.id); }
  } catch (e) { console.error(e); }
});

document.addEventListener('click', (e)=> { if (!e.target.closest || (!e.target.closest('#notifBell') && !e.target.closest('#notifDropdown'))) toggleNotifDropdown(false); });

async function startNotifPolling() {
  try {
    const j = await fetchNotifications(5);
    lastUnread = j.unread_count || 0;
    if (lastUnread > 0) { notifBadge.style.display = 'inline-block'; notifBadge.textContent = lastUnread > 99 ? '99+' : String(lastUnread); }
    else notifBadge.style.display='none';
  } catch (e) { console.error('initial notifications', e); }

  polling = setInterval(async ()=> {
    try {
      const j = await fetchNotifications(5);
      if (!j) return;
      const unreadNow = j.unread_count || 0;
      if (unreadNow > (lastUnread || 0)) {
        try { if (audioUnlocked) { bell2.currentTime = 0; bell2.play().catch(()=>{}); } } catch(e){}
      } else if (unreadNow > 0 && lastUnread === 0) {
        try { if (audioUnlocked) { bell.currentTime = 0; bell.play().catch(()=>{}); } } catch(e){}
      }
      lastUnread = unreadNow;
      if (lastUnread > 0) { notifBadge.style.display = 'inline-block'; notifBadge.textContent = lastUnread > 99 ? '99+' : String(lastUnread); }
      else notifBadge.style.display='none';
      if (notifDropdown.style.display === 'block') await loadNotifications(true);
    } catch (e) { console.error('poll', e); }
  }, POLL_INTERVAL);
}
    
async function startNotifPolling() {
  try {
    const j = await fetchNotifications(5);
    let lastUnread = j.unread_count || 0;
    if (lastUnread > 0) { notifBadge.style.display = 'inline-block'; notifBadge.textContent = lastUnread > 99 ? '99+' : String(lastUnread); } else notifBadge.style.display='none';
    setInterval(async ()=> {
      try {
        const k = await fetchNotifications(5);
        if (k.unread_count > lastUnread && audioUnlocked) { bell2.currentTime = 0; bell2.play().catch(()=>{}); }
        lastUnread = k.unread_count;
        if (lastUnread>0){ notifBadge.style.display='inline-block'; notifBadge.textContent = lastUnread>99?'99+':String(lastUnread); } else notifBadge.style.display='none';
      } catch(e){}
    }, 30000);
  } catch(e){}
}
notifBell.addEventListener('click', ()=> { /* toggle handled by notification code */ });    
    
/* ---------- start ---------- */
initialLoad().then(()=> { longPollLoop(); startNotifPolling(); }).catch(()=>{ startNotifPolling(); });
window.addEventListener('beforeunload', ()=> running = false);
</script>
</body>
</html>
