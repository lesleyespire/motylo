<?php
// community.php - view a community, list its channels, and open them (loads private.php / private_voice.php in iframe)
// Updated for mobile-friendly voice indicators, live voice counts, per-channel voice codes, and incoming call overlay.

require "config.php";

/* ----------------------------
   auth
---------------------------- */
if (empty($_COOKIE['auth_token'])) { header("Location:index.php"); exit; }
$stmt = $pdo->prepare("SELECT id, username, avatar FROM users WHERE auth_token = ?");
$stmt->execute([$_COOKIE['auth_token']]);
$me = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$me) { header("Location:index.php"); exit; }
$me_id = (int)$me['id'];
$me_username = $me['username'];

/* ----------------------------
   identify community
---------------------------- */
$public_id = trim((string)($_GET['public_id'] ?? ''));
$internal_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$community = null;
if ($public_id !== '') {
    $st = $pdo->prepare("SELECT * FROM communities WHERE public_id = ? LIMIT 1");
    $st->execute([$public_id]);
    $community = $st->fetch(PDO::FETCH_ASSOC);
} elseif ($internal_id > 0) {
    $st = $pdo->prepare("SELECT * FROM communities WHERE id = ? LIMIT 1");
    $st->execute([$internal_id]);
    $community = $st->fetch(PDO::FETCH_ASSOC);
}
if (!$community) { die("Community not found."); }
$community_id = (int)$community['id'];

