<?php
// mobile_community.php - mobile-optimized community view with a larger iframe area,
// thumb-friendly channel drawer, and support for text / voice / hidden rooms.

require "config.php";

// --- auth ---
if (empty($_COOKIE['auth_token'])) { header("Location:index.php"); exit; }
$stmt = $pdo->prepare("SELECT id, username, avatar FROM users WHERE auth_token = ?");
$stmt->execute([$_COOKIE['auth_token']]);
$me = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$me) { header("Location:index.php"); exit; }
$me_id = (int)$me['id'];
$me_username = $me['username'];

// identify community by public_id or internal id
$public_id = trim((string)($_GET['public_id'] ?? ''));
$internal_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// fetch community
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

// Ensure private_rooms has required columns
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

// ensure there is a general room
try {
    $s = $pdo->prepare("SELECT id, code, name FROM private_rooms WHERE community_id = ? AND name = 'general' LIMIT 1");
    $s->execute([$community_id]);
    $general = $s->fetch(PDO::FETCH_ASSOC);
    if (!$general) {
        $code = 'c' . substr(md5($community['public_id'] . time() . random_int(1,99999)),0,10);
        $ins = $pdo->prepare("INSERT INTO private_rooms (code, name, community_id) VALUES (?, 'general', ?)");
        $ins->execute([$code, $community_id]);
        $general_id = (int)$pdo->lastInsertId();
        $general = ['id'=>$general_id, 'code'=>$code, 'name'=>'general'];
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
            $code = 'c' . substr(md5($community['public_id'] . time() . random_int(1,99999)),0,10);
            $ins = $pdo->prepare("INSERT INTO private_rooms (code, name, community_id) VALUES (?, 'general', ?)");
            $ins->execute([$code, $community_id]);
            $general_id = (int)$pdo->lastInsertId();
            $general = ['id'=>$general_id, 'code'=>$code, 'name'=>'general'];
        }
    } catch (Exception $ex) {
        die("Failed to ensure general room: " . htmlspecialchars($ex->getMessage()));
    }
}

// Load channels
$channels = [];
try {
    $s = $pdo->prepare("SELECT id, code, name, required_role_id, is_hidden, is_voice FROM private_rooms WHERE community_id = ? ORDER BY id ASC");
    $s->execute([$community_id]);
    $channels = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $channels = []; }

// split into groups for display
$regular_channels = [];
$voice_channels = [];
$hidden_channels = [];
foreach ($channels as $ch) {
    if (!empty($ch['is_hidden'])) {
        $hidden_channels[] = $ch;
    } elseif (!empty($ch['is_voice'])) {
        $voice_channels[] = $ch;
    } else {
        $regular_channels[] = $ch;
    }
}

// load community roles
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

// fetch current member roles (supporting multiple roles: community_member_roles)
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
        if ($rid) $user_roles_for_me = [ (int)$rid ];
    }
} catch (Exception $e) { $user_roles_for_me = []; }

// admin/owner check
function is_comm_admin_local($pdo, $community_id, $user_id) {
    $s = $pdo->prepare("SELECT owner_id FROM communities WHERE id = ? LIMIT 1");
    $s->execute([$community_id]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r) return false;
    if ((int)$r['owner_id'] === (int)$user_id) return true;
    $hasTable = (bool)$pdo->query("SHOW TABLES LIKE 'community_member_roles'")->fetchColumn();
    if ($hasTable) {
        $q = $pdo->prepare("SELECT 1 FROM community_member_roles mr JOIN community_roles cr ON cr.id = mr.role_id WHERE mr.community_id = ? AND mr.user_id = ? AND cr.is_admin = 1 LIMIT 1");
        $q->execute([$community_id, $user_id]);
        if ($q->fetchColumn()) return true;
    } else {
        $s2 = $pdo->prepare("SELECT cr.is_admin FROM community_members cm JOIN community_roles cr ON cr.id = cm.role_id WHERE cm.community_id = ? AND cm.user_id = ? LIMIT 1");
        $s2->execute([$community_id, $user_id]);
        $v = $s2->fetchColumn();
        return (bool)$v;
    }
    return false;
}
$is_admin = is_comm_admin_local($pdo, $community_id, $me_id);
$is_owner = ((int)$community['owner_id'] === $me_id);

