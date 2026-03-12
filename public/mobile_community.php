<?php
// mobile_community.php - mobile-optimized community view (channels sidebar + mobile_private in iframe)
// Based on community.php but tailored for small screens and mobile links.

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

// Ensure private_rooms has community_id and required_role_id columns
try {
    $cols = $pdo->query("SHOW COLUMNS FROM private_rooms")->fetchAll(PDO::FETCH_COLUMN);
    $cols_l = array_map('strtolower',$cols);
    if (!in_array('community_id', $cols_l)) {
        $pdo->exec("ALTER TABLE private_rooms ADD COLUMN community_id INT DEFAULT NULL");
    }
    if (!in_array('required_role_id', $cols_l)) {
        $pdo->exec("ALTER TABLE private_rooms ADD COLUMN required_role_id INT DEFAULT NULL");
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
    // minimal creation if table missing
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS private_rooms (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(128) NOT NULL UNIQUE,
            name VARCHAR(255) NOT NULL,
            community_id INT DEFAULT NULL,
            required_role_id INT DEFAULT NULL,
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
    $s = $pdo->prepare("SELECT id, code, name, required_role_id FROM private_rooms WHERE community_id = ? ORDER BY id ASC");
    $s->execute([$community_id]);
    $channels = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $channels = []; }

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
        // fallback to singular community_members.role_id for older installs
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
$default_choice = null;
foreach ($channels as $ch) {
    $can = user_can_view_channel($ch['required_role_id'], $me_id, $community_id, $pdo, $is_owner, $is_admin);
    if ($can) $accessible_codes[] = $ch['code'];
    if ($default_choice === null && $can) $default_choice = $ch['code'];
}
if ($selected_code && !in_array($selected_code, $accessible_codes, true)) $selected_code = $default_choice;
if (!$selected_code && $default_choice) $selected_code = $default_choice;

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

?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?= htmlspecialchars($community['name'] ?? 'Community') ?> — Mobile</title>
<link rel="icon" href="root/favicon.ico">
<style>
:root{--bg:#0d1114;--panel:#0f1518;--accent:#3f7bff;--muted:#bfc9d9}
html,body{height:100%;margin:0;background:var(--bg);color:#eef3ff;font-family:Inter,Arial,Helvetica,sans-serif;-webkit-font-smoothing:antialiased}
.header{display:flex;align-items:center;justify-content:space-between;padding:12px;border-bottom:1px solid rgba(255,255,255,0.02)}
.brand{display:flex;gap:10px;align-items:center}
.backBtn{background:transparent;border:0;color:var(--muted);font-size:20px;padding:6px;border-radius:8px}
.title{font-weight:800;font-size:16px}
.container{display:flex;flex-direction:column;height:calc(100vh - 56px);overflow:hidden}
.topInfo{display:flex;align-items:center;gap:12px;padding:12px}
.commLogo{width:64px;height:64px;border-radius:12px;display:flex;align-items:center;justify-content:center;background:linear-gradient(180deg,#3f7bff,#2a59d9);font-weight:800;font-size:22px}
.meta{flex:1}
.small{color:var(--muted);font-size:13px}
.controls{display:flex;gap:8px}
.btn{background:var(--accent);border:0;padding:8px 10px;border-radius:8px;color:#fff;cursor:pointer}
.ghost{background:transparent;border:1px solid rgba(255,255,255,0.04);padding:8px;border-radius:8px;color:var(--muted);cursor:pointer}

/* sidebar as slide-over panel */
.sidebarPanel{position:fixed;left:0;top:56px;bottom:70px;width:86%;max-width:420px;background:var(--panel);z-index:90;transform:translateX(-110%);transition:transform .22s ease;padding:12px;box-sizing:border-box;overflow:auto;box-shadow:0 20px 60px rgba(0,0,0,0.6)}
.sidebarPanel.open{transform:translateX(0)}
.overlay{position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:80;display:none}
.overlay.show{display:block}

/* channel list */
.channelItem{display:flex;justify-content:space-between;align-items:center;padding:12px;border-radius:10px;margin-bottom:8px;background:rgba(255,255,255,0.02);cursor:pointer}
.channelItem.locked{opacity:0.5;cursor:not-allowed}
.channelItem.active{background:linear-gradient(90deg, rgba(63,123,255,0.12), rgba(63,123,255,0.06))}

/* iframe area */
.iframeWrap{flex:1;background:#0b0f12;margin:12px;border-radius:12px;overflow:hidden;min-height:160px;display:flex;align-items:stretch}
iframe{border:0;width:100%;height:100%}

/* create channel form */
.formRow{margin-bottom:8px}
.select{width:100%;padding:10px;border-radius:8px;border:0;background:#0b0f12;color:#fff}

/* moderation menu */
.contextMenu{position:fixed;background:#111;border:1px solid #222;padding:8px;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.6);z-index:9999;display:none}
.contextMenu button{display:block;width:100%;text-align:left;padding:8px;border:0;background:transparent;color:#fff;cursor:pointer;border-radius:6px}
.contextMenu button:hover{background:rgba(255,255,255,0.02)}
.userRolesHover{position:fixed;z-index:10000;background:#111;border:1px solid #222;padding:8px;border-radius:8px;color:#fff;display:none;box-shadow:0 8px 24px rgba(0,0,0,.6)}

/* bottom quickbar */
.quickbar{position:fixed;left:0;right:0;bottom:0;padding:8px;background:linear-gradient(180deg, rgba(0,0,0,0.05), rgba(0,0,0,0.12));display:flex;gap:8px;justify-content:space-between;align-items:center;box-shadow:0 -6px 30px rgba(0,0,0,0.45)}
@media (min-width:700px){ .sidebarPanel{display:none} .quickbar{display:none} }
</style>
</head>
<body>

<header class="header">
  <div class="brand">
    <button id="backBtn" class="backBtn" title="Back">◀</button>
    <div>
      <div class="title"><?= htmlspecialchars($community['name'] ?? 'Community') ?></div>
      <div class="small"><?= htmlspecialchars($community['description'] ?? '') ?></div>
    </div>
  </div>
  <div class="controls">
    <button id="openSidebar" class="ghost" title="Channels">☰</button>
    <button id="notifBtn" class="ghost" title="Notifications">🔔 <span id="notifBadge" class="small" style="display:none;margin-left:6px"></span></button>
  </div>
</header>

<div class="container" id="appContainer">
  <div class="topInfo">
    <div class="commLogo" id="commLogo">
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
    <div class="meta">
      <div style="font-weight:800"><?= htmlspecialchars($community['name']) ?></div>
      <div class="small">Members: <?= (int)($community['member_count'] ?? 0) ?></div>
      <div style="margin-top:8px" class="small">
        <?php if ($is_admin): ?><button id="adminBtn" class="btn">Manage</button><?php endif; ?>
        <button id="goRooms" class="ghost" style="margin-left:6px">Nodes</button>
      </div>
    </div>
  </div>

  <div class="iframeWrap" id="iframeWrap" aria-live="polite">
    <?php if (!empty($selected_code)): ?>
      <iframe id="chatFrame" src="mobile_private.php?code=<?= rawurlencode($selected_code) ?>" allow="clipboard-write"></iframe>
    <?php else: ?>
      <div style="padding:20px;color:var(--muted)">You do not currently have access to any channels in this community.</div>
    <?php endif; ?>
  </div>

  <div style="padding:12px">
    <div class="small">Tap the menu to switch channels or open settings.</div>
  </div>

  <!-- bottom quick bar -->
  <div class="quickbar">
    <button id="openSidebarQuick" class="btn">Channels</button>
    <div>
      <button id="openSettings" class="ghost">Settings</button>
    </div>
  </div>
</div>

<!-- sliding sidebar panel (channels + create channel) -->
<div id="sidebarPanel" class="sidebarPanel" aria-hidden="true">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
    <div style="font-weight:800">Channels</div>
    <button id="closeSidebar" class="backBtn">✖</button>
  </div>

  <div id="channelsList">
    <?php if (empty($channels)): ?>
      <div class="small">No channels yet</div>
    <?php else: foreach($channels as $ch):
          $can = user_can_view_channel($ch['required_role_id'], $me_id, $community_id, $pdo, $is_owner, $is_admin);
    ?>
      <div class="channelItem <?= $can ? '' : 'locked' ?>" data-code="<?= htmlspecialchars($ch['code']) ?>" data-locked="<?= $can ? '0' : '1' ?>">
        <div style="font-weight:700"><?= htmlspecialchars($ch['name']) ?></div>
        <div class="small"><?= $ch['required_role_id'] ? 'Locked' : 'Public' ?></div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <hr style="border:none;border-top:1px solid rgba(255,255,255,0.03);margin:12px 0" />

  <?php if ($is_admin): ?>
    <div style="font-weight:800;margin-bottom:8px">Create channel</div>
    <form id="newChannelForm">
      <div class="formRow"><input name="name" placeholder="channel name" required class="select" /></div>
      <div class="formRow">
        <select name="required_role_id" class="select">
          <option value="">Public (no role)</option>
          <?php foreach ($roles as $r): ?>
            <option value="<?= (int)$r['id'] ?>"><?= htmlspecialchars($r['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><button class="btn" type="submit">Create</button></div>
    </form>
  <?php else: ?>
    <div class="small">Ask a community manager to add channels</div>
  <?php endif; ?>

  <hr style="border:none;border-top:1px solid rgba(255,255,255,0.03);margin:12px 0" />
  <div style="font-weight:800;margin-bottom:8px">Your roles</div>
  <div>
    <?php if (!empty($user_roles_for_me)): ?>
      <?php foreach ($user_roles_for_me as $rid): $r = $roleMap[$rid] ?? null; if (!$r) continue; ?>
        <span class="userRole" style="display:inline-block;background:<?= htmlspecialchars($r['color'] ?? '#ddd') ?>;color:#000;padding:6px 8px;border-radius:8px;margin-right:6px;margin-bottom:6px"><?= htmlspecialchars($r['name']) ?></span>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="small">No roles</div>
    <?php endif; ?>
  </div>
</div>

<div id="overlay" class="overlay" onclick="toggleSidebar(false)"></div>

<!-- moderation context menu (parent-side) -->
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

<!-- notification drawer (mobile) -->
<div id="notifDrawer" class="notifDrawer" style="display:none"></div>

<script>
/* Mobile community client script
   - controls sidebar, channel switching (iframe loads mobile_private.php)
   - admin channel creation (calls community_interface.php?action=create_room)
   - parent->iframe enhancements: rewrite links to mobile equivalents, long-press moderation trigger
*/

const COMMUNITY_ID = <?= json_encode($community_id) ?>;
const IS_ADMIN = <?= $is_admin ? 'true' : 'false' ?>;
const IS_OWNER = <?= $is_owner ? 'true' : 'false' ?>;
const ME_ID = <?= json_encode($me_id) ?>;
const ROLES = <?= $roles_json ?>;
const USER_ROLES_ME = <?= $user_roles_json ?>;
const CHANNELS = <?= $channels_json ?>;

const openSidebar = document.getElementById('openSidebar');
const openSidebarQuick = document.getElementById('openSidebarQuick') || document.getElementById('openSidebarQuick');
const closeSidebar = document.getElementById('closeSidebar');
const sidebarPanel = document.getElementById('sidebarPanel');
const overlayEl = document.getElementById('overlay');
const channelsList = document.getElementById('channelsList');
const iframeWrap = document.getElementById('iframeWrap');
const chatFrame = document.getElementById('chatFrame');
const backBtn = document.getElementById('backBtn');
const goRoomsBtn = document.getElementById('goRooms');
const adminBtn = document.getElementById('adminBtn');
const notifBtn = document.getElementById('notifBtn');
const notifBadge = document.getElementById('notifBadge');

const ctxtMenu = document.getElementById('contextMenu');
const ctxtTitle = document.getElementById('contextMenuTitle');
const userRolesHover = document.getElementById('userRolesHover');
let currentTargetUser = null;

// Sidebar toggle
function toggleSidebar(show) {
  if (show === undefined) show = !sidebarPanel.classList.contains('open');
  if (show) {
    sidebarPanel.classList.add('open');
    overlayEl.classList.add('show');
    sidebarPanel.setAttribute('aria-hidden','false');
  } else {
    sidebarPanel.classList.remove('open');
    overlayEl.classList.remove('show');
    sidebarPanel.setAttribute('aria-hidden','true');
  }
}
openSidebar && openSidebar.addEventListener('click', ()=> toggleSidebar(true));
openSidebarQuick && openSidebarQuick.addEventListener('click', ()=> toggleSidebar(true));
closeSidebar && closeSidebar.addEventListener('click', ()=> toggleSidebar(false));
overlayEl && overlayEl.addEventListener('click', ()=> toggleSidebar(false));

// Back to nodes (mobile)
document.getElementById('backBtn').addEventListener('click', ()=> {
  location.href = 'mobile_room.php';
});
goRoomsBtn && goRoomsBtn.addEventListener('click', ()=> { location.href = 'mobile_room.php'; });
adminBtn && adminBtn.addEventListener('click', ()=> { location.href = 'community_admin.php?public_id=' + encodeURIComponent(<?= json_encode($community['public_id'] ?? '') ?>); });

// Channel switching
function setActiveChannelByCode(code) {
  if (!code) return;
  // mark active
  Array.from(document.querySelectorAll('.channelItem')).forEach(el => {
    if (el.dataset.code === code) el.classList.add('active');
    else el.classList.remove('active');
  });
  // switch iframe src to mobile_private.php
  const url = 'mobile_private.php?code=' + encodeURIComponent(code);
  if (chatFrame) chatFrame.src = url;
  else {
    const ifr = document.createElement('iframe');
    ifr.id = 'chatFrame';
    ifr.src = url;
    ifr.style.border = 0; ifr.style.width = '100%'; ifr.style.height = '100%';
    iframeWrap.innerHTML = '';
    iframeWrap.appendChild(ifr);
  }
  toggleSidebar(false);
}

// wire existing channel elements
Array.from(document.querySelectorAll('.channelItem')).forEach(el => {
  el.addEventListener('click', ()=> {
    if (el.classList.contains('locked')) {
      alert('You do not have permission to view this channel.');
      return;
    }
    const code = el.dataset.code;
    setActiveChannelByCode(code);
  });
});

// Create channel (admin)
const newChannelForm = document.getElementById('newChannelForm');
if (newChannelForm) {
  newChannelForm.addEventListener('submit', async (ev)=> {
    ev.preventDefault();
    const fd = new FormData(newChannelForm);
    fd.append('community_id', COMMUNITY_ID);
    try {
      const res = await fetch('community_interface.php?action=create_room', { method: 'POST', body: fd, credentials:'same-origin' });
      const j = await res.json();
      if (j && j.ok) {
        // append
        const div = document.createElement('div');
        div.className = 'channelItem';
        if (j.required_role_id) div.classList.add('locked');
        div.dataset.code = j.code;
        div.innerHTML = `<div style="font-weight:700">${j.name}</div><div class="small">${j.required_role_id ? 'Locked' : 'Public'}</div>`;
        channelsList.appendChild(div);
        div.addEventListener('click', ()=> {
          if (div.classList.contains('locked')) { alert('Locked'); return; }
          setActiveChannelByCode(j.code);
        });
        newChannelForm.reset();
        alert('Channel created');
      } else {
        alert('Failed to create channel: ' + (j && j.error ? j.error : 'unknown'));
      }
    } catch (err) { console.error(err); alert('Network error'); }
  });
}

// --- Notifications (simple) ---
const NOTIF_API = 'notifications.php';
async function fetchNotifications(limit=10) {
  try {
    const r = await fetch(NOTIF_API + '?limit=' + encodeURIComponent(limit), { credentials:'same-origin' });
    if (!r.ok) return null;
    return await r.json();
  } catch (e) { return null; }
}
async function refreshNotifBadge() {
  const j = await fetchNotifications(5);
  if (!j) return;
  const unread = j.unread_count || (Array.isArray(j.notifications) ? j.notifications.filter(n=>!n.is_read).length : 0);
  if (unread > 0) { notifBadge.style.display='inline-block'; notifBadge.textContent = unread > 99 ? '99+' : String(unread); }
  else { notifBadge.style.display='none'; }
}
refreshNotifBadge();
setInterval(refreshNotifBadge, 30000);
notifBtn && notifBtn.addEventListener('click', async ()=> {
  try {
    const j = await fetchNotifications(200);
    const drawer = document.getElementById('notifDrawer');
    drawer.innerHTML = '';
    if (!j || !Array.isArray(j.notifications) || j.notifications.length === 0) {
      drawer.innerHTML = '<div style="padding:12px;color:var(--muted)">No notifications</div>';
    } else {
      j.notifications.forEach(n => {
        const row = document.createElement('div');
        row.style.padding = '10px'; row.style.borderBottom = '1px solid rgba(255,255,255,0.03)';
        row.innerHTML = `<div style="font-weight:700">${escapeHtml(n.source_username||'System')}</div><div class="small" style="margin-top:6px">${escapeHtml((n.message||'').slice(0,140))}</div>`;
        row.addEventListener('click', async ()=> {
          try { await fetch('notifications.php', { method:'POST', credentials:'same-origin', body: new URLSearchParams({ action:'mark_read', id: n.id }) }); } catch(e){}
          // deep-link rules: prefer ref_code -> dm username -> ref_id
          const refCode = n.ref_code || n.ref || n.code || null;
          if (refCode) { location.href = 'mobile_private.php?code=' + encodeURIComponent(refCode); return; }
          if (n.type && n.type.indexOf('dm') !== -1 && n.source_username) { location.href = 'mobile_message.php?user=' + encodeURIComponent(n.source_username); return; }
          if (n.ref_id) { location.href = 'mobile_private.php?code=' + encodeURIComponent(n.ref_id); return; }
          location.reload();
        });
        drawer.appendChild(row);
      });
    }
    drawer.style.display = drawer.style.display === 'block' ? 'none' : 'block';
  } catch (e) { console.error(e); }
});

// --- Parent -> iframe enhancement (rewrite links & attach long-press moderation) ---
function getIframeDoc() {
  const ifr = document.getElementById('chatFrame');
  if (!ifr) return null;
  try { return ifr.contentDocument || ifr.contentWindow.document; }
  catch (e) { return null; }
}
function rewriteLinkToMobile(href) {
  if (!href) return href;
  // simple replacements
  href = href.replace(/private\.php/gi, 'mobile_private.php');
  href = href.replace(/message\.php/gi, 'mobile_message.php');
  href = href.replace(/room\.php/gi, 'mobile_room.php');
  // preserve absolute urls and others
  return href;
}
function enhanceIframeElements() {
  const doc = getIframeDoc();
  if (!doc) return;
  try {
    // rewrite anchors & ensure top navigation when needed
    const anchors = doc.querySelectorAll('a[href]');
    anchors.forEach(a => {
      try {
        const href = a.getAttribute('href');
        if (!href) return;
        const newHref = rewriteLinkToMobile(href);
        a.setAttribute('href', newHref);
        a.addEventListener('click', (ev) => {
          // open external links normally, internal mobile content open top-level to preserve navigation
          if (newHref.indexOf('mobile_private.php') !== -1 || newHref.indexOf('mobile_message.php') !== -1) {
            // open in top-level so user has full mobile page
            window.top.location.href = newHref;
            ev.preventDefault();
            return;
          }
          // otherwise let default happen
        }, {passive:true});
      } catch(e){}
    });

    // long-press handling for mobile moderation: detect touchstart and if held >600ms fire context
    let touchTimer = null;
    doc.addEventListener('touchstart', function(e) {
      const target = e.target.closest('[data-username], .username, .userLink, .avatarLink, .msgRow');
      if (!target) return;
      touchTimer = setTimeout(()=> {
        let username = target.getAttribute('data-username') || target.getAttribute('data-user') || (target.textContent || '').trim().split(/\s+/)[0];
        if (!username) username = null;
        // try to resolve user id by asking parent endpoint
        fetch('community_interface.php?action=resolve_user&username=' + encodeURIComponent(username || '') + '&community_id=' + encodeURIComponent(COMMUNITY_ID), { credentials:'same-origin' })
          .then(r=>r.json())
          .then(j=>{
            const uid = (j && j.ok && j.user_id) ? j.user_id : null;
            showModerationMenuAt((e.touches && e.touches[0] && e.touches[0].pageX) || 80, (e.touches && e.touches[0] && e.touches[0].pageY) || 120, username, uid);
          }).catch(()=> {
            showModerationMenuAt((e.touches && e.touches[0] && e.touches[0].pageX) || 80, (e.touches && e.touches[0] && e.touches[0].pageY) || 120, username, null);
          });
      }, 600);
    }, true);
    doc.addEventListener('touchend', ()=> { clearTimeout(touchTimer); touchTimer = null; }, true);
    doc.addEventListener('touchmove', ()=> { clearTimeout(touchTimer); touchTimer = null; }, true);

    // show hover roles on tap (quick fetch)
    doc.addEventListener('click', function(e){
      const target = e.target.closest('[data-username], .username, .userLink, .avatarLink');
      if (!target) return;
      const username = target.getAttribute('data-username') || target.getAttribute('data-user') || (target.textContent || '').trim().split(/\s+/)[0];
      if (!username) return;
      // fetch roles
      fetch('community_interface.php?action=get_user_roles_by_name&username=' + encodeURIComponent(username) + '&community_id=' + encodeURIComponent(COMMUNITY_ID), { credentials:'same-origin' })
        .then(r=>r.json())
        .then(j=>{
          if (j && j.ok && Array.isArray(j.roles) && j.roles.length) {
            showUserRolesHover((e.pageX || 40), (e.pageY || 120), j.roles);
            setTimeout(()=> hideUserRolesHover(), 2200);
          }
        }).catch(()=>{});
    }, true);

  } catch (err) {
    // likely cross-origin or content security; fail quietly
    console.warn('enhanceIframeElements:', err);
  }
}

// observe iframe and attach enhancements after load/navigation
function enhanceIframeOnceLoaded() {
  const ifr = document.getElementById('chatFrame');
  if (!ifr) return;
  ifr.addEventListener('load', ()=> setTimeout(enhanceIframeElements, 120));
  // initial attempt
  setTimeout(enhanceIframeElements, 250);
}
enhanceIframeOnceLoaded();
new MutationObserver(() => enhanceIframeOnceLoaded()).observe(document.getElementById('iframeWrap'), { childList:true, subtree:false });

// moderation menu display/interaction
function showModerationMenuAt(x,y, username, userId) {
  currentTargetUser = { id: userId, username: username };
  ctxtTitle.textContent = username || 'User';
  ctxtMenu.style.left = Math.min(window.innerWidth - 220, x) + 'px';
  ctxtMenu.style.top = Math.min(window.innerHeight - 200, y) + 'px';
  ctxtMenu.style.display = 'block';
  ctxtMenu.setAttribute('aria-hidden','false');
}
function hideContextMenu() { ctxtMenu.style.display = 'none'; ctxtMenu.setAttribute('aria-hidden','true'); currentTargetUser = null; }
ctxtMenu.addEventListener('click', async (ev)=> {
  const btn = ev.target.closest('button');
  if (!btn || !currentTargetUser) return;
  const action = btn.getAttribute('data-action');
  hideContextMenu();
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
      reason: 'via mobile context menu'
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

// show user roles hover (parent)
function showUserRolesHover(x,y, rolesArray) {
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

// close context menu on background tap
document.addEventListener('click', (e)=> { if (!e.target.closest || !e.target.closest('#contextMenu')) hideContextMenu(); });

// utility
function escapeHtml(s) { if (!s) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// initial active channel marking (if selected_code present)
(function markInitial() {
  const selected = <?= json_encode($selected_code ?: '') ?>;
  if (selected) {
    setTimeout(()=> setActiveChannelByCode(selected), 300);
  }
})();

</script>
</body>
</html>
