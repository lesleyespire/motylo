<?php
// community_admin.php - admin UI with role hierarchy, timeouts, bans, audit, channels
require "config.php";
session_start();

/* ---------- DEBUG / LOGGING ---------- */
@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
function admin_log($msg) {
    $fn = '/tmp/community_admin.log';
    @file_put_contents($fn, date('[Y-m-d H:i:s] ').(is_scalar($msg)?$msg:var_export($msg, true)).PHP_EOL, FILE_APPEND|LOCK_EX);
}

/* ---------- helpers ---------- */
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
    $cols = get_table_columns($pdo, 'community_roles');
    return in_array(strtolower($col), $cols, true);
}
function getRequestData() {
    if (!empty($_POST)) return $_POST;
    $raw = @file_get_contents('php://input');
    if ($raw) {
        $j = @json_decode($raw, true);
        if (is_array($j)) return $j;
        parse_str($raw, $out);
        if (!empty($out)) return $out;
    }
    return $_GET ?? [];
}

/* ---------- migrations: ensure core tables/columns exist (non-destructive) ---------- */
$create_roles_sql = "CREATE TABLE IF NOT EXISTS community_roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    community_id INT NOT NULL,
    name VARCHAR(120) NOT NULL,
    color VARCHAR(32) DEFAULT NULL,
    badge VARCHAR(32) DEFAULT NULL,
    priority INT DEFAULT 0,
    is_admin TINYINT(1) DEFAULT 0,
    can_delete_messages TINYINT(1) DEFAULT 0,
    can_timeout TINYINT(1) DEFAULT 0,
    can_ban TINYINT(1) DEFAULT 0,
    can_edit_channels TINYINT(1) DEFAULT 0,
    can_view_locked TINYINT(1) DEFAULT 0,
    UNIQUE KEY ux_comm_name (community_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
try { $pdo->exec($create_roles_sql); } catch (Exception $e) { /* ignore */ }
$cols_needed = [
    'priority'=>"priority INT DEFAULT 0",
    'is_admin'=>"is_admin TINYINT(1) DEFAULT 0",
    'can_delete_messages'=>"can_delete_messages TINYINT(1) DEFAULT 0",
    'can_timeout'=>"can_timeout TINYINT(1) DEFAULT 0",
    'can_ban'=>"can_ban TINYINT(1) DEFAULT 0",
    'can_edit_channels'=>"can_edit_channels TINYINT(1) DEFAULT 0",
    'can_view_locked'=>"can_view_locked TINYINT(1) DEFAULT 0"
];
try {
    $existing = array_map('strtolower', $pdo->query("SHOW COLUMNS FROM `community_roles`")->fetchAll(PDO::FETCH_COLUMN,0) ?: []);
    foreach ($cols_needed as $col=>$def) {
        if (!in_array(strtolower($col), $existing, true)) {
            try { $pdo->exec("ALTER TABLE community_roles ADD COLUMN $def"); } catch (Exception $e) {}
        }
    }
} catch (Exception $e) { /* ignore */ }

// add optional admin columns: is_owner, can_assign_roles
try {
    $cols = get_table_columns($pdo,'community_roles');
    if (!in_array('is_owner', $cols, true)) {
        try { $pdo->exec("ALTER TABLE community_roles ADD COLUMN is_owner TINYINT(1) DEFAULT 0"); } catch (Exception $e) {}
    }
    if (!in_array('can_assign_roles', $cols, true)) {
        try { $pdo->exec("ALTER TABLE community_roles ADD COLUMN can_assign_roles TINYINT(1) DEFAULT 0"); } catch (Exception $e) {}
    }
} catch (Exception $e) { /* ignore */ }

// ensure many-to-many membership table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS community_member_roles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        community_id INT NOT NULL,
        user_id INT NOT NULL,
        role_id INT NOT NULL,
        assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY ux_member_role (community_id, user_id, role_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { /* ignore */ }

// ensure community_timeouts table exists
try {
    if (!table_exists($pdo,'community_timeouts')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS community_timeouts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            community_id INT NOT NULL,
            user_id INT NOT NULL,
            actor_user INT NULL,
            reason TEXT NULL,
            until_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } else {
        $cols = get_table_columns($pdo,'community_timeouts');
        if (!in_array('until_at', $cols, true)) {
            try { $pdo->exec("ALTER TABLE community_timeouts ADD COLUMN until_at DATETIME NULL"); } catch(Exception $e) {}
        }
    }
} catch (Exception $e) { /* ignore */ }

// ensure community_bans exists
try {
    if (!table_exists($pdo,'community_bans')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS community_bans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            community_id INT NOT NULL,
            user_id INT NOT NULL,
            username VARCHAR(255) NULL,
            banned_by INT NULL,
            reason TEXT NULL,
            until_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
} catch (Exception $e) { /* ignore */ }

// ensure community_audit exists with the exact schema you specified
try {
    if (!table_exists($pdo,'community_audit')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS community_audit (
            id INT AUTO_INCREMENT PRIMARY KEY,
            community_id INT NOT NULL,
            action VARCHAR(64) NOT NULL,
            actor_user INT NULL,
            target_user INT NULL,
            target_message TEXT NULL,
            reason TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } else {
        // make sure required columns exist (non-destructive)
        $cols = get_table_columns($pdo,'community_audit');
        $need = [
            'action'=>"action VARCHAR(64) NOT NULL",
            'actor_user'=>"actor_user INT NULL",
            'target_user'=>"target_user INT NULL",
            'target_message'=>"target_message TEXT NULL",
            'reason'=>"reason TEXT NULL"
        ];
        foreach ($need as $c=>$d) {
            if (!in_array($c, $cols, true)) {
                try { $pdo->exec("ALTER TABLE community_audit ADD COLUMN $d"); } catch (Exception $e) {}
            }
        }
    }
} catch (Exception $e) { /* ignore */ }

// ensure private_rooms.required_role_id exists
try {
    $cols = get_table_columns($pdo,'private_rooms');
    if (!in_array('required_role_id', $cols, true)) {
        try { $pdo->exec("ALTER TABLE private_rooms ADD COLUMN required_role_id INT NULL DEFAULT NULL"); } catch(Exception $e){ /* ignore */ }
    }
} catch (Exception $e) { /* ignore */ }

/* ---------- auth & community ---------- */
if (empty($_COOKIE['auth_token'])) { header("Location:index.php"); exit; }
$st = $pdo->prepare("SELECT id, username FROM users WHERE auth_token = ? LIMIT 1"); $st->execute([$_COOKIE['auth_token']]);
$me = $st->fetch(PDO::FETCH_ASSOC);
if (!$me) { header("Location:index.php"); exit; }
$me_id = (int)$me['id'];

$public_id = trim((string)($_GET['public_id'] ?? ''));
if ($public_id === '') die("missing public_id");
$com = $pdo->prepare("SELECT * FROM communities WHERE public_id = ? LIMIT 1"); $com->execute([$public_id]);
$community = $com->fetch(PDO::FETCH_ASSOC);
if (!$community) die("Community not found");
$community_id = (int)$community['id'];
$owner_id = (int)$community['owner_id'];

/* --------- compute actor permissions & max priority ---------- */
$isOwner = ($owner_id === $me_id);

// is global staff? fallback
$isGlobalStaff = false;
try {
    if (table_exists($pdo,'roles')) {
        $stg = $pdo->prepare("SELECT r.name FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ? LIMIT 1");
        $stg->execute([$me_id]);
        $rn = strtolower((string)$stg->fetchColumn());
        if (strpos($rn,'admin') !== false || strpos($rn,'moderator') !== false) $isGlobalStaff = true;
    }
} catch (Exception $e) { /* ignore */ }

$has_member_roles = table_exists($pdo,'community_member_roles') && table_exists($pdo,'community_roles');

$actorMaxPriority = 0;
$actorPermissions = [
    'is_owner'=> $isOwner,
    'is_admin'=> false,
    'can_ban'=> false,
    'can_timeout'=> false,
    'can_assign_roles'=> false,
    'can_delete_messages'=> false,
    'can_edit_channels'=> false,
    'can_view_locked'=> false
];

try {
    if ($community_id && $has_member_roles) {
        $q = $pdo->prepare("SELECT cr.* FROM community_member_roles mr JOIN community_roles cr ON cr.id = mr.role_id WHERE mr.community_id = ? AND mr.user_id = ?");
        $q->execute([$community_id, $me_id]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $actorMaxPriority = max($actorMaxPriority, (int)($r['priority'] ?? 0));
            foreach (['is_admin','can_ban','can_timeout','can_assign_roles','can_delete_messages','can_edit_channels','can_view_locked','is_owner'] as $c) {
                if (!empty($r[$c])) $actorPermissions[$c] = true;
            }
        }
    } else {
        // older one-role-per-member system
        if (table_exists($pdo,'community_members') && table_exists($pdo,'community_roles')) {
            $q2 = $pdo->prepare("SELECT cr.* FROM community_members cm LEFT JOIN community_roles cr ON cr.id = cm.role_id WHERE cm.community_id = ? AND cm.user_id = ? LIMIT 1");
            $q2->execute([$community_id, $me_id]);
            $r = $q2->fetch(PDO::FETCH_ASSOC);
            if ($r) {
                $actorMaxPriority = (int)($r['priority'] ?? 0);
                foreach (['is_admin','can_ban','can_timeout','can_assign_roles','can_delete_messages','can_edit_channels','can_view_locked','is_owner'] as $c) {
                    if (!empty($r[$c])) $actorPermissions[$c] = true;
                }
            }
        }
    }

    if ($isOwner || $isGlobalStaff) {
        // elevate
        $actorPermissions['is_admin'] = true;
        $actorPermissions['can_ban'] = true;
        $actorPermissions['can_timeout'] = true;
        $actorPermissions['can_assign_roles'] = true;
        $actorPermissions['can_delete_messages'] = true;
        $actorPermissions['can_edit_channels'] = true;
        $actorPermissions['can_view_locked'] = true;
        if ($isOwner) $actorPermissions['is_owner'] = true;
        $actorMaxPriority = max($actorMaxPriority, 1000000);
    }
} catch (Exception $e) {
    admin_log("[perm compute] ".$e->getMessage());
}

/* ---------- audit helper (matches your schema) ---------- */
function audit_log($pdo, $community_id, $action, $actor_user = null, $target_user = null, $target_message = null, $reason = null) {
    try {
        $ins = $pdo->prepare("INSERT INTO community_audit (community_id, action, actor_user, target_user, target_message, reason) VALUES (?, ?, ?, ?, ?, ?)");
        $ins->execute([$community_id, $action, $actor_user, $target_user, $target_message, $reason]);
    } catch (Exception $e) { admin_log("[audit_log] ".$e->getMessage()); }
}

/* ---------- POST actions ---------- */
$action = $_POST['action'] ?? ($_GET['action'] ?? '');
if ($action) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!$me_id) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'not_logged_in']); exit; }
        $actorMax = $actorMaxPriority;
        $actorPerms = $actorPermissions;
        $ownerFlag = $isOwner;

        // filterable permission input helper
        $filterPermsInput = function(array $input, array $actorPerms, bool $isOwner) {
            $allowed = ['is_admin','can_delete_messages','can_timeout','can_ban','can_edit_channels','can_view_locked','can_assign_roles','is_owner'];
            $out = [];
            foreach ($allowed as $p) {
                if (!isset($input[$p])) continue;
                $val = ($input[$p] ? 1 : 0);
                if ($p === 'is_owner') {
                    if ($isOwner) $out[$p] = $val;
                } else {
                    $actorHas = !empty($actorPerms[$p]) || !empty($actorPerms['is_admin']) || !empty($actorPerms['is_owner']);
                    if ($actorHas) $out[$p] = $val;
                }
            }
            return $out;
        };

        switch ($action) {

            /* create_role */
            case 'create_role':
                $name = trim((string)($_POST['name'] ?? ''));
                if ($name === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing_name']); exit; }
                $color = trim((string)($_POST['color'] ?? '')) ?: null;
                $badge = trim((string)($_POST['badge'] ?? '')) ?: null;
                $priority = isset($_POST['priority']) ? (int)$_POST['priority'] : 0;
                if (!$ownerFlag && $priority > $actorMax) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'priority_too_high']); exit; }
                $inputPerms = [
                    'is_admin'=> !empty($_POST['is_admin']) ? 1 : 0,
                    'can_delete_messages'=> !empty($_POST['can_delete']) ? 1 : 0,
                    'can_timeout'=> !empty($_POST['can_timeout']) ? 1 : 0,
                    'can_ban'=> !empty($_POST['can_ban']) ? 1 : 0,
                    'can_edit_channels'=> !empty($_POST['can_edit']) ? 1 : 0,
                    'can_view_locked'=> !empty($_POST['can_view_locked']) ? 1 : 0,
                    'can_assign_roles'=> !empty($_POST['can_assign_roles']) ? 1 : 0,
                    'is_owner'=> !empty($_POST['is_owner']) ? 1 : 0
                ];
                $filtered = $filterPermsInput($inputPerms, $actorPerms, $ownerFlag);

                $cols = ['community_id','name','color','badge','priority'];
                $vals = ['?','?','?','?','?'];
                $params = [$community_id,$name,$color,$badge,$priority];
                foreach ($filtered as $k=>$v) { $cols[] = $k; $vals[] = '?'; $params[] = $v; }
                $sql = "INSERT INTO community_roles (".implode(',', $cols).") VALUES (".implode(',', $vals).")";
                $ins = $pdo->prepare($sql);
                $ins->execute($params);
                $newId = $pdo->lastInsertId();
                audit_log($pdo, $community_id, 'create_role', $me_id, null, json_encode(['role_id'=>$newId,'name'=>$name]), 'created role');
                echo json_encode(['ok'=>true,'role_id'=>$newId]); exit;

            /* update_role */
            case 'update_role':
                $role_id = isset($_POST['role_id']) ? (int)$_POST['role_id'] : 0;
                if (!$role_id) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing_role']); exit; }
                $rQ = $pdo->prepare("SELECT * FROM community_roles WHERE id = ? AND community_id = ? LIMIT 1"); $rQ->execute([$role_id,$community_id]);
                $role = $rQ->fetch(PDO::FETCH_ASSOC);
                if (!$role) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'role_not_found']); exit; }
                $name = trim((string)($_POST['name'] ?? $role['name']));
                $color = trim((string)($_POST['color'] ?? $role['color'])) ?: null;
                $badge = trim((string)($_POST['badge'] ?? $role['badge'])) ?: null;
                $priority = isset($_POST['priority']) ? (int)$_POST['priority'] : (int)$role['priority'];
                if (!$ownerFlag && $priority > $actorMax) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'priority_too_high']); exit; }
                $inputPerms = [
                    'is_admin'=> !empty($_POST['is_admin']) ? 1 : 0,
                    'can_delete_messages'=> !empty($_POST['can_delete']) ? 1 : 0,
                    'can_timeout'=> !empty($_POST['can_timeout']) ? 1 : 0,
                    'can_ban'=> !empty($_POST['can_ban']) ? 1 : 0,
                    'can_edit_channels'=> !empty($_POST['can_edit']) ? 1 : 0,
                    'can_view_locked'=> !empty($_POST['can_view_locked']) ? 1 : 0,
                    'can_assign_roles'=> !empty($_POST['can_assign_roles']) ? 1 : 0,
                    'is_owner'=> !empty($_POST['is_owner']) ? 1 : 0
                ];
                $filtered = $filterPermsInput($inputPerms, $actorPerms, $ownerFlag);
                $sets = ["name = ?", "color = ?", "badge = ?", "priority = ?"];
                $params = [$name, $color, $badge, $priority];
                foreach ($filtered as $k=>$v) { $sets[] = "{$k} = ?"; $params[] = $v; }
                $params[] = $role_id; $params[] = $community_id;
                $sql = "UPDATE community_roles SET ".implode(',', $sets)." WHERE id = ? AND community_id = ?";
                $upd = $pdo->prepare($sql);
                $upd->execute($params);
                audit_log($pdo, $community_id, 'update_role', $me_id, null, json_encode(['role_id'=>$role_id,'priority'=>$priority]), 'updated role');
                echo json_encode(['ok'=>true]); exit;

            /* delete_role */
            case 'delete_role':
                $role_id = isset($_POST['role_id']) ? (int)$_POST['role_id'] : 0;
                if (!$role_id) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing_role']); exit; }
                $rq = $pdo->prepare("SELECT priority FROM community_roles WHERE id = ? AND community_id = ? LIMIT 1"); $rq->execute([$role_id,$community_id]);
                $rr = $rq->fetch(PDO::FETCH_ASSOC);
                if (!$rr) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'role_not_found']); exit; }
                $rprio = (int)$rr['priority'];
                if (!$ownerFlag && $rprio >= $actorMax && empty($actorPerms['is_admin'])) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'role_too_high']); exit; }
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("DELETE FROM community_member_roles WHERE community_id = ? AND role_id = ?")->execute([$community_id,$role_id]);
                    $pdo->prepare("DELETE FROM community_roles WHERE id = ? AND community_id = ?")->execute([$role_id,$community_id]);
                    $pdo->commit();
                    audit_log($pdo, $community_id, 'delete_role', $me_id, null, json_encode(['role_id'=>$role_id]), 'deleted role');
                    echo json_encode(['ok'=>true]); exit;
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    admin_log("[delete_role] ".$e->getMessage());
                    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'server']); exit;
                }

            /* assign_role */
            case 'assign_role':
                $username = trim((string)($_POST['username'] ?? ''));
                $role_id = isset($_POST['role_id']) ? (int)$_POST['role_id'] : 0;
                if ($username === '' || !$role_id) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing_params']); exit; }
                $u = $pdo->prepare("SELECT id, username FROM users WHERE username = ? LIMIT 1"); $u->execute([$username]); $user = $u->fetch(PDO::FETCH_ASSOC);
                if (!$user) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'user_not_found']); exit; }
                $target_id = (int)$user['id'];
                $rQ = $pdo->prepare("SELECT id, priority FROM community_roles WHERE id = ? AND community_id = ? LIMIT 1"); $rQ->execute([$role_id,$community_id]); $rR = $rQ->fetch(PDO::FETCH_ASSOC);
                if (!$rR) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'role_not_found']); exit; }
                $rolePriority = (int)$rR['priority'];
                if (!$ownerFlag && $rolePriority >= $actorMax && empty($actorPerms['is_admin'])) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'role_too_high']); exit; }
                try {
                    $ins = $pdo->prepare("INSERT IGNORE INTO community_member_roles (community_id, user_id, role_id) VALUES (?, ?, ?)");
                    $ins->execute([$community_id, $target_id, $role_id]);
                    audit_log($pdo, $community_id, 'add_role', $me_id, $target_id, json_encode(['role_id'=>$role_id]), 'assigned role via admin UI');
                    echo json_encode(['ok'=>true]); exit;
                } catch (Exception $e) {
                    admin_log("[assign_role] ".$e->getMessage());
                    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'server']); exit;
                }

            /* reorder_roles */
            case 'reorder_roles':
                $data = getRequestData();
                $order = [];
                if (!empty($data['order']) && is_string($data['order'])) {
                    $try = @json_decode($data['order'], true);
                    if (is_array($try)) $order = $try;
                } elseif (!empty($data['order']) && is_array($data['order'])) $order = $data['order'];
                if (empty($order)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing_order']); exit; }
                // assign high-to-low priorities: n..1
                $n = count($order);
                $pdo->beginTransaction();
                try {
                    for ($i=0;$i<$n;$i++) {
                        $rid = (int)$order[$i];
                        $newPriority = $n - $i;
                        $rQ = $pdo->prepare("SELECT priority FROM community_roles WHERE id = ? AND community_id = ? LIMIT 1"); $rQ->execute([$rid,$community_id]);
                        $rR = $rQ->fetch(PDO::FETCH_ASSOC);
                        if (!$rR) continue;
                        $oldPriority = (int)$rR['priority'];
                        // cannot set a role to >= actorMax unless owner or admin
                        if (!$ownerFlag && $newPriority >= $actorMax && empty($actorPerms['is_admin'])) {
                            throw new Exception("insufficient_permission_to_set_priority_for_role:$rid");
                        }
                        $u = $pdo->prepare("UPDATE community_roles SET priority = ? WHERE id = ? AND community_id = ?");
                        $u->execute([$newPriority, $rid, $community_id]);
                    }
                    $pdo->commit();
                    audit_log($pdo, $community_id, 'reorder_roles', $me_id, null, json_encode(['order'=>$order]), 'reordered roles');
                    echo json_encode(['ok'=>true]); exit;
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    admin_log("[reorder_roles] ".$e->getMessage());
                    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'server','msg'=>$e->getMessage()]); exit;
                }

            /* revoke_timeout */
            case 'revoke_timeout':
            case 'untimeout':
                $target_user_id = isset($_POST['target_user_id']) ? (int)$_POST['target_user_id'] : 0;
                if (!$target_user_id) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing_target']); exit; }
                if (empty($actorPerms['can_timeout']) && empty($actorPerms['is_admin']) && !$ownerFlag) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'no_permission']); exit; }
                try {
                    $pdo->beginTransaction();
                    if (table_exists($pdo,'community_timeouts')) {
                        $del = $pdo->prepare("DELETE FROM community_timeouts WHERE community_id = ? AND user_id = ?");
                        $del->execute([$community_id, $target_user_id]);
                    } else {
                        $up = $pdo->prepare("UPDATE users SET timeout_until = NULL WHERE id = ?");
                        $up->execute([$target_user_id]);
                    }
                    audit_log($pdo, $community_id, 'untimeout', $me_id, $target_user_id, null, 'untimeout by moderator');
                    $pdo->commit();
                    echo json_encode(['ok'=>true]); exit;
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    admin_log("[untimeout] ".$e->getMessage());
                    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'server']); exit;
                }

            /* revoke_ban */
            case 'revoke_ban':
            case 'unban':
                $target_user_id = isset($_POST['target_user_id']) ? (int)$_POST['target_user_id'] : 0;
                if (!$target_user_id) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing_target']); exit; }
                if (empty($actorPerms['can_ban']) && empty($actorPerms['is_admin']) && !$ownerFlag) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'no_permission']); exit; }
                try {
                    $pdo->beginTransaction();
                    $del = $pdo->prepare("DELETE FROM community_bans WHERE community_id = ? AND user_id = ?");
                    $del->execute([$community_id, $target_user_id]);
                    audit_log($pdo, $community_id, 'unban', $me_id, $target_user_id, null, 'unban by moderator');
                    $pdo->commit();
                    echo json_encode(['ok'=>true]); exit;
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    admin_log("[unban] ".$e->getMessage());
                    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'server']); exit;
                }

            /* update_channel_lock */
            case 'update_channel_lock':
                $channel_id = isset($_POST['channel_id']) ? (int)$_POST['channel_id'] : 0;
                $required_role = isset($_POST['required_role_id']) ? (int)$_POST['required_role_id'] : null;
                if (!$channel_id) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing_channel']); exit; }
                if ($required_role) {
                    $rQ = $pdo->prepare("SELECT priority FROM community_roles WHERE id = ? AND community_id = ? LIMIT 1"); $rQ->execute([$required_role,$community_id]); $rR = $rQ->fetch(PDO::FETCH_ASSOC);
                    if ($rR) {
                        $rp = (int)$rR['priority'];
                        if (!$ownerFlag && $rp >= $actorMax && empty($actorPerms['is_admin'])) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'role_too_high']); exit; }
                    }
                }
                try {
                    $u = $pdo->prepare("UPDATE private_rooms SET required_role_id = ? WHERE id = ? AND community_id = ?");
                    $u->execute([$required_role ?: null, $channel_id, $community_id]);
                    audit_log($pdo, $community_id, 'update_channel_lock', $me_id, null, json_encode(['channel_id'=>$channel_id,'required_role'=>$required_role]), 'Updated channel lock');
                    echo json_encode(['ok'=>true]); exit;
                } catch (Exception $e) {
                    admin_log("[update_channel_lock] ".$e->getMessage());
                    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'server']); exit;
                }

            /* delete_channel */
            case 'delete_channel':
                $channel_id = isset($_POST['channel_id']) ? (int)$_POST['channel_id'] : 0;
                if (!$channel_id) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing_channel']); exit; }
                $ch = $pdo->prepare("SELECT id, name FROM private_rooms WHERE id = ? AND community_id = ? LIMIT 1"); $ch->execute([$channel_id,$community_id]);
                $cR = $ch->fetch(PDO::FETCH_ASSOC);
                if (!$cR) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'channel_not_found']); exit; }
                if (empty($actorPerms['can_edit_channels']) && empty($actorPerms['is_admin']) && !$ownerFlag) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'no_permission']); exit; }
                try {
                    $pdo->prepare("DELETE FROM private_rooms WHERE id = ? AND community_id = ?")->execute([$channel_id,$community_id]);
                    audit_log($pdo, $community_id, 'delete_channel', $me_id, null, json_encode(['channel_id'=>$channel_id]), 'Deleted private channel');
                    echo json_encode(['ok'=>true]); exit;
                } catch (Exception $e) {
                    admin_log("[delete_channel] ".$e->getMessage());
                    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'server']); exit;
                }

            /* audit_reverse (conservative) */
            case 'audit_reverse':
                $audit_id = isset($_POST['audit_id']) ? (int)$_POST['audit_id'] : 0;
                if (!$audit_id) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing_audit']); exit; }
                if (empty($actorPerms['is_admin']) && !$ownerFlag) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'no_permission']); exit; }
                $aq = $pdo->prepare("SELECT * FROM community_audit WHERE id = ? AND community_id = ? LIMIT 1"); $aq->execute([$audit_id,$community_id]);
                $a = $aq->fetch(PDO::FETCH_ASSOC);
                if (!$a) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'audit_not_found']); exit; }
                $act = $a['action'];
                $meta = $a['target_message'] ? @json_decode($a['target_message'], true) : null;
                try {
                    $pdo->beginTransaction();
                    if ($act === 'add_role' && !empty($meta['role_id']) && !empty($a['target_user'])) {
                        $pdo->prepare("DELETE FROM community_member_roles WHERE community_id = ? AND user_id = ? AND role_id = ?")->execute([$community_id, $a['target_user'], (int)$meta['role_id']]);
                        audit_log($pdo, $community_id, 'reverse_add_role', $me_id, $a['target_user'], json_encode(['role_id'=>$meta['role_id'],'audit_id'=>$audit_id]), 'Reversed add_role');
                    } elseif ($act === 'ban') {
                        if (!empty($a['target_user'])) {
                            $pdo->prepare("DELETE FROM community_bans WHERE community_id = ? AND user_id = ?")->execute([$community_id,$a['target_user']]);
                            audit_log($pdo, $community_id, 'reverse_ban', $me_id, $a['target_user'], json_encode(['audit_id'=>$audit_id]), 'Reversed ban');
                        }
                    } elseif ($act === 'timeout') {
                        if (!empty($a['target_user'])) {
                            if (table_exists($pdo,'community_timeouts')) {
                                $pdo->prepare("DELETE FROM community_timeouts WHERE community_id = ? AND user_id = ?")->execute([$community_id,$a['target_user']]);
                            } else {
                                $pdo->prepare("UPDATE users SET timeout_until = NULL WHERE id = ?")->execute([$a['target_user']]);
                            }
                            audit_log($pdo, $community_id, 'reverse_timeout', $me_id, $a['target_user'], json_encode(['audit_id'=>$audit_id]), 'Reversed timeout');
                        }
                    } elseif ($act === 'create_role' && !empty($meta['role_id'])) {
                        $rid = (int)$meta['role_id'];
                        $rq = $pdo->prepare("SELECT priority FROM community_roles WHERE id = ? AND community_id = ? LIMIT 1"); $rq->execute([$rid,$community_id]);
                        $rr = $rq->fetch(PDO::FETCH_ASSOC);
                        if ($rr) {
                            $rprio = (int)$rr['priority'];
                            if ($ownerFlag || $rprio < $actorMax) {
                                $pdo->prepare("DELETE FROM community_member_roles WHERE community_id = ? AND role_id = ?")->execute([$community_id,$rid]);
                                $pdo->prepare("DELETE FROM community_roles WHERE id = ? AND community_id = ?")->execute([$rid,$community_id]);
                                audit_log($pdo, $community_id, 'reverse_create_role', $me_id, null, json_encode(['role_id'=>$rid]), 'Reversed create_role');
                            } else throw new Exception("role_too_high_to_remove");
                        }
                    } else {
                        throw new Exception("unsupported_reverse_action");
                    }
                    $pdo->commit();
                    echo json_encode(['ok'=>true]); exit;
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    admin_log("[audit_reverse] ".$e->getMessage());
                    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'server','msg'=>$e->getMessage()]); exit;
                }

            default:
                http_response_code(400); echo json_encode(['ok'=>false,'error'=>'unknown_action','action'=>$action]); exit;
        }

    } catch (Exception $e) {
        admin_log("[action dispatch] ".$e->getMessage());
        http_response_code(500); echo json_encode(['ok'=>false,'error'=>'server','msg'=>$e->getMessage()]);
        exit;
    }
}