// permission helper
function user_can_view_channel($channel_required_role_id, $me_id, $community_id, $pdo, $is_owner, $is_admin) {
    if ($channel_required_role_id === null || $channel_required_role_id === 0) return true;
    if ($is_owner) return true;
    if ($is_admin) return true;
    try {
        $hasTable = (bool)$pdo->query("SHOW TABLES LIKE 'community_member_roles'")->fetchColumn();
        if ($hasTable) {
            $q = $pdo->prepare("SELECT 1 FROM community_member_roles mr JOIN community_roles cr ON cr.id = mr.role_id WHERE mr.community_id = ? AND mr.user_id = ? AND cr.can_view_locked = 1 LIMIT 1");
            $q->execute([$community_id, $me_id]);
            if ($q->fetchColumn()) return true;
            $q2 = $pdo->prepare("SELECT 1 FROM community_member_roles WHERE community_id = ? AND user_id = ? AND role_id = ? LIMIT 1");
            $q2->execute([$community_id, $me_id, (int)$channel_required_role_id]);
            if ($q2->fetchColumn()) return true;
        } else {
            $q = $pdo->prepare("SELECT cr.can_view_locked, cm.role_id FROM community_members cm LEFT JOIN community_roles cr ON cr.id = cm.role_id WHERE cm.community_id = ? AND cm.user_id = ? LIMIT 1");
            $q->execute([$community_id, $me_id]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
            if ($r && (!empty($r['can_view_locked']) || intval($r['role_id']) === intval($channel_required_role_id))) return true;
        }
    } catch (Exception $e) {}
    return false;
}

// decide accessible channels and selection
$accessible_codes = [];
$selected_code = $_GET['code'] ?? ($general['code'] ?? null);
$selected_kind = 'text';
$default_text_choice = null;
$default_voice_choice = null;

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
        if ($default_voice_choice === null) $default_voice_choice = $ch['code'];
    }
}