/* ----------------------------
   helpers
---------------------------- */
function e($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function ensure_voice_presence_table(PDO $pdo): void {
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

function cleanup_voice_presence(PDO $pdo, int $olderThanSeconds = 45): void {
    try {
        $pdo->exec("DELETE FROM voice_room_presence WHERE last_seen < (NOW() - INTERVAL " . (int)$olderThanSeconds . " SECOND)");
    } catch (Exception $e) {
        // ignore
    }
}

function get_voice_counts(PDO $pdo, array $roomCodes): array {
    ensure_voice_presence_table($pdo);
    cleanup_voice_presence($pdo, 45);

    $counts = [];
    $roomCodes = array_values(array_filter(array_map('strval', $roomCodes), fn($v) => $v !== ''));
    if (empty($roomCodes)) return $counts;

    $placeholders = implode(',', array_fill(0, count($roomCodes), '?'));
    try {
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

function is_comm_admin_local(PDO $pdo, int $community_id, int $user_id): bool {
    $s = $pdo->prepare("SELECT owner_id FROM communities WHERE id = ? LIMIT 1");
    $s->execute([$community_id]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r) return false;
    if ((int)$r['owner_id'] === (int)$user_id) return true;

    $hasTable = (bool)$pdo->query("SHOW TABLES LIKE 'community_member_roles'")->fetchColumn();
    if ($hasTable) {
        $q = $pdo->prepare("SELECT 1
                            FROM community_member_roles mr
                            JOIN community_roles cr ON cr.id = mr.role_id
                            WHERE mr.community_id = ? AND mr.user_id = ? AND cr.is_admin = 1
                            LIMIT 1");
        $q->execute([$community_id, $user_id]);
        if ($q->fetchColumn()) return true;
    } else {
        $s2 = $pdo->prepare("SELECT cr.is_admin
                             FROM community_members cm
                             JOIN community_roles cr ON cr.id = cm.role_id
                             WHERE cm.community_id = ? AND cm.user_id = ?
                             LIMIT 1");
        $s2->execute([$community_id, $user_id]);
        return (bool)$s2->fetchColumn();
    }
    return false;
}

function user_can_view_channel($channel_required_role_id, $me_id, $community_id, PDO $pdo, $is_owner, $is_admin): bool {
    if ($channel_required_role_id === null || $channel_required_role_id === 0) return true;
    if ($is_owner) return true;
    if ($is_admin) return true;

    try {
        $hasTable = (bool)$pdo->query("SHOW TABLES LIKE 'community_member_roles'")->fetchColumn();
        if ($hasTable) {
            $q = $pdo->prepare("SELECT 1
                                FROM community_member_roles mr
                                JOIN community_roles cr ON cr.id = mr.role_id
                                WHERE mr.community_id = ? AND mr.user_id = ? AND cr.can_view_locked = 1
                                LIMIT 1");
            $q->execute([$community_id, $me_id]);
            if ($q->fetchColumn()) return true;

            $q2 = $pdo->prepare("SELECT 1
                                 FROM community_member_roles
                                 WHERE community_id = ? AND user_id = ? AND role_id = ?
                                 LIMIT 1");
            $q2->execute([$community_id, $me_id, (int)$channel_required_role_id]);
            if ($q2->fetchColumn()) return true;
        } else {
            $q = $pdo->prepare("SELECT cr.can_view_locked, cm.role_id
                                FROM community_members cm
                                LEFT JOIN community_roles cr ON cr.id = cm.role_id
                                WHERE cm.community_id = ? AND cm.user_id = ?
                                LIMIT 1");
            $q->execute([$community_id, $me_id]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
            if ($r && (!empty($r['can_view_locked']) || (int)$r['role_id'] === (int)$channel_required_role_id)) return true;
        }
    } catch (Exception $e) {}
    return false;
}

/* ----------------------------
   Ensure private_rooms schema
---------------------------- */
try {
    $cols = $pdo->query("SHOW COLUMNS FROM private_rooms")->fetchAll(PDO::FETCH_COLUMN);
    $cols_l = array_map('strtolower', $cols);
    if (!in_array('community_id', $cols_l)) {
        $pdo->exec("ALTER TABLE private_rooms ADD COLUMN community_id INT DEFAULT NULL");
    }
    if (!in_array('required_role_id', $cols_l)) {
        $pdo->exec("ALTER TABLE private_rooms ADD COLUMN required_role_id INT DEFAULT NULL");
    }
    if (!in_array('is_hidden', $cols_l)) {
        $pdo->exec("ALTER TABLE private_rooms ADD COLUMN is_hidden TINYINT(1) NOT NULL DEFAULT 0");
    }
    if (!in_array('is_voice', $cols_l)) {
        $pdo->exec("ALTER TABLE private_rooms ADD COLUMN is_voice TINYINT(1) NOT NULL DEFAULT 0");
    }
} catch (Exception $e) { /* ignore */ }

/* ----------------------------
   Ensure general room exists
---------------------------- */
try {
    $s = $pdo->prepare("SELECT id, code, name FROM private_rooms WHERE community_id = ? AND name = 'general' LIMIT 1");
    $s->execute([$community_id]);
    $general = $s->fetch(PDO::FETCH_ASSOC);
    if (!$general) {
        $code = 'c' . substr(md5($community['public_id'] . time() . random_int(1,99999)), 0, 10);
        $ins = $pdo->prepare("INSERT INTO private_rooms (code, name, community_id) VALUES (?, 'general', ?)");
        $ins->execute([$code, $community_id]);
        $general = ['id' => (int)$pdo->lastInsertId(), 'code' => $code, 'name' => 'general'];
    }
} catch (Exception $e) {
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
        $s = $pdo->prepare("SELECT id, code, name FROM private_rooms WHERE community_id = ? AND name = 'general' LIMIT 1");
        $s->execute([$community_id]);
        $general = $s->fetch(PDO::FETCH_ASSOC);
        if (!$general) {
            $code = 'c' . substr(md5($community['public_id'] . time() . random_int(1,99999)), 0, 10);
            $ins = $pdo->prepare("INSERT INTO private_rooms (code, name, community_id) VALUES (?, 'general', ?)");
            $ins->execute([$code, $community_id]);
            $general = ['id' => (int)$pdo->lastInsertId(), 'code' => $code, 'name' => 'general'];
        }
    } catch (Exception $ex) {
        die("Failed to ensure general room: " . htmlspecialchars($ex->getMessage()));
    }
}

/* ----------------------------
   Load channels
---------------------------- */
$channels = [];
try {
    $s = $pdo->prepare("SELECT id, code, name, required_role_id, is_hidden, is_voice FROM private_rooms WHERE community_id = ? ORDER BY id ASC");
    $s->execute([$community_id]);
    $channels = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $channels = []; }

$regular_channels = [];
$hidden_channels = [];
$voice_channels = [];
foreach ($channels as $ch) {
    if (!empty($ch['is_hidden'])) {
        $hidden_channels[] = $ch;
    } elseif (!empty($ch['is_voice'])) {
        $voice_channels[] = $ch;
    } else {
        $regular_channels[] = $ch;
    }
}

/* ----------------------------
   Roles
---------------------------- */
$roles = [];
$roleMap = [];
try {
    $rc = $pdo->query("SHOW TABLES LIKE 'community_roles'")->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($rc)) {
        $rstmt = $pdo->prepare("SELECT id, name, color, badge, is_admin, can_view_locked FROM community_roles WHERE community_id = ? ORDER BY id ASC");
        $rstmt->execute([$community_id]);
        $roles = $rstmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($roles as $rr) $roleMap[(int)$rr['id']] = $rr;
    }
} catch (Exception $e) { $roles = []; $roleMap = []; }

/* ----------------------------
   User roles
---------------------------- */
$user_roles_for_me = [];
try {
    $mr = $pdo->query("SHOW TABLES LIKE 'community_member_roles'")->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($mr)) {
        $mstmt = $pdo->prepare("SELECT role_id FROM community_member_roles WHERE community_id = ? AND user_id = ?");
        $mstmt->execute([$community_id, $me_id]);
        $rows = $mstmt->fetchAll(PDO::FETCH_COLUMN);
        $user_roles_for_me = array_map('intval', $rows ?: []);
    } else {
        $mstmt = $pdo->prepare("SELECT role_id FROM community_members WHERE community_id = ? AND user_id = ? LIMIT 1");
        $mstmt->execute([$community_id, $me_id]);
        $rid = $mstmt->fetchColumn();
        if ($rid) $user_roles_for_me = [(int)$rid];
    }
} catch (Exception $e) { $user_roles_for_me = []; }

/* ----------------------------
   Permissions
---------------------------- */
$is_admin = is_comm_admin_local($pdo, $community_id, $me_id);
$is_owner = ((int)$community['owner_id'] === $me_id);

$can_timeout = false;
$can_ban = false;
$can_manage_roles = $is_admin || $is_owner;
try {
    if ($is_owner) {
        $can_timeout = true;
        $can_ban = true;
    } else {
        $hasMany = (bool)$pdo->query("SHOW TABLES LIKE 'community_member_roles'")->fetchColumn();
        if ($hasMany) {
            $p = $pdo->prepare("SELECT MAX(cr.can_timeout) AS can_timeout, MAX(cr.can_ban) AS can_ban, MAX(cr.is_admin) AS is_admin
                                FROM community_member_roles mr
                                JOIN community_roles cr ON cr.id = mr.role_id
                                WHERE mr.community_id = ? AND mr.user_id = ?");
            $p->execute([$community_id, $me_id]);
            $row = $p->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $can_timeout = !empty($row['can_timeout']);
                $can_ban = !empty($row['can_ban']);
                if (!empty($row['is_admin'])) $can_manage_roles = true;
            }
        } else {
            $p = $pdo->prepare("SELECT cr.can_timeout, cr.can_ban, cr.is_admin
                                FROM community_members cm JOIN community_roles cr ON cr.id = cm.role_id
                                WHERE cm.community_id = ? AND cm.user_id = ? LIMIT 1");
            $p->execute([$community_id, $me_id]);
            $row = $p->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $can_timeout = !empty($row['can_timeout']);
                $can_ban = !empty($row['can_ban']);
                if (!empty($row['is_admin'])) $can_manage_roles = true;
            }
        }
    }
} catch (Exception $e) { /* ignore */ }

/* ----------------------------
   Accessible / selected rooms
---------------------------- */
$accessible_codes = [];
$selected_code = $_GET['code'] ?? ($general['code'] ?? null);
$selected_kind = 'text';

$default_text_choice = null;
$default_voice_choice = null;

$visible_hidden_channels = [];
$voice_count_codes = [];

foreach ($regular_channels as $ch) {
    $can = user_can_view_channel($ch['required_role_id'], $me_id, $community_id, $pdo, $is_owner, $is_admin);
    if ($can) {
        $accessible_codes[] = $ch['code'];
        if ($default_text_choice === null) $default_text_choice = $ch['code'];
    }
}
foreach ($voice_channels as $ch) {
    $can = user_can_view_channel($ch['required_role_id'], $me_id, $community_id, $pdo, $is_owner, $is_admin);
    if ($can) {
        $accessible_codes[] = $ch['code'];
        $voice_count_codes[] = $ch['code'];
        if ($default_voice_choice === null) $default_voice_choice = $ch['code'];
    }
}
foreach ($hidden_channels as $ch) {
    $can = user_can_view_channel($ch['required_role_id'], $me_id, $community_id, $pdo, $is_owner, $is_admin);
    if ($can) {
        $accessible_codes[] = $ch['code'];
        $visible_hidden_channels[] = $ch;
        if (!empty($ch['is_voice'])) {
            $voice_count_codes[] = $ch['code'];
            if ($default_voice_choice === null) $default_voice_choice = $ch['code'];
        } else {
            if ($default_text_choice === null) $default_text_choice = $ch['code'];
        }
    }
}

if ($selected_code && !in_array($selected_code, $accessible_codes, true)) {
    $selected_code = $default_text_choice ?: $default_voice_choice;
}
if (!$selected_code) {
    $selected_code = $default_text_choice ?: $default_voice_choice;
}

if ($selected_code) {
    foreach ($voice_channels as $vc) {
        if ((string)$vc['code'] === (string)$selected_code) {
            $selected_kind = 'voice';
            break;
        }
    }
    if ($selected_kind !== 'voice') {
        foreach ($visible_hidden_channels as $vh) {
            if (!empty($vh['is_voice']) && (string)$vh['code'] === (string)$selected_code) {
                $selected_kind = 'voice';
                break;
            }
        }
    }
}

/* ----------------------------
   Voice counts JSON endpoint
---------------------------- */
if (($_GET['action'] ?? '') === 'voice_counts') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'counts' => get_voice_counts($pdo, $voice_count_codes),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ----------------------------
   Prepare roles JSON
---------------------------- */
$roles_for_js = [];
foreach ($roles as $r) {
    $roles_for_js[] = [
        'id' => (int)$r['id'],
        'name' => $r['name'],
        'color' => $r['color'],
        'badge' => $r['badge'],
        'is_admin' => (bool)$r['is_admin']
    ];
}
$roles_json = json_encode($roles_for_js, JSON_UNESCAPED_UNICODE);
$user_roles_json = json_encode(array_values($user_roles_for_me), JSON_UNESCAPED_UNICODE);
$channels_json = json_encode($channels, JSON_UNESCAPED_UNICODE);
$voice_count_codes_json = json_encode(array_values($voice_count_codes), JSON_UNESCAPED_UNICODE);
$selected_code_json = json_encode($selected_code ?: '');
$selected_kind_json = json_encode($selected_kind ?: 'text');

/* -----------------------------
   Minimal blocks renderer
----------------------------- */
function render_community_blocks($pdo, $community_id, $channels, $general, $me_id, $is_owner, $is_admin) {
    $has = (bool)$pdo->query("SHOW TABLES LIKE 'community_blocks'")->fetchColumn();
    if (!$has) return '';

    try {
        $st = $pdo->prepare("SELECT * FROM community_blocks WHERE community_id = ? AND visible = 1 ORDER BY position ASC, id ASC");
        $st->execute([$community_id]);
        $blocks = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return '';
    }
    if (empty($blocks)) return '';

    $channelCodes = [];
    foreach ($channels as $ch) {
        if (!empty($ch['code'])) $channelCodes[$ch['code']] = $ch;
    }

    $html = "<div class='blocksArea' style='margin-top:12px'>\n";
    foreach ($blocks as $b) {
        $type = strtolower($b['block_type'] ?? '');
        $cfg = [];
        if (!empty($b['config_json'])) {
            $cfg = json_decode($b['config_json'], true) ?: [];
        }
        $blockTitle = htmlspecialchars($b['code'] ?: ($b['block_type'] ?? 'block'));

        if ($type === 'chat') {
            $room_code = null;
            if (!empty($cfg['room_code'])) {
                $room_code = $cfg['room_code'];
            } elseif (!empty($cfg['room_id'])) {
                try {
                    $s = $pdo->prepare("SELECT code FROM private_rooms WHERE id = ? LIMIT 1");
                    $s->execute([(int)$cfg['room_id']]);
                    $rc = $s->fetchColumn();
                    if ($rc) $room_code = $rc;
                } catch (Exception $e) {}
            } elseif (isset($channelCodes[$b['code']])) {
                $room_code = $b['code'];
            } elseif (!empty($general['code'])) {
                $room_code = $general['code'];
            }

            if (!$room_code) {
                $html .= "<div class='block chat-block' style='padding:12px;border-radius:10px;background:rgba(255,255,255,0.01);margin-bottom:10px'><strong>{$blockTitle}</strong><div class='small' style='margin-top:6px;color:#bfc9d9'>No room configured</div></div>\n";
            } else {
                $safeCode = rawurlencode($room_code);
                $html .= "<div class='block chat-block' style='padding:8px;border-radius:10px;background:linear-gradient(180deg, rgba(255,255,255,0.01), transparent);margin-bottom:10px'>";
                $html .= "<div style='display:flex;justify-content:space-between;align-items:center;padding:6px 8px'><strong>{$blockTitle}</strong><span class='small'>chat block — room: " . htmlspecialchars($room_code) . "</span></div>";
                $html .= "<div style='height:360px;border-radius:8px;overflow:hidden;border:1px solid rgba(255,255,255,0.03)'>";
                $html .= "<iframe src='private.php?code={$safeCode}' style='border:0;width:100%;height:100%;min-height:360px'></iframe>";
                $html .= "</div></div>\n";
            }
        } elseif ($type === 'voice') {
            $html .= "<div class='block voice-block' style='padding:12px;border-radius:10px;background:rgba(255,255,255,0.01);margin-bottom:10px'><strong>{$blockTitle}</strong>";
            $html .= "<div class='small' style='margin-top:6px;color:#bfc9d9'>Voice block (placeholder). Config: " . htmlspecialchars(json_encode($cfg)) . "</div>";
            $html .= "<div style='margin-top:8px'><button class='btn' onclick=\"alert('Join voice (placeholder)')\">Join Voice (placeholder)</button></div>";
            $html .= "</div>\n";
        } elseif ($type === 'voting' || $type === 'vote' || $type === 'poll') {
            $topic = htmlspecialchars($cfg['topic'] ?? $b['code']);
            $choices = is_array($cfg['choices'] ?? null) ? $cfg['choices'] : [];
            $html .= "<div class='block voting-block' style='padding:12px;border-radius:10px;background:rgba(255,255,255,0.01);margin-bottom:10px'><strong>{$blockTitle}</strong>";
            $html .= "<div class='small' style='margin-top:6px;color:#bfc9d9'>" . ($topic ? "Topic: {$topic}" : "Voting block") . "</div>";
            if (!empty($choices)) {
                $html .= "<div style='margin-top:8px'>";
                foreach ($choices as $ch) {
                    $label = htmlspecialchars($ch);
                    $html .= "<button class='btn' style='margin-right:8px;margin-bottom:6px' onclick=\"alert('Vote recorded (placeholder)')\">Vote: {$label}</button>";
                }
                $html .= "</div>";
            } else {
                $html .= "<div class='small' style='margin-top:8px;color:#bfc9d9'>No choices configured</div>";
            }
            $html .= "</div>\n";
        } else {
            $html .= "<div class='block generic-block' style='padding:12px;border-radius:10px;background:rgba(255,255,255,0.01);margin-bottom:10px'><strong>{$blockTitle}</strong>";
            $html .= "<div class='small' style='margin-top:6px;color:#bfc9d9'>Type: " . htmlspecialchars($b['block_type']) . " — Config: " . htmlspecialchars($b['config_json'] ?? '') . "</div>";
            $html .= "</div>\n";
        }
    }
    $html .= "</div>\n";
    return $html;
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($community['name'] ?? 'Community') ?> — Community</title>
<link rel="icon" href="root/favicon.ico">
<style>
:root{--bg:#0d1114;--panel:#0f1518;--accent:#3f7bff;--muted:#bfc9d9}
html,body{height:100%;margin:0;background:var(--bg);color:#eef3ff;font-family:Inter,Arial,Helvetica,sans-serif}
.topbar{display:flex;justify-content:space-between;align-items:center;padding:12px}
.container{display:flex;gap:12px;padding:12px;height:calc(100vh - 64px);box-sizing:border-box}
.side{width:320px;background:var(--panel);padding:12px;border-radius:10px;overflow:auto;transition:all .18s}
.side.hidden{display:none}
.main{flex:1;background:linear-gradient(180deg, rgba(255,255,255,0.01), transparent);border-radius:10px;padding:6px;display:flex;flex-direction:column;min-width:0}
.communityHeader{display:flex;gap:12px;align-items:center}
.commLogo{width:76px;height:76px;border-radius:16px;overflow:hidden;display:flex;align-items:center;justify-content:center;background:linear-gradient(180deg,#3f7bff,#2a59d9);font-weight:800;font-size:28px;color:#fff}
.channelItem{display:flex;justify-content:space-between;align-items:center;padding:10px;margin-bottom:8px;border-radius:8px;background:rgba(255,255,255,0.02);cursor:pointer;user-select:none}
.channelItem.locked{opacity:.45;cursor:not-allowed;background:transparent}
.channelItem.active{background:linear-gradient(90deg, rgba(63,123,255,0.12), rgba(63,123,255,0.06))}
.channelItem .name{font-weight:700}
.iframeWrap{flex:1;border-radius:10px;overflow:hidden;background:#0b0f12;min-width:0;display:flex;align-items:stretch}
.small{color:var(--muted);font-size:13px}
.btn{background:var(--accent);border:0;padding:8px 10px;border-radius:8px;color:#fff;cursor:pointer}
.formRow{margin-bottom:8px}
.rolePill{display:inline-block;padding:6px 8px;border-radius:8px;margin-right:6px;margin-bottom:6px;color:#000}
.topActions{display:flex;gap:8px;align-items:center}
.toggleBtn{background:transparent;font-size:28px;border:1px solid rgba(255,255,255,0.03);padding:6px 8px;border-radius:8px;color:var(--muted);cursor:pointer}
.blocksArea{margin-top:12px}
.roomDivider{border:none;border-top:1px solid rgba(255,255,255,0.08);margin:12px 0}
.roomSectionTitle{font-weight:800;margin-bottom:8px}
@media (max-width:900px){ .side{display:none} }

/* notification bell */
.bell1 { position:relative; cursor:pointer; padding:6px 8px; border-radius:8px; background:rgba(255,255,255,0.02); }
.badge { position:absolute; top:-6px; right:-6px; background:#ff4d4f; color:white; border-radius:12px; padding:2px 6px; font-size:12px; min-width:24px; text-align:center; }
.notifBox{position:absolute; right:12px; top:64px; background:#0b1114; border-radius:8px; padding:12px; min-width:360px; max-width:520px; box-shadow:0 8px 24px rgba(0,0,0,.6); display:none; z-index:1000}
.notifRow{padding:8px 6px;border-bottom:1px solid rgba(255,255,255,0.03);font-size:13px}
.notifRow:last-child{border-bottom:0}

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

/* voice indicators */
.voiceStatus{
  display:inline-flex;
  align-items:center;
  gap:6px;
  color:var(--muted);
  font-size:12px;
  white-space:nowrap;
}
.voiceDot{
  width:8px;
  height:8px;
  border-radius:999px;
  background:#4cd964;
  box-shadow:0 0 0 4px rgba(76,217,100,0.12);
  display:inline-block;
}
.voiceDot.off{
  background:#667085;
  box-shadow:none;
}
.voiceLiveBadge{
  display:inline-flex;
  align-items:center;
  gap:8px;
  padding:5px 10px;
  border-radius:999px;
  background:rgba(76,217,100,0.12);
  color:#d8ffe0;
  font-size:12px;
  font-weight:700;
}
.voiceLiveBadge .dot{
  width:8px;
  height:8px;
  border-radius:999px;
  background:#4cd964;
  box-shadow:0 0 0 4px rgba(76,217,100,0.12);
}

.userRolesHover{
  position:fixed;
  z-index:10000;
  background:#111;
  border:1px solid #222;
  padding:8px;
  border-radius:8px;
  color:#fff;
  display:none;
  box-shadow:0 8px 24px rgba(0,0,0,.6);
  max-width:320px;
}

/* context menu */
.contextMenu{
  position:fixed;
  background:#111;
  border:1px solid #222;
  padding:8px;
  border-radius:8px;
  box-shadow:0 8px 24px rgba(0,0,0,.6);
  z-index:9999;
  display:none;
}
.contextMenu button{
  display:block;
  width:100%;
  text-align:left;
  padding:8px;
  border:0;
  background:transparent;
  color:#fff;
  cursor:pointer;
  border-radius:6px;
}
.contextMenu button:hover{background:rgba(255,255,255,0.02)}

/* incoming call overlay */
.incomingCall{
  position:fixed;inset:0;z-index:9999;display:none;align-items:center;justify-content:center;
  background:radial-gradient(circle at center, rgba(88,101,242,.18), rgba(4,6,10,.88) 55%, rgba(0,0,0,.96) 100%);
  backdrop-filter:blur(12px);
}
.incomingCard{
  position:relative;width:min(94vw, 460px);
  border-radius:28px;padding:20px 18px 18px;
  background:linear-gradient(180deg, rgba(20,24,34,.99), rgba(10,13,20,.99));
  border:1px solid rgba(255,255,255,.08);
  box-shadow:0 28px 100px rgba(0,0,0,.68);
}
.incomingHead{display:flex;gap:14px;align-items:center}
.incomingAvatar{
  width:68px;height:68px;border-radius:22px;overflow:hidden;display:flex;align-items:center;justify-content:center;
  background:linear-gradient(135deg,#232a3d,#151a26);
  font-weight:900;font-size:26px;flex:0 0 68px;border:1px solid rgba(255,255,255,.06);
}
.incomingAvatar img{width:100%;height:100%;object-fit:cover;display:block}
.incomingTitle{font-weight:950;font-size:22px;line-height:1.1}
.incomingSub{margin-top:6px;color:#e6ecfb;font-size:15px;line-height:1.45}
.incomingActions{display:flex;gap:10px;margin-top:18px}
.incomingActions .pillBtn{
  flex:1;padding:14px 14px;font-weight:950;min-height:50px;border:0;border-radius:14px;color:#eef3ff;cursor:pointer
}
.incomingActions .pillBtn.primary{background:linear-gradient(135deg,#5865F2,#7b89ff)}
.incomingActions .pillBtn.ghost{background:rgba(255,255,255,.06)}
.incomingHint{margin-top:12px;color:#aab4c5;font-size:12px;text-align:center;line-height:1.4}
</style>
</head>
<body>
<div class="topbar">
  <div style="display:flex;align-items:center;gap:12px">
    <button id="toggleSidebar" class="toggleBtn" title="Toggle channels sidebar">☰</button>
    <div style="font-weight:800;font-size:18px"><?= htmlspecialchars($community['name'] ?? 'Community') ?></div>
    <div class="small" id="currentRoomMeta"><?= htmlspecialchars($community['description'] ?? '') ?></div>
  </div>
  <div class="topActions">
    <button onclick="location.href='room.php'" class="btn">Back to Nodes</button>
    <?php if ($is_admin): ?><button onclick="location.href='community_admin.php?public_id=<?= rawurlencode($community['public_id'] ?? '') ?>'" class="btn">Admin</button><?php endif; ?>
    <div id="notifBell" class="bell1" title="Notifications" style="margin-left:6px">
      🔔
      <span id="notifBadge" class="badge" style="display:none">0</span>
    </div>
  </div>
</div>

<div class="container" id="pageContainer">
  <aside class="side" id="sidebar">
    <div class="communityHeader">
      <div class="commLogo">
        <?php if (!empty($community['logo'])): ?>
          <?php if (stripos($community['logo'],'http') === 0): ?>
            <img src="<?= htmlspecialchars($community['logo'],ENT_QUOTES) ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:12px" />
          <?php else: ?>
            <img src="uploads/community_logos/<?= rawurlencode($community['logo']) ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:12px" />
          <?php endif; ?>
        <?php else: ?>
          <?= htmlspecialchars(substr($community['name'] ?? 'C',0,2)) ?>
        <?php endif; ?>
      </div>
      <div>
        <div style="font-weight:800"><?= htmlspecialchars($community['name']) ?></div>
        <div class="small">Members: <?= (int)($community['member_count'] ?? 0) ?></div>
      </div>
    </div>

    <hr style="border:none;border-top:1px solid rgba(255,255,255,0.03);margin:12px 0" />

    <div class="roomSectionTitle">Channels</div>

    <div id="channelsList">
      <?php if (empty($regular_channels)): ?>
        <div class="small">No channels yet</div>
      <?php else: foreach($regular_channels as $ch):
            $can = user_can_view_channel($ch['required_role_id'], $me_id, $community_id, $pdo, $is_owner, $is_admin);
      ?>
        <div class="channelItem roomItem <?= $can ? '' : 'locked' ?>" data-code="<?= htmlspecialchars($ch['code']) ?>" data-kind="text" data-locked="<?= $can ? '0' : '1' ?>" data-required-role="<?= (int)$ch['required_role_id'] ?>">
          <div class="name"><?= htmlspecialchars($ch['name']) ?></div>
          <div class="small"><?= $ch['required_role_id'] ? 'Locked' : 'Public' ?></div>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <?php if (!empty($voice_channels)): ?>
      <hr class="roomDivider" />
      <div class="roomSectionTitle">Voice chats</div>
      <div id="voiceChannelsList">
        <?php foreach($voice_channels as $ch):
              $can = user_can_view_channel($ch['required_role_id'], $me_id, $community_id, $pdo, $is_owner, $is_admin);
        ?>
          <div class="channelItem roomItem <?= $can ? '' : 'locked' ?>"
               data-code="<?= htmlspecialchars($ch['code']) ?>"
               data-kind="voice"
               data-locked="<?= $can ? '0' : '1' ?>"
               data-required-role="<?= (int)$ch['required_role_id'] ?>">
            <div class="name"><?= htmlspecialchars($ch['name']) ?></div>
            <div class="small">
              <span class="voiceStatus">
                <span class="voiceDot off"></span>
                <span class="voiceCountLabel">Loading…</span>
              </span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($visible_hidden_channels)): ?>
      <hr class="roomDivider" />
      <div class="roomSectionTitle">Hidden rooms</div>
      <div id="hiddenChannelsList">
        <?php foreach($visible_hidden_channels as $ch):
              $can = user_can_view_channel($ch['required_role_id'], $me_id, $community_id, $pdo, $is_owner, $is_admin);
              if (!$can) continue;
        ?>
          <div class="channelItem roomItem <?= $can ? '' : 'locked' ?>"
               data-code="<?= htmlspecialchars($ch['code']) ?>"
               data-kind="<?= !empty($ch['is_voice']) ? 'voice' : 'text' ?>"
               data-locked="<?= $can ? '0' : '1' ?>"
               data-required-role="<?= (int)$ch['required_role_id'] ?>">
            <div class="name"><?= htmlspecialchars($ch['name']) ?></div>
            <div class="small">
              <?php if (!empty($ch['is_voice'])): ?>
                <span class="voiceStatus">
                  <span class="voiceDot off"></span>
                  <span class="voiceCountLabel">Loading…</span>
                </span>
              <?php else: ?>
                Hidden<?= $ch['required_role_id'] ? ' · Locked' : '' ?>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div style="margin-top:12px">
      <?php if ($is_admin): ?>
        <div style="font-weight:800">Create channel</div>
        <form id="newChannelForm">
          <div class="formRow">
            <input name="name" placeholder="channel name" style="width:100%;padding:8px;border-radius:6px;border:0;background:#0b0f12;color:#fff" required>
          </div>
          <div class="formRow">
            <select name="required_role_id" style="width:100%;padding:8px;border-radius:6px;border:0;background:#0b0f12;color:#fff">
              <option value="">Public (no role)</option>
              <?php foreach ($roles as $r): ?>
                <option value="<?= (int)$r['id'] ?>"><?= htmlspecialchars($r['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="formRow">
            <select name="room_type" style="width:100%;padding:8px;border-radius:6px;border:0;background:#0b0f12;color:#fff">
              <option value="text">Text room</option>
              <option value="voice">Voice chat</option>
            </select>
          </div>
          <div class="formRow">
            <label style="display:flex;align-items:center;gap:8px;color:#eef3ff;font-size:14px;">
              <input type="checkbox" name="is_hidden" value="1">
              Hidden room
            </label>
          </div>
          <div><button class="btn" type="submit">Create</button></div>
        </form>
      <?php else: ?>
        <div class="small">Ask a community manager to add channels</div>
      <?php endif; ?>
    </div>
  </aside>

  <section class="main" id="mainContent">
    <div class="iframeWrap" id="iframeWrap" aria-live="polite">
      <?php if (!empty($selected_code)): ?>
        <?php if ($selected_kind === 'voice'): ?>
          <iframe id="chatFrame" src="private_voice.php?code=<?= rawurlencode($selected_code) ?>" style="border:0;width:100%;height:100%"></iframe>
        <?php else: ?>
          <iframe id="chatFrame" src="private.php?code=<?= rawurlencode($selected_code) ?>" style="border:0;width:100%;height:100%"></iframe>
        <?php endif; ?>
      <?php else: ?>
        <div style="padding:24px;color:var(--muted)">You do not currently have access to any channels in this community.</div>
      <?php endif; ?>
    </div>

    <div id="blocksContainer" style="margin-top:12px">
      <?php
        echo render_community_blocks(
            $pdo,
            $community_id,
            array_merge($regular_channels, $voice_channels, $visible_hidden_channels),
            $general,
            $me_id,
            $is_owner,
            $is_admin
        );
      ?>
    </div>
  </section>
</div>

<div id="contextMenu" class="contextMenu" role="menu" aria-hidden="true">
  <div style="font-weight:700;margin-bottom:6px" id="contextMenuTitle">User</div>
  <button data-action="view">View profile</button>
  <?php if ($is_admin): ?>
    <button data-action="add_role">Add role…</button>
    <button data-action="remove_role">Remove role…</button>
    <button data-action="timeout">Timeout</button>
    <button data-action="ban">Ban</button>
  <?php endif; ?>
</div>

<div id="userRolesHover" class="userRolesHover" aria-hidden="true"></div>

<div id="notifDropdown" class="notifBox" aria-hidden="true" style="display:none">
  <div class="markAll"><button id="markAllBtn" style="background:transparent;border:0;color:var(--accent);cursor:pointer">Mark all read</button></div>
  <div id="notifList" style="max-height:420px;overflow:auto">
    <div style="padding:12px;color:var(--muted)">Loading…</div>
  </div>
</div>

<div class="incomingCall" id="incomingCall" aria-hidden="true">
  <div class="incomingCard">
    <div class="incomingHead">
      <div class="incomingAvatar" id="incomingAvatar"></div>
      <div style="min-width:0;flex:1">
        <div class="incomingTitle" id="incomingTitle">Incoming call</div>
        <div class="incomingSub" id="incomingSub">Someone is calling…</div>
      </div>
    </div>
    <div class="incomingActions">
      <button class="pillBtn primary" id="incomingAcceptBtn">Open</button>
      <button class="pillBtn ghost" id="incomingDismissBtn">Dismiss</button>
    </div>
    <div class="incomingHint">This stays on screen until you choose.</div>
  </div>
</div>

<audio id="notifBellAudio" preload="auto">
  <source src="root/bell_2.mp3" type="audio/mpeg">
</audio>
<audio id="call_bell" preload="auto">
  <source src="root/bell_3.mp3" type="audio/mpeg">
</audio>

<script>
/* ---------- config & state ---------- */
const COMMUNITY_ID = <?= json_encode($community_id) ?>;
const COMMUNITY_QUERY = <?= json_encode($public_id !== '' ? ('public_id=' . $public_id) : ('id=' . $community_id)) ?>;
const IS_ADMIN = <?= $is_admin ? 'true' : 'false' ?>;
const IS_OWNER = <?= $is_owner ? 'true' : 'false' ?>;
const CAN_TIMEOUT = <?= $can_timeout ? 'true' : 'false' ?>;
const CAN_BAN = <?= $can_ban ? 'true' : 'false' ?>;
const CAN_MANAGE_ROLES = <?= $can_manage_roles ? 'true' : 'false' ?>;
const ME_ID = <?= json_encode($me_id) ?>;
const ROLES = <?= $roles_json ?>;
const USER_ROLES_ME = <?= $user_roles_json ?>;
const ACCESSIBLE_VOICE_CODES = <?= $voice_count_codes_json ?>;
const INITIAL_SELECTED_CODE = <?= $selected_code_json ?>;
const INITIAL_SELECTED_KIND = <?= $selected_kind_json ?>;

const bell2 = document.getElementById('notifBellAudio');
const callBell = document.getElementById('call_bell');

let audioUnlocked = false;
function unlockAudioOnce() {
  audioUnlocked = true;
  try {
    if (bell2) bell2.load();
    if (callBell) callBell.load();
  } catch (e) {}
}
document.addEventListener('pointerdown', unlockAudioOnce, { once:true });
document.addEventListener('touchstart', unlockAudioOnce, { once:true, passive:true });
document.addEventListener('keydown', unlockAudioOnce, { once:true });

/* ---------- sidebar ---------- */
const toggleBtn = document.getElementById('toggleSidebar');
const sidebar = document.getElementById('sidebar');
toggleBtn.addEventListener('click', ()=> sidebar.classList.toggle('hidden'));

/* ---------- room switching ---------- */
function roomSrcFor(code, kind) {
  return kind === 'voice'
    ? 'private_voice.php?code=' + encodeURIComponent(code)
    : 'private.php?code=' + encodeURIComponent(code);
}

function loadRoom(code, kind) {
  const iframeWrap = document.getElementById('iframeWrap');
  const src = roomSrcFor(code, kind);

  let chatFrame = document.getElementById('chatFrame');
  if (chatFrame) {
    chatFrame.src = src;
  } else {
    const ifr = document.createElement('iframe');
    ifr.id = 'chatFrame';
    ifr.src = src;
    ifr.style.border = 0;
    ifr.style.width = '100%';
    ifr.style.height = '100%';
    iframeWrap.innerHTML = '';
    iframeWrap.appendChild(ifr);
    chatFrame = ifr;
  }
  refreshCurrentVoiceMeta();
}

function setActiveRoom(el) {
  document.querySelectorAll('.roomItem').forEach(c => c.classList.remove('active'));
  el.classList.add('active');
}

const roomEls = Array.from(document.querySelectorAll('.roomItem'));
roomEls.forEach(el => {
  el.addEventListener('click', () => {
    const locked = el.dataset.locked === '1';
    const code = el.dataset.code;
    const kind = el.dataset.kind || 'text';
    if (locked) {
      alert('You do not have permission to view this channel.');
      return;
    }
    if (!code) return;
    setActiveRoom(el);
    loadRoom(code, kind);
  });
});

/* ---------- create channel ---------- */
const newChannelForm = document.getElementById('newChannelForm');
if (newChannelForm) {
  newChannelForm.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const fd = new FormData(newChannelForm);
    fd.append('community_id', COMMUNITY_ID);

    try {
      const res = await fetch('community_interface.php?action=create_room', {
        method:'POST',
        body: fd,
        credentials:'same-origin'
      });
      const j = await res.json();

      if (j && j.ok) {
        const roomType = (j.is_voice || j.room_type === 'voice') ? 'voice' : 'text';

        const div = document.createElement('div');
        div.className = 'channelItem roomItem';
        div.dataset.code = j.code;
        div.dataset.kind = roomType;
        div.dataset.locked = j.required_role_id ? '1' : '0';
        div.dataset.requiredRole = j.required_role_id || '';
        div.innerHTML = `
          <div class="name">${escapeHtml(j.name || 'Channel')}</div>
          <div class="small">${
            roomType === 'voice'
              ? '<span class="voiceStatus"><span class="voiceDot off"></span><span class="voiceCountLabel">Loading…</span></span>'
              : (j.is_hidden ? 'Hidden' : (j.required_role_id ? 'Locked' : 'Public'))
          }</div>
        `;

        div.addEventListener('click', () => {
          const locked = div.dataset.locked === '1';
          if (locked) {
            alert('You do not have permission to view this channel.');
            return;
          }
          setActiveRoom(div);
          loadRoom(j.code, roomType);
        });

        if (roomType === 'voice') {
          const voiceList = document.getElementById('voiceChannelsList');
          if (voiceList) {
            voiceList.appendChild(div);
          } else {
            location.reload();
            return;
          }
        } else if (j.is_hidden) {
          const hiddenList = document.getElementById('hiddenChannelsList');
          if (hiddenList) {
            hiddenList.appendChild(div);
          } else {
            location.reload();
            return;
          }
        } else {
          document.getElementById('channelsList').appendChild(div);
        }

        newChannelForm.reset();
        refreshVoiceCounts();
        alert('Channel created');
      } else {
        alert(j && j.error ? j.error : 'Failed to create channel');
      }
    } catch (err) {
      console.error(err);
      alert('Create failed');
    }
  });
}

/* ---------- iframe enhancements ---------- */
const ctxtMenu = document.getElementById('contextMenu');
const ctxtTitle = document.getElementById('contextMenuTitle');
const userRolesHover = document.getElementById('userRolesHover');
let currentTargetUser = null;

function getIframeDoc() {
  const ifr = document.getElementById('chatFrame');
  if (!ifr) return null;
  try { return ifr.contentDocument || ifr.contentWindow.document; }
  catch (e) { return null; }
}

function showModerationMenuAt(pageX, pageY, username, userId) {
  currentTargetUser = { id: userId, username: username };
  if (!ctxtMenu) return;
  ctxtTitle.textContent = username || 'User';
  let left = pageX, top = pageY;
  if (left + 240 > window.innerWidth) left = window.innerWidth - 260;
  if (top + 200 > window.innerHeight) top = window.innerHeight - 220;
  ctxtMenu.style.left = left + 'px';
  ctxtMenu.style.top = top + 'px';
  ctxtMenu.style.display = 'block';
  ctxtMenu.setAttribute('aria-hidden','false');
}

function escapeHtml(s){ if (!s) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function setAvatar(el, user) {
  if (!el) return;
  el.innerHTML = '';
  const url = user && user.avatar ? ((String(user.avatar).indexOf('/') === 0 || String(user.avatar).startsWith('http')) ? user.avatar : 'avatars/' + encodeURIComponent(user.avatar)) : '';
  if (url) {
    const img = document.createElement('img');
    img.src = url;
    img.alt = user && user.username ? user.username : '';
    el.appendChild(img);
  } else {
    el.textContent = (user && user.username ? user.username[0] : '?').toUpperCase();
  }
}

async function enhanceIframeElements(){
  const doc = getIframeDoc();
  if (!doc) return;
  try {
    const anchors = doc.querySelectorAll('a[href]');
    anchors.forEach(a => {
      try {
        a.setAttribute('target','_top');
        a.addEventListener('click', (ev)=> {
          const href = a.getAttribute('href');
          if (!href) return;
          if (href.startsWith('javascript:')) return;
          window.top.location.href = href;
          ev.preventDefault();
        });
      } catch(e){}
    });

    doc.addEventListener('contextmenu', function(e){
      const target = e.target.closest('.userLink, .avatarLink, .msgRow, .username, [data-username]');
      if (!target) return;
      let username = null;
      let userId = null;
      const el = e.target.closest('[data-username],[data-user-id]') || e.target;
      if (el) {
        username = el.getAttribute('data-username') || el.getAttribute('data-user') || (el.textContent || null);
        userId = el.getAttribute('data-user-id') || el.getAttribute('data-userid') || null;
      }
      if (!username && target.textContent) username = target.textContent.trim().split(/\s+/)[0];
      if (!userId && username) {
        fetch('community_interface.php?action=resolve_user&username=' + encodeURIComponent(username) + '&community_id=' + encodeURIComponent(COMMUNITY_ID), { credentials:'same-origin' })
          .then(r => r.json())
          .then(j => {
            if (j && j.ok && j.user_id) userId = j.user_id;
            showModerationMenuAt(e.pageX, e.pageY, username, userId);
          }).catch(()=> {
            showModerationMenuAt(e.pageX, e.pageY, username, null);
          });
      } else {
        showModerationMenuAt(e.pageX, e.pageY, username, userId);
      }
      e.preventDefault();
    }, true);
  } catch (err) {
    console.warn('enhanceIframeElements failed', err);
  }
}

function enhanceIframeOnceLoaded() {
  const ifr = document.getElementById('chatFrame');
  if (!ifr) return;
  ifr.addEventListener('load', ()=> {
    enhanceIframeElements();
  });
  enhanceIframeElements();
}

(function initEnhancements(){
  enhanceIframeOnceLoaded();
  new MutationObserver(() => { enhanceIframeOnceLoaded(); }).observe(document.getElementById('iframeWrap'), { childList: true, subtree: false });
})();

if (ctxtMenu) {
  ctxtMenu.addEventListener('click', async (ev)=> {
    const btn = ev.target.closest('button');
    if (!btn || !currentTargetUser) return;
    const action = btn.getAttribute('data-action');
    ctxtMenu.style.display = 'none';
    ctxtMenu.setAttribute('aria-hidden','true');

    if (action === 'view') {
      if (currentTargetUser.username) location.href = 'user.php?username=' + encodeURIComponent(currentTargetUser.username);
      return;
    }
    if (!IS_ADMIN) { alert('Only moderators/admins can do that'); return; }

    if (action === 'add_role' || action === 'remove_role') {
      const roleName = prompt('Enter role name exactly (available: ' + ROLES.map(r=>r.name).join(', ') + ')');
      if (!roleName) return;
      const role = ROLES.find(r => r.name.toLowerCase() === roleName.toLowerCase());
      if (!role) { alert('Role not found'); return; }
      const method = action === 'add_role' ? 'add_role' : 'remove_role';
      const body = new URLSearchParams({
        action_type: method,
        community_id: COMMUNITY_ID,
        target_user_id: currentTargetUser.id || '',
        role_id: role.id,
        reason: 'via community context menu'
      });
      const resp = await fetch('community_interface.php?action=moderate', { method:'POST', body, credentials:'same-origin' });
      const j = await resp.json();
      if (j && j.ok) alert('Role updated'); else alert('Failed: ' + (j && j.error ? j.error : 'unknown'));
      return;
    }

    if (action === 'timeout' || action === 'ban') {
      const reason = prompt('Please give a reason (will be saved in audit log):', '');
      if (reason === null) return;
      let duration = '';
      if (action === 'timeout') {
        duration = prompt('Timeout length in minutes', '30');
        if (duration === null) return;
      }
      const body = new URLSearchParams({
        action_type: action,
        community_id: COMMUNITY_ID,
        target_user_id: currentTargetUser.id || '',
        reason: reason || '',
        duration_minutes: duration || ''
      });
      const resp = await fetch('community_interface.php?action=moderate', { method:'POST', body, credentials:'same-origin' });
      const j = await resp.json();
      if (j && j.ok) alert('Action applied'); else alert('Failed: ' + (j && j.error ? j.error : 'unknown'));
      return;
    }
  });

  document.addEventListener('click', (e)=> {
    if (!e.target.closest || !e.target.closest('#contextMenu')) {
      ctxtMenu.style.display = 'none';
      ctxtMenu.setAttribute('aria-hidden','true');
    }
  });
}

function showUserRolesHover(x, y, rolesArray) {
  if (!rolesArray || !rolesArray.length) return;
  userRolesHover.innerHTML = '<strong>Roles</strong><div style="margin-top:6px">' + rolesArray.map(r => `<span style="display:inline-block;background:${r.color||'#ddd'};color:#000;padding:4px 8px;border-radius:6px;margin-right:6px">${escapeHtml(r.name)}</span>`).join('') + '</div>';
  let left = Math.min(window.innerWidth - 260, x + 12);
  let top = Math.min(window.innerHeight - 140, y + 12);
  userRolesHover.style.left = left + 'px';
  userRolesHover.style.top = top + 'px';
  userRolesHover.style.display = 'block';
  userRolesHover.setAttribute('aria-hidden','false');
}
function hideUserRolesHover() { userRolesHover.style.display = 'none'; userRolesHover.setAttribute('aria-hidden','true'); }

/* ---------- incoming call overlay ---------- */
const CALL_API = 'community_interface.php?action=call_state';
const INCOMING_CALL = document.getElementById('incomingCall');
const INCOMING_AVATAR = document.getElementById('incomingAvatar');
const INCOMING_TITLE = document.getElementById('incomingTitle');
const INCOMING_SUB = document.getElementById('incomingSub');
const INCOMING_ACCEPT = document.getElementById('incomingAcceptBtn');
const INCOMING_DISMISS = document.getElementById('incomingDismissBtn');

let activeIncomingCall = null;
let incomingCallRinger = null;
let incomingCallRetry = null;
let pendingVoiceCaller = '';

function stopIncomingCallRinger() {
  if (incomingCallRinger) {
    clearInterval(incomingCallRinger);
    incomingCallRinger = null;
  }
  if (incomingCallRetry) {
    clearTimeout(incomingCallRetry);
    incomingCallRetry = null;
  }
  try {
    if (callBell) {
      callBell.pause();
      callBell.currentTime = 0;
      callBell.loop = false;
    }
  } catch (e) {}
}

async function playIncomingCallBell() {
  if (!callBell) return false;
  try {
    callBell.loop = true;
    callBell.currentTime = 0;
    const p = callBell.play();
    if (p && typeof p.then === 'function') await p;
    return true;
  } catch (e) {
    return false;
  }
}

async function startIncomingCallRinger() {
  stopIncomingCallRinger();

  const beat = async () => {
    const ok = await playIncomingCallBell();
    if (!ok) {
      incomingCallRetry = setTimeout(beat, 1200);
    } else if (navigator.vibrate) {
      navigator.vibrate([180, 80, 220]);
    }
  };

  await beat();

  incomingCallRinger = setInterval(() => {
    try {
      if (callBell && callBell.paused) {
        callBell.currentTime = 0;
        callBell.play().catch(()=>{});
      }
    } catch (e) {}
  }, 3200);
}

async function showIncomingCall(userName, avatar, text, callId){
  activeIncomingCall = callId || activeIncomingCall || null;
  pendingVoiceCaller = (userName || '').trim();
  INCOMING_TITLE.textContent = pendingVoiceCaller ? `${pendingVoiceCaller} is calling you` : 'Incoming call';
  INCOMING_SUB.textContent = text || 'Tap Open to continue.';
  setAvatar(INCOMING_AVATAR, { username: pendingVoiceCaller || 'Caller', avatar });
  INCOMING_CALL.style.display = 'flex';
  INCOMING_CALL.setAttribute('aria-hidden', 'false');
  await startIncomingCallRinger();
}

function hideIncomingCall(){
  INCOMING_CALL.style.display = 'none';
  INCOMING_CALL.setAttribute('aria-hidden', 'true');
  activeIncomingCall = null;
  stopIncomingCallRinger();
}

async function refreshCallState() {
  try {
    const r = await fetch(CALL_API, { credentials:'same-origin' });
    if (!r.ok) return;
    const j = await r.json();
    const call = j && j.call ? j.call : null;
    if (!call) {
      if (activeIncomingCall) hideIncomingCall();
      return;
    }

    if (String(call.status) === 'ringing' && Number(call.callee_id) === Number(ME_ID)) {
      if (String(activeIncomingCall || '') !== String(call.id)) {
        showIncomingCall(
          call.caller_username || 'Caller',
          call.caller_avatar || null,
          'Tap Open to go to the call.',
          call.id
        );
      }
    } else if (activeIncomingCall && String(activeIncomingCall) === String(call.id) && String(call.status) !== 'ringing') {
      hideIncomingCall();
    }
  } catch (e) {
    // ignore
  }
}

INCOMING_ACCEPT.addEventListener('click', async ()=>{
  const caller = pendingVoiceCaller || (INCOMING_TITLE.textContent || '').replace(' is calling you', '').trim();
  try {
    if (caller) {
      await fetch(
        'message_interface.php?user=' + encodeURIComponent(caller) + '&mode=voice_call_accept',
        { method:'POST', credentials:'same-origin' }
      );
    }
  } catch (e) {}
  hideIncomingCall();
  if (caller) {
    location.href = 'message.php?user=' + encodeURIComponent(caller);
  }
});

INCOMING_DISMISS.addEventListener('click', async ()=>{
  const caller = pendingVoiceCaller || (INCOMING_TITLE.textContent || '').replace(' is calling you', '').trim();
  try {
    if (caller) {
      await fetch(
        'message_interface.php?user=' + encodeURIComponent(caller) + '&mode=voice_call_dismiss',
        { method:'POST', credentials:'same-origin' }
      );
    }
  } catch (e) {}
  hideIncomingCall();
});

INCOMING_CALL.addEventListener('click', (e)=> { if (e.target === INCOMING_CALL) hideIncomingCall(); });

/* ---------- voice counts ---------- */
const voiceCountMap = {};
let voicePollTimer = null;

function refreshCurrentVoiceMeta() {
  const active = document.querySelector('.roomItem.active[data-kind="voice"]');
  const titleMeta = document.querySelector('#currentRoomMeta');
  if (!titleMeta) return;

  if (active) {
    const code = active.dataset.code;
    const count = Number(voiceCountMap[code] || 0);
    titleMeta.innerHTML = `<span class="voiceLiveBadge"><span class="dot"></span><span>${escapeHtml(count > 0 ? `${count} connected` : 'No one connected')}</span></span>`;
  } else {
    const selectedText = <?= json_encode($community['description'] ?? '') ?>;
    titleMeta.textContent = selectedText || 'Tap a channel to open it';
  }
}

function applyVoiceCountToRow(el, count) {
  const label = el.querySelector('.voiceCountLabel');
  const dot = el.querySelector('.voiceDot');
  if (!label || !dot) return;
  dot.classList.toggle('off', !(count > 0));
  label.textContent = count > 0 ? `${count} connected` : 'Empty';
}

function updateVoiceIndicators() {
  document.querySelectorAll('.roomItem[data-kind="voice"]').forEach(el => {
    const code = el.dataset.code;
    const count = Number(voiceCountMap[code] || 0);
    applyVoiceCountToRow(el, count);
  });
  refreshCurrentVoiceMeta();
}

async function refreshVoiceCounts() {
  try {
    const r = await fetch('community.php?' + COMMUNITY_QUERY + '&action=voice_counts', { credentials:'same-origin' });
    if (!r.ok) return;
    const j = await r.json();
    if (!j || !j.ok || !j.counts) return;
    Object.keys(j.counts).forEach(code => {
      voiceCountMap[code] = j.counts[code];
    });
    updateVoiceIndicators();
  } catch (e) {
    // ignore
  }
}

/* ---------- notifications ---------- */
const notifBell = document.getElementById('notifBell');
const notifBadge = document.getElementById('notifBadge');
const notifDropdown = document.getElementById('notifDropdown');
const notifList = document.getElementById('notifList');
const markAllBtn = document.getElementById('markAllBtn');

let lastUnread = 0;
const API = 'notifications.php';
const marked = new Set();

async function fetchNotifications(limit=100) {
  try {
    const res = await fetch(`${API}?limit=${encodeURIComponent(limit)}`, { credentials:'same-origin' });
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
      try { if (audioUnlocked && bell2) { bell2.currentTime = 0; bell2.play().catch(()=>{}); } } catch(e){}
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
  } catch (e) {
    notifList.innerHTML = '<div style="padding:12px;color:#f66">Failed to load notifications</div>';
    console.error('loadNotifications', e);
  }
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
    const items = document.querySelectorAll('.notifGroup');
    items.forEach(it => { it.classList.remove('unread'); const dot = it.querySelector('.unreadDot'); if (dot) dot.remove(); });
    lastUnread = 0; notifBadge.style.display='none';
    const j = await fetchNotifications(200);
    if (Array.isArray(j.notifications)) { for (const n of j.notifications) marked.add(n.id); }
  } catch (e) { console.error(e); }
});

document.addEventListener('click', (e)=> {
  if (!e.target.closest || (!e.target.closest('#notifBell') && !e.target.closest('#notifDropdown'))) toggleNotifDropdown(false);
});

let notifPollingTimer = null;
async function startNotifPolling() {
  try {
    const j = await fetchNotifications(5);
    lastUnread = j.unread_count || 0;
    if (lastUnread > 0) {
      notifBadge.style.display = 'inline-block';
      notifBadge.textContent = lastUnread > 99 ? '99+' : String(lastUnread);
    } else {
      notifBadge.style.display='none';
    }

    if (notifPollingTimer) clearInterval(notifPollingTimer);
    notifPollingTimer = setInterval(async ()=> {
      try {
        const k = await fetchNotifications(5);
        if (k.unread_count > lastUnread && audioUnlocked && bell2) { bell2.currentTime = 0; bell2.play().catch(()=>{}); }
        lastUnread = k.unread_count;
        if (lastUnread > 0) {
          notifBadge.style.display='inline-block';
          notifBadge.textContent = lastUnread > 99 ? '99+' : String(lastUnread);
        } else notifBadge.style.display='none';
      } catch(e){}
    }, 30000);
  } catch(e){}
}

/* ---------- initial active room ---------- */
(function markInitial() {
  const selected = INITIAL_SELECTED_CODE;
  const selectedKind = INITIAL_SELECTED_KIND;
  if (selected) {
    const selectedEl = document.querySelector(`.roomItem[data-code="${CSS.escape(selected)}"]`);
    if (selectedEl) selectedEl.classList.add('active');
    loadRoom(selected, selectedKind);
  }
})();

/* ---------- periodic voice counts + incoming calls ---------- */
async function startVoicePolling() {
  await refreshVoiceCounts();
  await refreshCallState();
  refreshCurrentVoiceMeta();
  if (voicePollTimer) clearInterval(voicePollTimer);
  voicePollTimer = setInterval(async () => {
    await refreshVoiceCounts();
    await refreshCallState();
    refreshCurrentVoiceMeta();
  }, 5000);
}

/* ---------- start ---------- */
window.addEventListener('keydown', (e)=> {
  if (e.key === 'Escape') {
    closeNotifBox();
    hideIncomingCall();
  }
});
function closeNotifBox() {
  notifDropdown.style.display = 'none';
}

window.addEventListener('beforeunload', ()=> {
  if (notifPollingTimer) clearInterval(notifPollingTimer);
  if (voicePollTimer) clearInterval(voicePollTimer);
});

startNotifPolling();
startVoicePolling();
refreshVoiceCounts();
refreshCurrentVoiceMeta();
refreshCallState();
</script>
</body>
</html>