/* ---------- fetch lists for UI ---------- */
try {
    $roles_list_stmt = $pdo->prepare("SELECT * FROM community_roles WHERE community_id = ? ORDER BY priority DESC, id ASC");
    $roles_list_stmt->execute([$community_id]); $roles_list = $roles_list_stmt->fetchAll(PDO::FETCH_ASSOC);

    $channels_list_stmt = $pdo->prepare("SELECT id, name, code, required_role_id FROM private_rooms WHERE community_id = ? ORDER BY id ASC");
    $channels_list_stmt->execute([$community_id]); $channels_list = $channels_list_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (table_exists($pdo,'community_member_roles')) {
        $members_stmt = $pdo->prepare("SELECT u.id AS user_id, u.username, GROUP_CONCAT(cr.name ORDER BY cr.priority DESC SEPARATOR ', ') AS roles FROM users u JOIN community_member_roles mr ON mr.user_id = u.id JOIN community_roles cr ON cr.id = mr.role_id WHERE mr.community_id = ? GROUP BY u.id ORDER BY u.username ASC");
        $members_stmt->execute([$community_id]); $members_list = $members_stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $members_list = [];
        if (table_exists($pdo,'community_members')) {
            $ms = $pdo->prepare("SELECT u.id AS user_id, u.username, cr.name AS roles FROM users u JOIN community_members cm ON cm.user_id = u.id LEFT JOIN community_roles cr ON cr.id = cm.role_id WHERE cm.community_id = ? ORDER BY u.username ASC");
            $ms->execute([$community_id]); $members_list = $ms->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    // timeouts
    $timeouts = [];
    if (table_exists($pdo,'community_timeouts')) {
        $qt = $pdo->prepare("SELECT ct.id, ct.user_id, u.username, ct.reason, ct.until_at, ct.created_at, ct.actor_user FROM community_timeouts ct LEFT JOIN users u ON u.id = ct.user_id WHERE ct.community_id = ? AND (ct.until_at IS NULL OR ct.until_at > NOW()) ORDER BY ct.until_at ASC");
        $qt->execute([$community_id]); $timeouts = $qt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $qt = $pdo->prepare("SELECT id AS user_id, username, timeout_until AS until_at FROM users WHERE timeout_until IS NOT NULL AND timeout_until > NOW()");
        $qt->execute([]); $timeouts = $qt->fetchAll(PDO::FETCH_ASSOC);
    }

    // bans
    $bans = [];
    if (table_exists($pdo,'community_bans')) {
        $qb = $pdo->prepare("SELECT id, user_id, username, reason, until_at, created_at, banned_by FROM community_bans WHERE community_id = ? AND (until_at IS NULL OR until_at > NOW()) ORDER BY created_at DESC");
        $qb->execute([$community_id]); $bans = $qb->fetchAll(PDO::FETCH_ASSOC);
    }

    // audit: join actor/target usernames for display
    $audit = [];
    if (table_exists($pdo,'community_audit')) {
        $qa = $pdo->prepare("
            SELECT a.id, a.action, a.actor_user, au.username AS actor_name, a.target_user, tu.username AS target_name, a.target_message, a.reason, a.created_at
            FROM community_audit a
            LEFT JOIN users au ON au.id = a.actor_user
            LEFT JOIN users tu ON tu.id = a.target_user
            WHERE a.community_id = ?
            ORDER BY a.created_at DESC
            LIMIT 200
        ");
        $qa->execute([$community_id]); $audit = $qa->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    admin_log("[fetch lists] ".$e->getMessage());
    $roles_list = $roles_list ?? [];
    $channels_list = $channels_list ?? [];
    $members_list = $members_list ?? [];
    $timeouts = $timeouts ?? [];
    $bans = $bans ?? [];
    $audit = $audit ?? [];
}

/* ---------- UI rendering ---------- */
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Community Admin — <?= htmlspecialchars($community['name']) ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body{background:#071017;color:#eaf0ff;font-family:Inter,Arial,Helvetica,sans-serif;padding:18px}
.header{display:flex;align-items:center;gap:12px;margin-bottom:12px}
.box{background:#081016;padding:12px;border-radius:10px;margin-bottom:12px}
.grid{display:grid;grid-template-columns:1fr 420px;gap:12px}
.small{color:#9aa3b8;font-size:13px}
.btn{background:#2f6bff;color:#fff;padding:8px 10px;border-radius:8px;border:0;cursor:pointer}
.roleRow{padding:8px;border-radius:8px;background:rgba(255,255,255,0.02);margin-bottom:8px;display:flex;justify-content:space-between;align-items:center;user-select:none}
.modal{position:fixed;left:0;top:0;right:0;bottom:0;background:rgba(0,0,0,0.6);display:none;align-items:center;justify-content:center;z-index:9999}
.modal .card{background:#0b0f12;padding:16px;border-radius:10px;width:640px;max-width:95%}
.input, textarea, select{width:100%;padding:8px;border-radius:6px;border:0;background:#061018;color:#fff}
.table{width:100%;border-collapse:collapse}
.table th,.table td{padding:8px;border-bottom:1px solid rgba(255,255,255,0.03);font-size:13px}
.tabbar{display:flex;gap:8px;margin-bottom:12px}
.tab{padding:8px 10px;border-radius:8px;background:#021018;color:#9fbfff;cursor:pointer}
.tab.active{background:#123242;color:#eaf0ff}
.drag-handle{cursor:grab;padding:6px 8px;background:#0f2a3a;border-radius:6px;margin-right:8px}
.roleRow.dragging{opacity:0.5}
.rolePlaceholder{height:48px;border-radius:8px;border:2px dashed rgba(255,255,255,0.06);margin-bottom:8px}
.pill{padding:6px 8px;border-radius:8px;background:#123242;color:#bfe1ff;font-weight:700}
.badge{font-size:12px;color:#9aa3b8}
.right{margin-left:auto}
.disabled{opacity:0.45;pointer-events:none}
.color-input-wrap{display:flex;gap:8px;align-items:center}
.color-hex{width:110px}
</style>
</head>
<body>
<div class="header">
  <h1 style="margin:0"><?= htmlspecialchars($community['name']) ?> — Admin</h1>
  <div style="margin-left:auto">
    <a href="community.php?public_id=<?= rawurlencode($public_id) ?>" class="btn">Back</a>
  </div>
</div>

<div class="tabbar">
  <div class="tab active" data-tab="roles">Roles</div>
  <div class="tab" data-tab="timeouts">Timeouts (<?= count($timeouts) ?>)</div>
  <div class="tab" data-tab="bans">Bans (<?= count($bans) ?>)</div>
  <div class="tab" data-tab="audit">Audit</div>
  <div class="tab" data-tab="channels">Channels</div>
</div>

<!-- Roles tab -->
<div id="tab-roles" class="admin-tab">
<div class="grid">
  <div>
    <div class="box">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <h3 style="margin:0">Roles</h3>
        <div>
          <button class="btn" id="createRoleBtn">Create role</button>
          <button class="btn" id="saveOrderBtn">Save order</button>
        </div>
      </div>
      <div style="margin-top:12px" id="rolesContainer">
        <div id="roleListInner">
          <?php foreach ($roles_list as $r): 
            $modifiable = ($isOwner || !empty($actorPermissions['is_admin']) || ((int)$r['priority'] < $actorMaxPriority));
            ?>
            <div class="roleRow" data-role='<?= json_encode($r, JSON_HEX_APOS|JSON_HEX_QUOT) ?>' data-role-id="<?= (int)$r['id'] ?>" data-role-priority="<?= (int)$r['priority'] ?>" <?= $modifiable ? 'draggable="true"' : '' ?> <?= $modifiable ? '' : 'data-modifiable="0"' ?>>
              <div style="display:flex;align-items:center">
                <div class="drag-handle" title="Drag to reorder">☰</div>
                <div>
                  <div style="font-weight:700"><?= htmlspecialchars($r['name']) ?> <span class="badge"> (pri <?= (int)$r['priority'] ?>)</span></div>
                  <div class="small">badge <?= htmlspecialchars($r['badge']) ?> • color <?= htmlspecialchars($r['color']) ?><?php if (!empty($r['is_owner'])): ?> • <span class="pill">owner role</span><?php endif; ?></div>
                </div>
              </div>
              <div style="display:flex;gap:8px;align-items:center">
                <button class="btn editRoleBtn" <?= $modifiable ? '' : 'disabled title="Insufficient priority to edit"' ?>>Edit</button>
                <form method="POST" style="display:inline">
                  <input type="hidden" name="action" value="assign_role">
                  <input type="hidden" name="role_id" value="<?= (int)$r['id'] ?>">
                  <input name="username" class="input" placeholder="username" style="width:140px" />
                  <button class="btn" type="submit">Assign</button>
                </form>
                <button class="btn deleteRoleBtn" data-role-id="<?= (int)$r['id'] ?>" <?= $modifiable ? '' : 'disabled title="Insufficient priority to delete"' ?>>Delete</button>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="box">
      <h3 style="margin-top:0">Members</h3>
      <table class="table">
        <thead><tr><th>User</th><th>Roles</th></tr></thead>
        <tbody>
        <?php foreach ($members_list as $m): ?>
          <tr><td><?= htmlspecialchars($m['username']) ?></td><td><?= htmlspecialchars($m['roles'] ?: '') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <aside>
    <div class="box">
      <h3>Quick role actions</h3>
      <div class="small">You can only set permissions you already have. Owner-only controls are shown only to the owner.</div>
      <div style="margin-top:12px">
        <a class="btn" id="openCreateRole">New role</a>
      </div>
    </div>

    <div class="box">
      <h3>Recent Audit</h3>
      <div class="small">Latest moderation actions (most recent)</div>
      <div style="margin-top:8px">
        <table class="table">
          <thead><tr><th>When</th><th>Action</th><th>Target</th><th>By</th><th></th></tr></thead>
          <tbody id="auditPreview">
            <?php foreach (array_slice($audit,0,8) as $a): ?>
              <tr>
                <td class="small"><?= htmlspecialchars($a['created_at']) ?></td>
                <td><?= htmlspecialchars($a['action']) ?></td>
                <td class="small"><?= htmlspecialchars($a['target_name'] ?: $a['target_user'] ?: '') ?></td>
                <td class="small"><?= htmlspecialchars($a['actor_name'] ?: $a['actor_user'] ?: '') ?></td>
                <td><?php if ($actorPermissions['is_admin'] || $isOwner): ?><button class="btn auditReverseBtn" data-audit-id="<?= (int)$a['id'] ?>">Reverse</button><?php endif; ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div style="margin-top:8px"><a class="btn" href="community_admin.php?public_id=<?= rawurlencode($public_id) ?>&view=audit">Open full audit</a></div>
      </div>
    </div>
  </aside>
</div>
</div>

<!-- Timeouts tab -->
<div id="tab-timeouts" class="admin-tab" style="display:none">
  <div class="box">
    <h3>Active Timeouts</h3>
    <table class="table">
      <thead><tr><th>User</th><th>Until</th><th>Reason</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach ($timeouts as $t): ?>
        <tr>
          <td><?= htmlspecialchars($t['username'] ?? ('id '.$t['user_id'])) ?></td>
          <td><?= htmlspecialchars($t['until_at'] ?? '') ?></td>
          <td class="small"><?= htmlspecialchars($t['reason'] ?? '') ?></td>
          <td>
            <?php if ($actorPermissions['can_timeout'] || $actorPermissions['is_admin']): ?>
              <button class="btn untimeoutBtn" data-user-id="<?= (int)$t['user_id'] ?>">Revoke</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Bans tab -->
<div id="tab-bans" class="admin-tab" style="display:none">
  <div class="box">
    <h3>Active Bans</h3>
    <table class="table">
      <thead><tr><th>User</th><th>Until</th><th>Reason</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach ($bans as $b): ?>
        <tr>
          <td><?= htmlspecialchars($b['username'] ?: 'id '.$b['user_id']) ?></td>
          <td><?= htmlspecialchars($b['until_at'] ?? 'Permanent') ?></td>
          <td class="small"><?= htmlspecialchars($b['reason'] ?? '') ?></td>
          <td><?php if ($actorPermissions['can_ban'] || $actorPermissions['is_admin']): ?><button class="btn unbanBtn" data-user-id="<?= (int)$b['user_id'] ?>">Unban</button><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Audit tab -->
<div id="tab-audit" class="admin-tab" style="display:none">
  <div class="box">
    <h3>Audit Log</h3>
    <table class="table">
      <thead><tr><th>When</th><th>Action</th><th>Target</th><th>By</th><th>Meta</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($audit as $a): ?>
        <tr>
          <td class="small"><?= htmlspecialchars($a['created_at']) ?></td>
          <td><?= htmlspecialchars($a['action']) ?></td>
          <td class="small"><?= htmlspecialchars($a['target_name'] ?: $a['target_user'] ?: '') ?></td>
          <td class="small"><?= htmlspecialchars($a['actor_name'] ?: $a['actor_user'] ?: '') ?></td>
          <td class="small"><?= htmlspecialchars(substr($a['target_message'] ?: $a['reason'] ?: '',0,160)) ?></td>
          <td><?php if ($actorPermissions['is_admin'] || $isOwner): ?><button class="btn auditReverseBtn" data-audit-id="<?= (int)$a['id'] ?>">Reverse</button><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Channels tab -->
<div id="tab-channels" class="admin-tab" style="display:none">
  <div class="box">
    <h3>Private Channels</h3>
    <table class="table">
      <thead><tr><th>Channel</th><th>Required Role</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach ($channels_list as $c): ?>
        <tr>
          <td><?= htmlspecialchars($c['name']) ?> <div class="small">code <?= htmlspecialchars($c['code']) ?></div></td>
          <td>
            <form class="channelLockForm" method="POST" onsubmit="return false;" data-channel-id="<?= (int)$c['id'] ?>">
              <input type="hidden" name="action" value="update_channel_lock">
              <input type="hidden" name="channel_id" value="<?= (int)$c['id'] ?>">
              <select name="required_role_id" class="input lockSelect">
                <option value="">Public</option>
                <?php foreach ($roles_list as $r): ?>
                  <option value="<?= (int)$r['id'] ?>" <?= ((int)$c['required_role_id'] === (int)$r['id']) ? 'selected' : '' ?>><?= htmlspecialchars($r['name']) ?> (pri <?= (int)$r['priority'] ?>)</option>
                <?php endforeach; ?>
              </select>
            </form>
          </td>
          <td>
            <?php if ($actorPermissions['can_edit_channels'] || $actorPermissions['is_admin']): ?>
              <button class="btn saveChannelLockBtn" data-channel-id="<?= (int)$c['id'] ?>">Save</button>
              <button class="btn deleteChannelBtn" data-channel-id="<?= (int)$c['id'] ?>">Delete</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Role editor modal (with color wheel) -->
<div id="roleModal" class="modal" role="dialog" aria-hidden="true">
  <div class="card">
    <h3 id="roleModalTitle">Create role</h3>
    <form id="roleForm" method="POST">
      <input type="hidden" name="action" id="roleFormAction" value="create_role">
      <input type="hidden" name="role_id" id="roleFormRoleId" value="0">
      <div class="field">
        <label class="small">Name</label>
        <input name="name" id="role_name" class="input" required>
      </div>
      <div class="field">
        <label class="small">Color</label>
        <div class="color-input-wrap">
          <input type="color" id="role_color_picker" value="#cccccc" title="Color wheel">
          <input name="color" id="role_color" class="input color-hex" placeholder="#aabbcc">
        </div>
      </div>
      <div class="field">
        <label class="small">Badge (short)</label>
        <input name="badge" id="role_badge" class="input" placeholder="MOD">
      </div>
      <div class="field">
        <label class="small">Priority (higher = more authority)</label>
        <input type="number" name="priority" id="role_priority" class="input" value="0" />
        <div class="small">You cannot create or edit a role to have priority greater than your highest role.</div>
      </div>

      <div class="field">
        <label class="small">Permissions</label>
        <div style="display:flex;gap:12px;flex-wrap:wrap">
          <label><input type="checkbox" name="is_admin" id="perm_is_admin"> Admin</label>
          <label><input type="checkbox" name="can_delete" id="perm_can_delete"> Delete messages</label>
          <label><input type="checkbox" name="can_timeout" id="perm_can_timeout"> Timeout</label>
          <label><input type="checkbox" name="can_ban" id="perm_can_ban"> Ban</label>
          <label><input type="checkbox" name="can_edit" id="perm_can_edit"> Edit channels</label>
          <label><input type="checkbox" name="can_view_locked" id="perm_can_view_locked"> View locked channels</label>
          <label><input type="checkbox" name="can_assign_roles" id="perm_can_assign_roles"> Assign roles</label>
          <?php if ($isOwner): ?>
            <label><input type="checkbox" name="is_owner" id="perm_is_owner"> Mark role as owner</label>
          <?php endif; ?>
        </div>
      </div>

      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
        <button type="button" class="btn" id="roleSaveBtn">Save</button>
        <button type="button" class="btn" id="roleCancelBtn" style="background:#444">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
(function(){
  // expose actorMax and permissions to JS for client-side protections
  const actorMax = <?= json_encode($actorMaxPriority) ?>;
  const actorIsOwner = <?= $isOwner ? 'true' : 'false' ?>;
  const actorIsAdmin = <?= (!empty($actorPermissions['is_admin']) ? 'true' : 'false') ?>;

  // tab switching
  document.querySelectorAll('.tab').forEach(t => t.addEventListener('click', function(){
    document.querySelectorAll('.tab').forEach(x=>x.classList.remove('active'));
    this.classList.add('active');
    const tab = this.dataset.tab;
    document.querySelectorAll('.admin-tab').forEach(x=>x.style.display='none');
    document.getElementById('tab-'+tab).style.display = 'block';
  }));

  // modal handling & color wheel sync
  const modal = document.getElementById('roleModal');
  const roleFormAction = document.getElementById('roleFormAction');
  const roleFormRoleId = document.getElementById('roleFormRoleId');
  const colorPicker = document.getElementById('role_color_picker');
  const colorHex = document.getElementById('role_color');

  const fields = {
    name: document.getElementById('role_name'),
    colorPicker: colorPicker,
    colorHex: colorHex,
    badge: document.getElementById('role_badge'),
    priority: document.getElementById('role_priority'),
    is_admin: document.getElementById('perm_is_admin'),
    can_delete: document.getElementById('perm_can_delete'),
    can_timeout: document.getElementById('perm_can_timeout'),
    can_ban: document.getElementById('perm_can_ban'),
    can_edit: document.getElementById('perm_can_edit'),
    can_view_locked: document.getElementById('perm_can_view_locked'),
    can_assign_roles: document.getElementById('perm_can_assign_roles'),
    is_owner: document.getElementById('perm_is_owner')
  };

  function openModal(mode, roleData) {
    roleFormAction.value = (mode === 'edit') ? 'update_role' : 'create_role';
    document.getElementById('roleModalTitle').textContent = (mode === 'edit') ? 'Edit role' : 'Create role';
    roleFormRoleId.value = roleData && roleData.id ? roleData.id : 0;
    fields.name.value = roleData ? (roleData.name || '') : '';
    fields.colorHex.value = roleData ? (roleData.color || '#cccccc') : '#cccccc';
    fields.colorPicker.value = fields.colorHex.value || '#cccccc';
    fields.badge.value = roleData ? (roleData.badge || '') : '';
    fields.priority.value = roleData ? (roleData.priority || 0) : 0;
    fields.is_admin.checked = roleData ? !!Number(roleData.is_admin) : false;
    fields.can_delete.checked = roleData ? !!Number(roleData.can_delete_messages) : false;
    fields.can_timeout.checked = roleData ? !!Number(roleData.can_timeout) : false;
    fields.can_ban.checked = roleData ? !!Number(roleData.can_ban) : false;
    fields.can_edit.checked = roleData ? !!Number(roleData.can_edit_channels) : false;
    fields.can_view_locked.checked = roleData ? !!Number(roleData.can_view_locked) : false;
    fields.can_assign_roles.checked = roleData ? !!Number(roleData.can_assign_roles) : false;
    if (fields.is_owner) fields.is_owner.checked = roleData ? !!Number(roleData.is_owner) : false;
    modal.style.display = 'flex';
  }
  function closeModal(){ modal.style.display='none'; }

  colorPicker.addEventListener('input', ()=> { colorHex.value = colorPicker.value; });
  colorHex.addEventListener('input', ()=> {
    let v = colorHex.value.trim();
    if (!v.startsWith('#') && /^[0-9a-f]{3,6}$/i.test(v)) v = '#'+v;
    if (/^#[0-9a-f]{6}$/i.test(v)) colorPicker.value = v;
  });

  document.getElementById('createRoleBtn').addEventListener('click', ()=> openModal('create', null));
  document.getElementById('openCreateRole').addEventListener('click', ()=> openModal('create', null));
  document.getElementById('roleCancelBtn').addEventListener('click', closeModal);

  // wire edit buttons
  document.querySelectorAll('.editRoleBtn').forEach(bt => bt.addEventListener('click', (ev) => {
    const roleEl = ev.target.closest('.roleRow');
    if (!roleEl) return;
    const data = JSON.parse(roleEl.getAttribute('data-role'));
    // if not modifiable, show notice
    if (roleEl.dataset.modifiable === "0") { alert('You do not have sufficient priority to edit this role.'); return; }
    openModal('edit', data);
  }));

  // delete role
  document.querySelectorAll('.deleteRoleBtn').forEach(bt => bt.addEventListener('click', async (ev) => {
    const rid = bt.dataset.roleId;
    const row = bt.closest('.roleRow');
    if (row && row.dataset.modifiable === "0") { alert('Insufficient priority to delete that role.'); return; }
    if (!confirm('Delete this role? This will remove the role from all members.')) return;
    try {
      const fd = new FormData(); fd.append('action','delete_role'); fd.append('role_id', rid);
      const res = await fetch('', { method:'POST', body:fd, credentials:'same-origin' });
      const j = await res.json();
      if (j.ok) location.reload(); else alert('Delete failed: '+(j.error||j.msg||'unknown'));
    } catch (e) { alert('Delete failed'); }
  }));

  // save role (form)
  document.getElementById('roleSaveBtn').addEventListener('click', async () => {
    if (!fields.name.value.trim()) { alert('Name required'); return; }
    const fd = new FormData();
    fd.append('action', roleFormAction.value);
    if (roleFormRoleId.value && roleFormRoleId.value !== '0') fd.append('role_id', roleFormRoleId.value);
    fd.append('name', fields.name.value.trim());
    fd.append('color', fields.colorHex.value.trim());
    fd.append('badge', fields.badge.value.trim());
    fd.append('priority', fields.priority.value);
    if (fields.is_admin.checked) fd.append('is_admin','1');
    if (fields.can_delete.checked) fd.append('can_delete','1');
    if (fields.can_timeout.checked) fd.append('can_timeout','1');
    if (fields.can_ban.checked) fd.append('can_ban','1');
    if (fields.can_edit.checked) fd.append('can_edit','1');
    if (fields.can_view_locked.checked) fd.append('can_view_locked','1');
    if (fields.can_assign_roles && fields.can_assign_roles.checked) fd.append('can_assign_roles','1');
    if (fields.is_owner && fields.is_owner.checked) fd.append('is_owner','1');
    try {
      const res = await fetch('', { method:'POST', body:fd, credentials:'same-origin' });
      const j = await res.json();
      if (j.ok) location.reload(); else alert('Save failed: '+(j.error||j.msg||'unknown'));
    } catch (e) { alert('Save failed'); }
  });

  // improved drag/drop with placeholder and client-side protection
  const list = document.getElementById('roleListInner');
  let dragEl = null;
  let placeholder = document.createElement('div');
  placeholder.className = 'rolePlaceholder';

  function isRowModifiable(row) {
    if (!row) return false;
    if (row.dataset.modifiable === "0") return false;
    return true;
  }

  function onDragStart(e) {
    dragEl = e.currentTarget;
    if (!isRowModifiable(dragEl)) { e.preventDefault(); return; }
    dragEl.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    try { e.dataTransfer.setData('text/plain', dragEl.dataset.roleId); } catch (e) {}
  }
  function onDragEnd(e) {
    if (dragEl) dragEl.classList.remove('dragging');
    placeholder.remove();
    dragEl = null;
  }
  function onDragOver(e) {
    e.preventDefault();
    const target = e.target.closest('.roleRow');
    if (!target || target === dragEl) {
      // if not over a row, append placeholder at end
      if (!list.contains(placeholder)) list.appendChild(placeholder);
      return;
    }
    // do not allow dragging another user's protected row *above* a protected row
    const targetRect = target.getBoundingClientRect();
    const insertBefore = (e.clientY < (targetRect.top + targetRect.height/2));
    if (insertBefore) target.parentNode.insertBefore(placeholder, target);
    else target.parentNode.insertBefore(placeholder, target.nextSibling);
  }
  function onDrop(e) {
    e.preventDefault();
    if (!dragEl) return;
    // compute new order array and client-side validate: actor cannot set a priority above or equal to actorMax for rows he cannot modify
    const rows = Array.from(list.querySelectorAll('.roleRow')).filter(r => r !== dragEl);
    // placeholder is in place where dragEl should go
    rows.splice(Array.prototype.indexOf.call(Array.from(list.children), placeholder), 0, dragEl);
    // compute the resulting priorities (n..1)
    const orderIds = rows.map(r => parseInt(r.dataset.roleId,10));
    const priorities = rows.map((r, i) => ({ id: r.dataset.roleId, newPri: rows.length - i, currentPri: parseInt(r.dataset.rolePriority || '0',10), modifiable: (r.dataset.modifiable !== "0") }));
    // check if any role is being assigned newPriority >= actorMax but actor cannot set it
    if (!actorIsOwner && !actorIsAdmin) {
        for (const p of priorities) {
            if (p.newPri >= actorMax && p.currentPri < p.newPri) {
                // new high priority would be >= actorMax — only allowed if actor can change that role (i.e., role is currently lower than actorMax? still block)
                // simpler policy: do not allow any resulting priority >= actorMax unless actor is owner/admin
                alert('You are not allowed to move roles into or above your own highest priority. Operation cancelled.');
                placeholder.remove();
                dragEl.classList.remove('dragging');
                dragEl = null;
                return;
            }
        }
    }
    // all good: replace placeholder with dragEl
    placeholder.parentNode.replaceChild(dragEl, placeholder);
    dragEl.classList.remove('dragging');
  }

  // attach drag listeners to modifiable rows only
  function attachDragHandlers() {
    document.querySelectorAll('.roleRow').forEach(row => {
      row.removeEventListener('dragstart', onDragStart);
      row.removeEventListener('dragend', onDragEnd);
      row.removeEventListener('dragover', onDragOver);
      row.removeEventListener('drop', onDrop);
      if (isRowModifiable(row)) {
        row.addEventListener('dragstart', onDragStart);
        row.addEventListener('dragend', onDragEnd);
        row.addEventListener('dragover', onDragOver);
        row.addEventListener('drop', onDrop);
        row.setAttribute('draggable','true');
      } else {
        row.removeAttribute('draggable');
      }
    });
  }
  attachDragHandlers();

  // save order
  document.getElementById('saveOrderBtn').addEventListener('click', async () => {
    const rows = Array.from(document.querySelectorAll('.roleRow'));
    const order = rows.map(r => r.dataset.roleId);
    if (!confirm('Save new role order?')) return;
    try {
      const fd = new FormData(); fd.append('action','reorder_roles'); fd.append('order', JSON.stringify(order));
      const res = await fetch('', { method:'POST', body:fd, credentials:'same-origin' });
      const j = await res.json();
      if (j.ok) location.reload(); else alert('Save order failed: '+(j.error||j.msg||'unknown'));
    } catch (e) { alert('Save order failed'); }
  });

  // untimeout/unban buttons
  document.querySelectorAll('.untimeoutBtn').forEach(b => b.addEventListener('click', async () => {
    const uid = b.dataset.userId;
    if (!confirm('Revoke timeout for user?')) return;
    try {
      const fd = new FormData(); fd.append('action','revoke_timeout'); fd.append('target_user_id', uid);
      const res = await fetch('', { method:'POST', body:fd, credentials:'same-origin' }); const j = await res.json();
      if (j.ok) location.reload(); else alert('Failed: '+(j.error||j.msg));
    } catch (e) { alert('Failed'); }
  }));

  document.querySelectorAll('.unbanBtn').forEach(b => b.addEventListener('click', async () => {
    const uid = b.dataset.userId;
    if (!confirm('Unban user?')) return;
    try {
      const fd = new FormData(); fd.append('action','revoke_ban'); fd.append('target_user_id', uid);
      const res = await fetch('', { method:'POST', body:fd, credentials:'same-origin' }); const j = await res.json();
      if (j.ok) location.reload(); else alert('Failed: '+(j.error||j.msg));
    } catch (e) { alert('Failed'); }
  }));

  // channel lock save/delete
  document.querySelectorAll('.saveChannelLockBtn').forEach(bt=>bt.addEventListener('click', async (ev)=>{
    const ch = bt.dataset.channelId;
    const form = document.querySelector('.channelLockForm[data-channel-id="'+ch+'"]');
    if (!form) return;
    const sel = form.querySelector('select[name=required_role_id]');
    const val = sel.value;
    try {
      const fd = new FormData(); fd.append('action','update_channel_lock'); fd.append('channel_id', ch); fd.append('required_role_id', val);
      const res = await fetch('', { method:'POST', body:fd, credentials:'same-origin' }); const j = await res.json();
      if (j.ok) location.reload(); else alert('Failed: '+(j.error||j.msg));
    } catch (e) { alert('Failed'); }
  }));

  document.querySelectorAll('.deleteChannelBtn').forEach(bt=>bt.addEventListener('click', async (ev)=>{
    if (!confirm('Delete this channel?')) return;
    const ch = bt.dataset.channelId;
    try {
      const fd = new FormData(); fd.append('action','delete_channel'); fd.append('channel_id', ch);
      const res = await fetch('', { method:'POST', body:fd, credentials:'same-origin' }); const j = await res.json();
      if (j.ok) location.reload(); else alert('Failed: '+(j.error||j.msg));
    } catch (e) { alert('Failed'); }
  }));

  // audit reversal
  document.querySelectorAll('.auditReverseBtn').forEach(bt=>bt.addEventListener('click', async (ev)=>{
    const aid = bt.dataset.auditId;
    if (!confirm('Attempt to reverse this audit action? This will perform safe reversal where supported.')) return;
    try {
      const fd = new FormData(); fd.append('action','audit_reverse'); fd.append('audit_id', aid);
      const res = await fetch('', { method:'POST', body:fd, credentials:'same-origin' }); const j = await res.json();
      if (j.ok) location.reload(); else alert('Reverse failed: '+(j.error||j.msg||'unknown'));
    } catch (e) { alert('Reverse failed'); }
  }));

  // close modal when clicking backdrop
  modal.addEventListener('click', (e)=>{ if (e.target === modal) closeModal(); });

})();
</script>

</body>
</html>