// hidden rooms are only shown if actually accessible
$visible_hidden_channels = [];
foreach ($hidden_channels as $ch) {
    $can = user_can_view_channel($ch['required_role_id'], $me_id, $community_id, $pdo, $is_owner, $is_admin);
    if ($can) {
        $accessible_codes[] = $ch['code'];
        $visible_hidden_channels[] = $ch;
        if ($default_text_choice === null) $default_text_choice = $ch['code'];
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
}

// prepare roles JSON for JS
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

function e($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?= e($community['name'] ?? 'Community') ?> — Mobile</title>
<link rel="icon" href="root/favicon.ico">
<style>
:root{
  --bg:#0d1114;
  --panel:#0f1518;
  --accent:#3f7bff;
  --muted:#bfc9d9;
  --text:#eef3ff;
  --card:rgba(255,255,255,0.03);
}
html,body{
  height:100%;
  margin:0;
  background:var(--bg);
  color:var(--text);
  font-family:Inter,Arial,Helvetica,sans-serif;
  -webkit-font-smoothing:antialiased;
  overflow:hidden;
}
*{box-sizing:border-box}
button,input,select{font:inherit}

.app{
  height:100vh;
  display:flex;
  flex-direction:column;
}

.header{
  position:sticky;
  top:0;
  z-index:30;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  padding:10px 12px;
  background:linear-gradient(180deg, rgba(15,21,24,0.96), rgba(15,21,24,0.86));
  backdrop-filter:blur(12px);
  border-bottom:1px solid rgba(255,255,255,0.04);
}

.brand{
  min-width:0;
  display:flex;
  align-items:center;
  gap:10px;
}

.backBtn,.iconBtn,.pillBtn,.ghost,.btn{
  border:0;
  border-radius:12px;
  cursor:pointer;
  color:var(--text);
}

.backBtn{
  width:42px;height:42px;
  background:rgba(255,255,255,0.03);
  font-size:18px;
}

.titleWrap{
  min-width:0;
  display:flex;
  flex-direction:column;
  gap:2px;
}

.title{
  font-weight:800;
  font-size:15px;
  line-height:1.1;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
  max-width:52vw;
}

.subtitle{
  color:var(--muted);
  font-size:12px;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
  max-width:52vw;
}

.headerActions{
  display:flex;
  align-items:center;
  gap:8px;
}

.iconBtn{
  width:42px;height:42px;
  background:rgba(255,255,255,0.03);
  font-size:18px;
  position:relative;
}

.badge{
  position:absolute;
  top:-6px;
  right:-6px;
  background:#ff4d4f;
  color:#fff;
  padding:3px 7px;
  border-radius:999px;
  font-size:12px;
  line-height:1;
}

.btn{
  background:var(--accent);
  padding:10px 12px;
  font-weight:700;
}

.ghost{
  background:rgba(255,255,255,0.03);
  padding:10px 12px;
}

.mainShell{
  flex:1;
  display:flex;
  min-height:0;
}

.viewer{
  flex:1;
  min-width:0;
  display:flex;
  flex-direction:column;
  padding:10px;
  gap:10px;
  min-height:0;
}

.iframeWrap{
  flex:1;
  min-height:0;
  background:#0b0f12;
  border-radius:18px;
  overflow:hidden;
  box-shadow:0 10px 30px rgba(0,0,0,0.35);
}

.iframeWrap iframe{
  border:0;
  width:100%;
  height:100%;
  display:block;
}

.currentBar{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  padding:10px 12px;
  border-radius:14px;
  background:rgba(255,255,255,0.03);
  border:1px solid rgba(255,255,255,0.03);
}
.currentBar .left{
  min-width:0;
  display:flex;
  flex-direction:column;
}
.currentBar .roomName{
  font-weight:800;
  font-size:14px;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
}
.currentBar .roomMeta{
  color:var(--muted);
  font-size:12px;
}

.roomBtnRow{
  display:flex;
  gap:8px;
}
.pillBtn{
  background:rgba(255,255,255,0.04);
  padding:10px 12px;
  min-width:92px;
  font-weight:700;
}

.sheetOverlay{
  position:fixed;
  inset:0;
  background:rgba(0,0,0,0.45);
  z-index:60;
  display:none;
}

.sheet{
  position:fixed;
  left:0;
  right:0;
  bottom:0;
  z-index:70;
  transform:translateY(110%);
  transition:transform .22s ease;
  background:linear-gradient(180deg, rgba(15,21,24,0.98), rgba(11,15,18,0.98));
  border-top-left-radius:20px;
  border-top-right-radius:20px;
  box-shadow:0 -18px 60px rgba(0,0,0,0.5);
  max-height:82vh;
  display:flex;
  flex-direction:column;
}

.sheet.open{ transform:translateY(0); }
.sheetOverlay.open{ display:block; }

.sheetHandle{
  width:44px;
  height:5px;
  border-radius:999px;
  background:rgba(255,255,255,0.22);
  margin:10px auto 8px;
}

.sheetHead{
  padding:0 14px 10px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
}

.sheetHead .sheetTitle{
  font-weight:800;
  font-size:16px;
}

.sheetSearchWrap{
  padding:0 14px 12px;
}
.sheetSearch{
  width:100%;
  background:#0b0f12;
  border:1px solid rgba(255,255,255,0.05);
  color:#fff;
  padding:12px 14px;
  border-radius:14px;
  outline:none;
}

.sheetBody{
  padding:0 12px 12px;
  overflow:auto;
  min-height:0;
}

.sectionLabel{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  color:var(--muted);
  font-size:12px;
  text-transform:uppercase;
  letter-spacing:.08em;
  padding:10px 6px 8px;
}

.channelList{
  display:flex;
  flex-direction:column;
  gap:8px;
  margin-bottom:10px;
}

.channelItem{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  padding:13px 14px;
  border-radius:14px;
  background:rgba(255,255,255,0.03);
  cursor:pointer;
  user-select:none;
  border:1px solid transparent;
}

.channelItem .name{
  font-weight:700;
  min-width:0;
  overflow:hidden;
  text-overflow:ellipsis;
  white-space:nowrap;
}

.channelItem .meta{
  color:var(--muted);
  font-size:12px;
  flex:0 0 auto;
}

.channelItem.active{
  background:rgba(63,123,255,0.16);
  border-color:rgba(63,123,255,0.25);
}

.channelItem.locked{
  opacity:.55;
}

.channelItem.hiddenRoom{
  background:rgba(255,255,255,0.02);
}

.channelItem.voiceRoom{
  background:rgba(70,120,255,0.08);
}

.channelItem.disabled{
  cursor:not-allowed;
}

.formCard{
  padding:12px;
  border-radius:16px;
  background:rgba(255,255,255,0.03);
  border:1px solid rgba(255,255,255,0.03);
}

.formRow{ margin-bottom:8px; }
.select, .input{
  width:100%;
  padding:12px 12px;
  border-radius:12px;
  border:0;
  background:#0b0f12;
  color:#fff;
  outline:none;
}
.checkRow{
  display:flex;
  align-items:center;
  gap:10px;
  color:var(--text);
  font-size:14px;
}
.checkRow input{ width:18px;height:18px; }

.rolesRow{
  display:flex;
  flex-wrap:wrap;
  gap:6px;
}
.rolePill{
  display:inline-block;
  padding:6px 8px;
  border-radius:999px;
  font-size:12px;
  color:#000;
  font-weight:700;
}

.smallNote{
  color:var(--muted);
  font-size:12px;
  line-height:1.35;
}

.notifDrawer{
  position:fixed;
  top:64px;
  left:10px;
  right:10px;
  z-index:65;
  display:none;
  max-height:60vh;
  overflow:auto;
  background:#0d0d0e;
  border:1px solid rgba(255,255,255,0.05);
  border-radius:16px;
  box-shadow:0 16px 44px rgba(0,0,0,0.5);
}

.notifRow{
  padding:12px;
  border-bottom:1px solid rgba(255,255,255,0.03);
}
.notifRow:last-child{ border-bottom:0; }

.notifTitle{
  font-weight:700;
  margin-bottom:4px;
}
.notifMsg{
  color:var(--muted);
  font-size:13px;
}

@media (min-width:900px){
  .viewer{
    padding:14px;
  }
  .currentBar{
    margin:0 2px;
  }
  .sheet{
    width:430px;
    left:12px;
    right:auto;
    bottom:12px;
    top:72px;
    border-radius:20px;
    max-height:none;
    transform:translateX(-110%);
  }
  .sheet.open{ transform:translateX(0); }
  .sheetHandle{ display:none; }
  .sheetOverlay{ display:none !important; }
  .notifDrawer{
    top:72px;
    left:auto;
    right:12px;
    width:380px;
  }
}
</style>
</head>
<body>
<div class="app" id="app">
  <header class="header">
    <div class="brand">
      <button id="backBtn" class="backBtn" title="Back">◀</button>
      <div class="titleWrap">
        <div class="title"><?= e($community['name'] ?? 'Community') ?></div>
        <div class="subtitle"><?= e($community['description'] ?? '') ?></div>
      </div>
    </div>
    <div class="headerActions">
      <?php if ($is_admin): ?>
        <button id="adminBtn" class="ghost">Manage</button>
      <?php endif; ?>
      <button id="notifBtn" class="iconBtn" title="Notifications">🔔<span id="notifBadge" class="badge" style="display:none">0</span></button>
      <button id="openChannelsBtn" class="iconBtn" title="Channels">☰</button>
    </div>
  </header>

  <div class="mainShell">
    <section class="viewer">
      <div class="currentBar">
        <div class="left">
          <div class="roomName" id="currentRoomName"><?= e($selected_code ? ($general['name'] ?? 'Room') : 'No channel selected') ?></div>
          <div class="roomMeta" id="currentRoomMeta">Tap channels to switch rooms</div>
        </div>
        <div class="roomBtnRow">
          <button id="openChannelsBtn2" class="pillBtn">Channels</button>
        </div>
      </div>

      <div class="iframeWrap" id="iframeWrap" aria-live="polite">
        <?php if (!empty($selected_code)): ?>
          <iframe id="chatFrame" src="<?= ($selected_kind === 'voice') ? 'mobile_private.php?code=' . rawurlencode($selected_code) : 'mobile_private.php?code=' . rawurlencode($selected_code) ?>" allow="clipboard-write"></iframe>
        <?php else: ?>
          <div style="padding:20px;color:var(--muted)">You do not currently have access to any channels in this community.</div>
        <?php endif; ?>
      </div>
    </section>
  </div>

  <div class="sheetOverlay" id="sheetOverlay"></div>

  <aside class="sheet" id="sheet" aria-hidden="true">
    <div class="sheetHandle"></div>
    <div class="sheetHead">
      <div class="sheetTitle">Channels</div>
      <button id="closeSheetBtn" class="ghost" style="padding:8px 12px">Close</button>
    </div>

    <div class="sheetSearchWrap">
      <input id="channelSearch" class="sheetSearch" type="text" placeholder="Search channels">
    </div>

    <div class="sheetBody">
      <div class="sectionLabel">
        <span>Rooms</span>
        <span><?= count($regular_channels) ?></span>
      </div>
      <div class="channelList" id="regularList">
        <?php if (empty($regular_channels)): ?>
          <div class="smallNote" style="padding:8px 6px">No channels yet</div>
        <?php else: foreach ($regular_channels as $ch):
            $can = user_can_view_channel($ch['required_role_id'], $me_id, $community_id, $pdo, $is_owner, $is_admin);
            $active = ($selected_code && (string)$selected_code === (string)$ch['code']);
        ?>
          <div class="channelItem roomItem <?= $active ? 'active' : '' ?> <?= $can ? '' : 'locked disabled' ?>"
               data-code="<?= e($ch['code']) ?>"
               data-kind="text"
               data-name="<?= e(strtolower($ch['name'])) ?>"
               data-hidden="0"
               data-voice="0"
               data-locked="<?= $can ? '0' : '1' ?>">
            <div class="name"><?= e($ch['name']) ?></div>
            <div class="meta"><?= $ch['required_role_id'] ? 'Locked' : 'Public' ?></div>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <?php if (!empty($voice_channels)): ?>
        <div class="sectionLabel">
          <span>Voice chats</span>
          <span><?= count($voice_channels) ?></span>
        </div>
        <div class="channelList" id="voiceList">
          <?php foreach ($voice_channels as $ch):
              $can = user_can_view_channel($ch['required_role_id'], $me_id, $community_id, $pdo, $is_owner, $is_admin);
              $active = ($selected_code && (string)$selected_code === (string)$ch['code']);
          ?>
            <div class="channelItem roomItem voiceRoom <?= $active ? 'active' : '' ?> <?= $can ? '' : 'locked disabled' ?>"
                 data-code="<?= e($ch['code']) ?>"
                 data-kind="voice"
                 data-name="<?= e(strtolower($ch['name'])) ?>"
                 data-hidden="0"
                 data-voice="1"
                 data-locked="<?= $can ? '0' : '1' ?>">
              <div class="name"><?= e($ch['name']) ?></div>
              <div class="meta"><?= $ch['required_role_id'] ? 'Locked' : 'Public' ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($visible_hidden_channels)): ?>
        <div class="sectionLabel">
          <span>Hidden rooms</span>
          <span><?= count($visible_hidden_channels) ?></span>
        </div>
        <div class="channelList" id="hiddenList">
          <?php foreach ($visible_hidden_channels as $ch):
              $can = user_can_view_channel($ch['required_role_id'], $me_id, $community_id, $pdo, $is_owner, $is_admin);
              $active = ($selected_code && (string)$selected_code === (string)$ch['code']);
              $isVoice = !empty($ch['is_voice']);
          ?>
            <div class="channelItem roomItem hiddenRoom <?= $active ? 'active' : '' ?> <?= $can ? '' : 'locked disabled' ?>"
                 data-code="<?= e($ch['code']) ?>"
                 data-kind="<?= $isVoice ? 'voice' : 'text' ?>"
                 data-name="<?= e(strtolower($ch['name'])) ?>"
                 data-hidden="1"
                 data-voice="<?= $isVoice ? '1' : '0' ?>"
                 data-locked="<?= $can ? '0' : '1' ?>">
              <div class="name"><?= e($ch['name']) ?></div>
              <div class="meta"><?= $isVoice ? 'Hidden voice' : 'Hidden' ?><?= $ch['required_role_id'] ? ' · Locked' : '' ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="sectionLabel">
        <span>Quick actions</span>
        <span>+</span>
      </div>

      <?php if ($is_admin): ?>
        <div class="formCard">
          <form id="newChannelForm">
            <div class="formRow">
              <input name="name" placeholder="channel name" required class="input">
            </div>
            <div class="formRow">
              <select name="required_role_id" class="select">
                <option value="">Public (no role)</option>
                <?php foreach ($roles as $r): ?>
                  <option value="<?= (int)$r['id'] ?>"><?= e($r['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="formRow">
              <select name="room_type" class="select">
                <option value="text">Text room</option>
                <option value="voice">Voice chat</option>
              </select>
            </div>
            <div class="formRow">
              <label class="checkRow">
                <input type="checkbox" name="is_hidden" value="1">
                <span>Hidden room</span>
              </label>
            </div>
            <div>
              <button class="btn" type="submit" style="width:100%">Create</button>
            </div>
          </form>
        </div>
      <?php else: ?>
        <div class="smallNote" style="padding:4px 6px">Ask a community manager to add channels.</div>
      <?php endif; ?>

      <div class="sectionLabel">
        <span>Your roles</span>
        <span><?= count($user_roles_for_me) ?></span>
      </div>
      <div class="rolesRow" style="padding:0 4px 8px">
        <?php if (!empty($user_roles_for_me)): ?>
          <?php foreach ($user_roles_for_me as $rid): $r = $roleMap[$rid] ?? null; if (!$r) continue; ?>
            <span class="rolePill" style="background:<?= e($r['color'] ?? '#ddd') ?>"><?= e($r['name']) ?></span>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="smallNote">No roles</div>
        <?php endif; ?>
      </div>
    </div>
  </aside>

  <div id="notifDrawer" class="notifDrawer"></div>
</div>

<script>
const COMMUNITY_ID = <?= json_encode($community_id) ?>;
const IS_ADMIN = <?= $is_admin ? 'true' : 'false' ?>;
const IS_OWNER = <?= $is_owner ? 'true' : 'false' ?>;
const ME_ID = <?= json_encode($me_id) ?>;
const ROLES = <?= $roles_json ?>;
const USER_ROLES_ME = <?= $user_roles_json ?>;
const CHANNELS = <?= $channels_json ?>;
const COMMUNITY_PUBLIC_ID = <?= json_encode($community['public_id'] ?? '') ?>;

const sheet = document.getElementById('sheet');
const sheetOverlay = document.getElementById('sheetOverlay');
const openChannelsBtn = document.getElementById('openChannelsBtn');
const openChannelsBtn2 = document.getElementById('openChannelsBtn2');
const closeSheetBtn = document.getElementById('closeSheetBtn');
const channelSearch = document.getElementById('channelSearch');
const iframeWrap = document.getElementById('iframeWrap');
const chatFrame = document.getElementById('chatFrame');
const notifBtn = document.getElementById('notifBtn');
const notifBadge = document.getElementById('notifBadge');
const notifDrawer = document.getElementById('notifDrawer');
const adminBtn = document.getElementById('adminBtn');
const backBtn = document.getElementById('backBtn');
const currentRoomName = document.getElementById('currentRoomName');
const currentRoomMeta = document.getElementById('currentRoomMeta');
const newChannelForm = document.getElementById('newChannelForm');

function escapeHtml(s){ if (!s) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function openSheet() {
  sheet.classList.add('open');
  sheetOverlay.classList.add('open');
  sheet.setAttribute('aria-hidden','false');
  if (channelSearch) setTimeout(()=>channelSearch.focus(), 50);
}
function closeSheet() {
  sheet.classList.remove('open');
  sheetOverlay.classList.remove('open');
  sheet.setAttribute('aria-hidden','true');
}
openChannelsBtn?.addEventListener('click', openSheet);
openChannelsBtn2?.addEventListener('click', openSheet);
closeSheetBtn?.addEventListener('click', closeSheet);
sheetOverlay?.addEventListener('click', closeSheet);

backBtn.addEventListener('click', ()=> {
  location.href = 'room.php';
});
adminBtn?.addEventListener('click', ()=> {
  location.href = 'community_admin.php?public_id=' + encodeURIComponent(COMMUNITY_PUBLIC_ID);
});

function setCurrentRoom(label, meta) {
  if (currentRoomName) currentRoomName.textContent = label || 'Channel';
  if (currentRoomMeta) currentRoomMeta.textContent = meta || '';
}

function loadRoom(code, kind, name, hidden, locked) {
  if (!code) return;
  if (locked === '1') {
    alert('You do not have permission to view this channel.');
    return;
  }
  const src = 'mobile_private.php?code=' + encodeURIComponent(code);
  if (chatFrame) chatFrame.src = src;
  else {
    const ifr = document.createElement('iframe');
    ifr.id = 'chatFrame';
    ifr.src = src;
    ifr.allow = 'clipboard-write';
    ifr.style.border = '0';
    ifr.style.width = '100%';
    ifr.style.height = '100%';
    iframeWrap.innerHTML = '';
    iframeWrap.appendChild(ifr);
  }

  document.querySelectorAll('.roomItem').forEach(el => el.classList.remove('active'));
  const target = document.querySelector(`.roomItem[data-code="${CSS.escape(code)}"]`);
  if (target) target.classList.add('active');

  setCurrentRoom(
    name || 'Channel',
    kind === 'voice'
      ? (hidden === '1' ? 'Hidden voice chat' : 'Voice chat')
      : (hidden === '1' ? 'Hidden room' : 'Text room')
  );
  closeSheet();
}

function wireRoomClicks() {
  document.querySelectorAll('.roomItem').forEach(el => {
    el.addEventListener('click', ()=> {
      const code = el.dataset.code;
      const kind = el.dataset.kind || 'text';
      const name = el.querySelector('.name')?.textContent?.trim() || 'Channel';
      const hidden = el.dataset.hidden || '0';
      const locked = el.dataset.locked || '0';
      loadRoom(code, kind, name, hidden, locked);
    });
  });
}
wireRoomClicks();

channelSearch?.addEventListener('input', ()=> {
  const q = channelSearch.value.trim().toLowerCase();
  document.querySelectorAll('.channelItem').forEach(el => {
    const name = (el.dataset.name || '').toLowerCase();
    el.style.display = (!q || name.includes(q)) ? '' : 'none';
  });
});

if (newChannelForm) {
  newChannelForm.addEventListener('submit', async (ev)=> {
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
        location.reload();
      } else {
        alert('Failed to create channel: ' + (j && j.error ? j.error : 'unknown'));
      }
    } catch (err) {
      console.error(err);
      alert('Network error');
    }
  });
}

// notifications
async function fetchNotifications(limit=10) {
  try {
    const r = await fetch('notifications.php?limit=' + encodeURIComponent(limit), { credentials:'same-origin' });
    if (!r.ok) return null;
    return await r.json();
  } catch (e) { return null; }
}
async function refreshNotifBadge() {
  const j = await fetchNotifications(5);
  if (!j) return;
  const unread = j.unread_count || (Array.isArray(j.notifications) ? j.notifications.filter(n=>!n.is_read).length : 0);
  if (unread > 0) {
    notifBadge.style.display='inline-block';
    notifBadge.textContent = unread > 99 ? '99+' : String(unread);
  } else {
    notifBadge.style.display='none';
  }
}
refreshNotifBadge();
setInterval(refreshNotifBadge, 30000);

notifBtn?.addEventListener('click', async (e)=> {
  e.stopPropagation();
  const j = await fetchNotifications(200);
  notifDrawer.innerHTML = '';
  if (!j || !Array.isArray(j.notifications) || j.notifications.length === 0) {
    notifDrawer.innerHTML = '<div style="padding:12px;color:var(--muted)">No notifications</div>';
  } else {
    j.notifications.forEach(n => {
      const row = document.createElement('div');
      row.className = 'notifRow';
      row.innerHTML = `<div class="notifTitle">${escapeHtml(n.source_username||'System')}</div><div class="notifMsg">${escapeHtml((n.message||'').slice(0,140))}</div>`;
      row.addEventListener('click', async ()=> {
        try { await fetch('notifications.php', { method:'POST', credentials:'same-origin', body: new URLSearchParams({ action:'mark_read', id: n.id }) }); } catch(e){}
        const refCode = n.ref_code || n.ref || n.code || null;
        if (refCode) { location.href = 'mobile_private.php?code=' + encodeURIComponent(refCode); return; }
        if (n.type && String(n.type).indexOf('dm') !== -1 && n.source_username) {
          location.href = 'mobile_message.php?user=' + encodeURIComponent(n.source_username);
          return;
        }
        if (n.ref_id) {
          location.href = 'mobile_private.php?code=' + encodeURIComponent(n.ref_id);
          return;
        }
        location.reload();
      });
      notifDrawer.appendChild(row);
    });
  }
  notifDrawer.style.display = notifDrawer.style.display === 'block' ? 'none' : 'block';
});
document.addEventListener('click', (e)=> {
  if (!e.target.closest || (!e.target.closest('#notifBtn') && !e.target.closest('#notifDrawer'))) {
    notifDrawer.style.display = 'none';
  }
});

// quick iframe load on initial selected room
(function markInitial() {
  const selected = <?= json_encode($selected_code ?: '') ?>;
  const selectedKind = <?= json_encode($selected_kind ?: 'text') ?>;
  if (selected) {
    const nameEl = document.querySelector(`.roomItem[data-code="${CSS.escape(selected)}"] .name`);
    const name = nameEl ? nameEl.textContent.trim() : 'Channel';
    const selectedEl = document.querySelector(`.roomItem[data-code="${CSS.escape(selected)}"]`);
    const hidden = selectedEl ? (selectedEl.dataset.hidden || '0') : '0';
    const locked = selectedEl ? (selectedEl.dataset.locked || '0') : '0';
    loadRoom(selected, selectedKind, name, hidden, locked);
  }
})();

// tap outside sheets
window.addEventListener('keydown', (e)=> { if (e.key === 'Escape') closeSheet(); });
</script>
</body>
</html>
