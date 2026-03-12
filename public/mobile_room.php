<?php
// mobile_room.php - mobile-friendly hub for rooms & communities (infinite scroll + saved rooms + notifications + create community)
// Based on desktop room.php features, adapted for small screens.
// Requires: config.php + room.php's communities_page endpoint, notifications.php, community_interface.php

require "config.php";

// --- auth ---
if (empty($_COOKIE['auth_token'])) { header("Location:index.php"); exit; }
$stmt = $pdo->prepare("SELECT id, username, avatar FROM users WHERE auth_token = ?");
$stmt->execute([$_COOKIE['auth_token']]);
$me = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$me) { header("Location:index.php"); exit; }
$me_id = (int)$me['id'];
$me_username = $me['username'];

// --- re-use small endpoints for add_room/list_rooms that live on room.php (mobile will call room.php?action=...) ---
// friend list
$friends = [];
try {
    $sql = "
      SELECT u.id,u.username,u.avatar
      FROM users u
      WHERE u.id IN (
        SELECT CASE WHEN f.user_a = :me THEN f.user_b ELSE f.user_a END
        FROM friendships f
        WHERE (f.user_a = :me OR f.user_b = :me) AND f.status = 'friends'
      )
      ORDER BY u.username
      LIMIT 200
    ";
    $st = $pdo->prepare($sql);
    $st->execute([':me'=>$me_id]);
    $friends = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $friends = [];
}

// saved rooms (we'll refresh client-side via room.php?action=list_rooms)
$saved_rooms = [];
try {
    $stmt = $pdo->prepare("SELECT id, name, room_code FROM user_rooms WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$me_id]);
    $saved_rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $saved_rooms = [];
}

?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Rooms — <?= htmlspecialchars($me_username) ?></title>
<link rel="icon" href="root/favicon.ico">
<style>
:root{--bg:#0d1114;--panel:#0f1518;--accent:#3f7bff;--muted:#bfc9d9}
html,body{height:100%;margin:0;background:var(--bg);color:#eef3ff;font-family:Inter,Arial,Helvetica,sans-serif;-webkit-font-smoothing:antialiased}
.app{display:flex;flex-direction:column;height:100vh;overflow:hidden}
.header{display:flex;align-items:center;justify-content:space-between;padding:12px 14px;border-bottom:1px solid rgba(255,255,255,0.02)}
.brand{display:flex;align-items:center;gap:10px}
.hambtn{background:transparent;border:0;color:var(--muted);font-size:20px;padding:8px;border-radius:8px}
.title{font-weight:800;font-size:18px}
.rightControls{display:flex;align-items:center;gap:8px}

/* main content area */
.content{flex:1;overflow:auto;-webkit-overflow-scrolling:touch;padding:12px;box-sizing:border-box}

/* communities grid */
.grid{display:grid;grid-template-columns:repeat(2, 1fr);gap:18px}
.communityCard{display:flex;flex-direction:column;align-items:center;gap:8px;padding:12px;border-radius:12px;background:linear-gradient(180deg, rgba(255,255,255,0.02), transparent);cursor:pointer;min-height:120px;justify-content:center}
.communityCircle{width:96px;height:96px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;color:#fff;box-shadow:0 10px 30px rgba(0,0,0,0.4)}
.communityCircle img{width:100%;height:100%;object-fit:cover;border-radius:50%}
.communityName{font-weight:700;text-align:center;font-size:13px;color:#eef3ff;max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

/* make circles varied but consistent */
.circleDefault{background:linear-gradient(180deg,#3f7bff,#2a59d9)}

/* large central "General" node */
.centerNode{display:flex;align-items:center;justify-content:center;margin:18px auto 6px;width:140px;height:140px;border-radius:50%;background:linear-gradient(180deg,#3f7bff,#2a59d9);font-size:16px;font-weight:800;box-shadow:0 12px 40px rgba(0,0,0,0.5);cursor:pointer}
.centerHint{color:var(--muted);text-align:center;margin-bottom:10px;font-size:13px}

/* small utility list for friends and saved rooms (accessible via sidebar) */
.sidebarPanel{position:fixed;left:0;top:0;bottom:0;width:86%;max-width:360px;background:var(--panel);z-index:80;transform:translateX(-110%);transition:transform .22s ease;padding:12px;box-sizing:border-box;box-shadow:0 20px 60px rgba(0,0,0,0.6)}
.sidebarPanel.open{transform:translateX(0)}
.overlay{position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:70;display:none}
.overlay.show{display:block}
.sideHeader{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
.friendItem{display:flex;gap:10px;align-items:center;padding:8px;border-radius:8px;background:rgba(255,255,255,0.02);cursor:pointer;margin-bottom:6px}
.friendItem img{width:44px;height:44px;border-radius:8px;object-fit:cover;border:2px solid #0b0f12}
.savedRooms{margin-top:12px}
.roomItem{padding:8px;border-radius:8px;background:rgba(255,255,255,0.02);cursor:pointer;margin-bottom:6px}

/* bottom quickbar */
.quickbar{position:fixed;left:0;right:0;bottom:0;padding:8px 12px;background:linear-gradient(180deg, rgba(0,0,0,0.05), rgba(0,0,0,0.12));display:flex;gap:8px;justify-content:space-between;align-items:center;box-shadow:0 -6px 30px rgba(0,0,0,0.45)}
.topBtn{background:var(--accent);color:#fff;border:0;border-radius:8px;padding:10px 12px;cursor:pointer;font-weight:700}
.smallBtn{background:transparent;border:1px solid rgba(255,255,255,0.04);color:var(--muted);padding:8px 10px;border-radius:8px}

/* notification drawer */
.notifDrawer{position:fixed;left:8px;right:8px;top:72px;max-height:64vh;background:#0b0f12;border-radius:10px;padding:8px;box-shadow:0 10px 40px rgba(0,0,0,0.6);z-index:90;display:none;overflow:auto}
.notifRow{display:flex;gap:8px;align-items:center;padding:8px;border-bottom:1px solid rgba(255,255,255,0.03)}
.notifRow:last-child{border-bottom:0}
.notifAvatar{width:44px;height:44px;border-radius:8px;background:#222;display:flex;align-items:center;justify-content:center;color:#fff;flex:0 0 44px}
.notifMeta{flex:1;min-width:0}
.notifTitle{font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.notifMsg{color:var(--muted);font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.badge{background:#ff4d4f;color:#fff;border-radius:999px;padding:4px 8px;font-weight:700;font-size:12px}

/* modest responsiveness */
@media (min-width:600px){
  .grid{grid-template-columns:repeat(3,1fr)}
}
</style>
</head>
<body>
<div class="app" role="application" aria-label="Rooms hub">

  <header class="header" role="banner">
    <div class="brand">
      <button id="openSidebarBtn" class="hambtn" aria-label="Open menu">☰</button>
      <div class="title">Rooms</div>
    </div>
    <div class="rightControls" role="navigation" aria-label="Quick actions">
      <button id="createCommunityToggle" class="smallBtn" aria-haspopup="dialog" title="Create community">➕ Community</button>
      <button id="openNotifBtn" class="smallBtn" aria-expanded="false" title="Notifications">🔔 <span id="topNotifCount" class="badge" style="display:none;margin-left:8px">0</span></button>
    </div>
  </header>

  <main class="content" id="content" role="main">
    <!-- Large central general node -->
    <div style="display:flex;flex-direction:column;align-items:center;margin-bottom:6px">
      <div class="centerNode" id="generalNode" title="Open #general"># general</div>
      <div class="centerHint">Tap to open the mobile general chat</div>
    </div>

    <!-- Communities grid -->
    <div id="communitiesGrid" class="grid" aria-live="polite" aria-busy="false">
      <!-- items appended by JS -->
    </div>

    <div id="loadingMore" style="padding:12px;text-align:center;color:var(--muted);display:none">Loading…</div>
    <div id="endOfList" style="padding:12px;text-align:center;color:var(--muted);display:none">No more communities</div>
  </main>

  <!-- bottom quickbar -->
  <div class="quickbar" role="toolbar">
    <button id="openSidebarQuick" class="topBtn">Friends & Rooms</button>
    <div style="display:flex;gap:8px">
      <button id="openDesktop" class="smallBtn" onclick="location.href='room.php'">Desktop</button>
      <button id="openSettings" class="smallBtn" onclick="location.href='settings.php'">Settings</button>
    </div>
  </div>

  <!-- sidebar panel -->
  <div id="sidebarPanel" class="sidebarPanel" role="menu" aria-hidden="true">
    <div class="sideHeader">
      <div style="font-weight:800">Friends</div>
      <button id="closeSidebarBtn" class="hambtn" aria-label="Close menu">✖</button>
    </div>

    <div id="friendsList">
      <?php foreach($friends as $f): ?>
        <div class="friendItem" data-username="<?= htmlspecialchars($f['username']) ?>">
          <?php if (!empty($f['avatar'])): ?>
            <?php if (stripos($f['avatar'],'http') === 0): ?>
              <img src="<?= htmlspecialchars($f['avatar'],ENT_QUOTES) ?>" alt="">
            <?php else: ?>
              <img src="avatars/<?= rawurlencode($f['avatar']) ?>" alt="">
            <?php endif; ?>
          <?php else: ?>
            <img src="root/default_avatar.png" alt="">
          <?php endif; ?>
          <div style="font-weight:700"><?= htmlspecialchars($f['username']) ?></div>
        </div>
      <?php endforeach; ?>
      <?php if (empty($friends)): ?>
        <div style="color:var(--muted)">No friends yet</div>
      <?php endif; ?>
    </div>

    <div style="margin-top:12px;font-weight:800">Saved Rooms</div>
    <div id="savedRoomsList" class="savedRooms">
      <?php if (!empty($saved_rooms)): foreach($saved_rooms as $r): ?>
        <div class="roomItem" data-code="<?= htmlspecialchars($r['room_code']) ?>"><?= htmlspecialchars($r['name']) ?></div>
      <?php endforeach; else: ?>
        <div style="color:var(--muted)">No saved rooms</div>
      <?php endif; ?>
    </div>

    <div style="margin-top:12px">
      <div style="font-size:13px;color:var(--muted);margin-bottom:8px">Add a room</div>
      <form id="addRoomForm">
        <input name="name" placeholder="Room display name" style="width:100%;padding:8px;border-radius:8px;border:0;margin-bottom:8px;background:#0b0f12;color:#fff" required>
        <input name="code" placeholder="Room code" style="width:100%;padding:8px;border-radius:8px;border:0;margin-bottom:8px;background:#0b0f12;color:#fff" required>
        <button style="width:100%;padding:8px;border-radius:8px;background:var(--accent);border:0;margin-bottom:14px;color:#fff" type="submit">Save</button>
      </form>
    </div>
  </div>

  <div id="overlay" class="overlay" onclick="toggleSidebar(false)"></div>

  <!-- notifications drawer -->
  <div id="notifDrawer" class="notifDrawer" role="dialog" aria-hidden="true" aria-label="Notifications">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
      <div style="font-weight:800">Notifications</div>
      <button id="markAllBtn" class="smallBtn">Mark all read</button>
    </div>
    <div id="notifList" style="display:flex;flex-direction:column;gap:6px">
      <div style="color:var(--muted);padding:8px">Loading…</div>
    </div>
  </div>

  <!-- create community modal -->
  <div id="createCommunityModal" style="display:none;position:fixed;inset:0;z-index:120;align-items:center;justify-content:center;background:rgba(0,0,0,0.5)">
    <div style="width:92%;max-width:420px;background:#0f1214;border-radius:10px;padding:14px">
      <div style="font-weight:800;margin-bottom:8px">Create community</div>
      <form id="createCommunityForm">
        <input name="name" placeholder="Community name" required style="width:100%;padding:8px;border-radius:8px;border:0;background:#090b0d;color:#fff;margin-bottom:8px">
        <input name="slug" placeholder="Optional slug" style="width:100%;padding:8px;border-radius:8px;border:0;background:#090b0d;color:#fff;margin-bottom:8px">
        <textarea name="description" placeholder="Optional description" style="width:100%;padding:8px;border-radius:8px;border:0;background:#090b0d;color:#fff;margin-bottom:8px;height:80px"></textarea>
        <div style="display:flex;gap:8px">
          <button class="topBtn" type="submit" style="flex:1">Create</button>
          <button id="cancelCreateCommunity" type="button" class="smallBtn" style="flex:0 0 auto">Cancel</button>
        </div>
      </form>
    </div>
  </div>

</div>

<script>
/* mobile_room.js - client logic
   - loads communities paged (room.php?action=communities_page&page=N)
   - displays them in a roomy grid (avoids clustering via spacing)
   - lazy-load more on scroll near bottom
   - sidebar for friends & saved rooms
   - notifications drawer that deep-links (mobile_message.php or mobile_private.php)
   - create community modal posts to community_interface.php?action=create_community
*/

const content = document.getElementById('content');
const communitiesGrid = document.getElementById('communitiesGrid');
const loadingMore = document.getElementById('loadingMore');
const endOfList = document.getElementById('endOfList');
const openSidebarBtn = document.getElementById('openSidebarBtn');
const sidebarPanel = document.getElementById('sidebarPanel');
const overlayEl = document.getElementById('overlay');
const closeSidebarBtn = document.getElementById('closeSidebarBtn');
const openSidebarQuick = document.getElementById('openSidebarQuick');
const savedRoomsList = document.getElementById('savedRoomsList');
const addRoomForm = document.getElementById('addRoomForm');
const generalNode = document.getElementById('generalNode');

const notifDrawer = document.getElementById('notifDrawer');
const openNotifBtn = document.getElementById('openNotifBtn');
const topNotifCount = document.getElementById('topNotifCount');
const notifList = document.getElementById('notifList');
const markAllBtn = document.getElementById('markAllBtn');

const createCommunityToggle = document.getElementById('createCommunityToggle');
const createCommunityModal = document.getElementById('createCommunityModal');
const createCommunityForm = document.getElementById('createCommunityForm');
const cancelCreateCommunity = document.getElementById('cancelCreateCommunity');

let communitiesPage = 1;
let loadingCommunities = false;
let lastPageReached = false;
const COMM_PAGE_SIZE = 10;
const COMM_API = 'room.php?action=communities_page';
const NOTIF_API = 'notifications.php';
const POLL_MS = 30000;

// -------- utilities ----------
function el(tag, attrs={}, children=[]) {
  const e = document.createElement(tag);
  for (const k in attrs) {
    if (k === 'class') e.className = attrs[k];
    else if (k === 'html') e.innerHTML = attrs[k];
    else e.setAttribute(k, attrs[k]);
  }
  (Array.isArray(children) ? children : [children]).forEach(ch => { if (ch) e.appendChild(ch); });
  return e;
}
function esc(s){ return s===null ? '' : String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// -------- communities load & render ----------
async function loadCommunitiesPage(page=1) {
  if (loadingCommunities || lastPageReached) return;
  loadingCommunities = true;
  loadingMore.style.display = 'block';
  try {
    const resp = await fetch(COMM_API + '&page=' + encodeURIComponent(page), { credentials: 'same-origin' });
    if (!resp.ok) throw new Error('fetch failed');
    const j = await resp.json();
    if (!j || !j.ok || !Array.isArray(j.rows) || j.rows.length === 0) {
      lastPageReached = true;
      endOfList.style.display = 'block';
      loadingMore.style.display = 'none';
      loadingCommunities = false;
      return;
    }
    for (const c of j.rows) {
      if (document.querySelector('.communityCard[data-id="'+c.id+'"]')) continue;
      const card = el('div',{class:'communityCard', 'data-id': c.id});
      const circle = el('div',{class:'communityCircle circleDefault'});
      if (c.logo) {
        const img = document.createElement('img');
        img.loading = 'lazy';
        // allow absolute http(s) or local uploads
        if (/^https?:\/\//i.test(c.logo)) img.src = c.logo;
        else img.src = 'uploads/community_logos/' + encodeURIComponent(c.logo);
        circle.appendChild(img);
      } else {
        // initials
        const initials = (c.name || '').trim().split(/\s+/).map(p=>p[0]).slice(0,2).join('').toUpperCase() || '?';
        circle.textContent = initials;
      }
      const name = el('div',{class:'communityName'}, document.createTextNode(c.name || 'Community'));
      card.appendChild(circle);
      card.appendChild(name);
      card.addEventListener('click', ()=> {
        if (c.public_id) location.href = 'mobile_community.php?public_id=' + encodeURIComponent(c.public_id);
        else location.href = 'mobile_community.php?id=' + encodeURIComponent(c.id);
      });
      communitiesGrid.appendChild(card);
    }
    communitiesPage = page;
    loadingMore.style.display = 'none';
    loadingCommunities = false;
  } catch (e) {
    console.error('loadCommunitiesPage', e);
    loadingMore.style.display = 'none';
    loadingCommunities = false;
  }
}

// infinite scroll trigger
content.addEventListener('scroll', ()=> {
  const el = content;
  if (!el) return;
  const nearBottom = (el.scrollTop + el.clientHeight) / el.scrollHeight > 0.78;
  if (nearBottom && !loadingCommunities && !lastPageReached) {
    loadCommunitiesPage(communitiesPage + 1);
  }
});

// initial load
loadCommunitiesPage(1);

// -------- general node & saved rooms --------
generalNode.addEventListener('click', ()=> {
  location.href = 'mobile_private.php?code=general';
});

// saved rooms wiring (client refresh)
async function refreshSavedRooms() {
  try {
    const res = await fetch('room.php?action=list_rooms', { credentials: 'same-origin' });
    if (!res.ok) throw new Error('failed');
    const rows = await res.json();
    const container = document.getElementById('savedRoomsList');
    container.innerHTML = '';
    if (!rows || rows.length === 0) {
      container.innerHTML = '<div style="color:var(--muted)">No saved rooms</div>';
      return;
    }
    for (const r of rows) {
      const it = el('div',{class:'roomItem'}, document.createTextNode(r.name || 'Room'));
      it.addEventListener('click', ()=> location.href = 'mobile_private.php?code=' + encodeURIComponent(r.room_code || r.id));
      container.appendChild(it);
    }
  } catch (e) {
    console.error('refreshSavedRooms', e);
  }
}
refreshSavedRooms();

// add room form
addRoomForm.addEventListener('submit', async (ev) => {
  ev.preventDefault();
  const fd = new FormData(addRoomForm);
  try {
    const r = await fetch('room.php?action=add_room', { method:'POST', body: fd, credentials:'same-origin' });
    const j = await r.json();
    if (j && j.ok) {
      addRoomForm.reset();
      await refreshSavedRooms();
      alert('Saved');
    } else {
      alert('Failed to save room');
    }
  } catch (e) {
    console.error(e);
    alert('Network error');
  }
});

// -------- sidebar interactions --------
function toggleSidebar(open) {
  if (open === undefined) open = !sidebarPanel.classList.contains('open');
  if (open) {
    sidebarPanel.classList.add('open');
    overlayEl.classList.add('show');
    sidebarPanel.setAttribute('aria-hidden','false');
  } else {
    sidebarPanel.classList.remove('open');
    overlayEl.classList.remove('show');
    sidebarPanel.setAttribute('aria-hidden','true');
  }
}
openSidebarBtn.addEventListener('click', ()=> toggleSidebar(true));
openSidebarQuick.addEventListener('click', ()=> toggleSidebar(true));
closeSidebarBtn.addEventListener('click', ()=> toggleSidebar(false));
overlayEl.addEventListener('click', ()=> toggleSidebar(false));

// wire friend items to mobile_message.php
document.querySelectorAll('#friendsList .friendItem').forEach(fi=>{
  fi.style.cursor = 'pointer';
  fi.addEventListener('click', ()=> {
    const u = fi.getAttribute('data-username');
    if (u) location.href = 'mobile_message.php?user=' + encodeURIComponent(u);
  });
});

// -------- notifications (drawer) ----------
let lastUnread = 0;
async function fetchNotifications(limit=50) {
  try {
    const r = await fetch(NOTIF_API + '?limit=' + encodeURIComponent(limit), { credentials: 'same-origin' });
    if (!r.ok) return null;
    return await r.json();
  } catch (e) {
    console.error('fetchNotifications', e);
    return null;
  }
}
function renderNotifItem(n) {
  const row = el('div',{class:'notifRow'});
  const avatar = el('div',{class:'notifAvatar'}, document.createTextNode((n.source_username||n.type||'')[0] ? (n.source_username||n.type)[0].toUpperCase() : '•'));
  const meta = el('div',{class:'notifMeta'});
  const title = el('div',{class:'notifTitle'}, document.createTextNode((n.source_username ? n.source_username + ' — ' : '') + (n.type || 'Notification')));
  const msg = el('div',{class:'notifMsg'}, document.createTextNode((n.message || '').slice(0,120)));
  meta.appendChild(title);
  meta.appendChild(msg);
  row.appendChild(avatar);
  row.appendChild(meta);

  row.addEventListener('click', async () => {
    // mark read (best-effort)
    try {
      await fetch('notifications.php', { method: 'POST', credentials:'same-origin', body: new URLSearchParams({ action: 'mark_read', id: n.id }) });
    } catch (e) {}
    // deep-linking priority: ref_code -> DM -> ref_id -> fallback reload
    const refCode = n.ref_code || n.ref || n.code || null;
    const type = (n.type || '').toString().toLowerCase();
    const src = n.source_username || n.actor_username || null;
    if (refCode) {
      location.href = 'mobile_private.php?code=' + encodeURIComponent(refCode);
      return;
    }
    if (type.includes('dm') || src) {
      if (src) {
        location.href = 'mobile_message.php?user=' + encodeURIComponent(src);
        return;
      }
    }
    if (n.ref_id) {
      location.href = 'mobile_private.php?code=' + encodeURIComponent(n.ref_id);
      return;
    }
    location.reload();
  });

  return row;
}
async function loadNotifications() {
  notifList.innerHTML = '';
  const j = await fetchNotifications(200);
  if (!j || !Array.isArray(j.notifications) || j.notifications.length === 0) {
    notifList.innerHTML = '<div style="color:var(--muted);padding:8px">No notifications</div>';
    topNotifCount.style.display = 'none';
    return;
  }
  const unread = j.notifications.filter(x => !x.is_read).length || j.unread_count || 0;
  topNotifCount.style.display = unread > 0 ? 'inline-block' : 'none';
  topNotifCount.textContent = unread > 99 ? '99+' : String(unread);
  j.notifications.forEach(n => {
    notifList.appendChild(renderNotifItem(n));
  });
}
openNotifBtn.addEventListener('click', async () => {
  const isOpen = notifDrawer.style.display === 'block';
  if (isOpen) {
    notifDrawer.style.display = 'none';
    openNotifBtn.setAttribute('aria-expanded','false');
  } else {
    notifDrawer.style.display = 'block';
    openNotifBtn.setAttribute('aria-expanded','true');
    await loadNotifications();
  }
});
markAllBtn.addEventListener('click', async ()=> {
  try {
    await fetch('notifications.php?action=mark_all', { method:'POST', credentials:'same-origin' });
    await loadNotifications();
    topNotifCount.style.display = 'none';
  } catch (e) { console.error(e); }
});

// polling for badge counts
async function pollNotificationsOnce() {
  try {
    const j = await fetchNotifications(5);
    if (!j) return;
    const unread = j.unread_count || (Array.isArray(j.notifications) ? j.notifications.filter(x=>!x.is_read).length : 0);
    if (unread > 0) {
      topNotifCount.style.display = 'inline-block';
      topNotifCount.textContent = unread > 99 ? '99+' : String(unread);
    } else {
      topNotifCount.style.display = 'none';
    }
    // play sound if new unread arrived
    if (typeof lastUnread === 'number' && unread > (lastUnread || 0) && lastUnread !== 0) {
      try { const a = new Audio('root/bell_2.mp3'); a.play().catch(()=>{}); } catch(e){}
    }
    lastUnread = unread;
  } catch (e) { console.error('pollNotificationsOnce', e); }
}
pollNotificationsOnce();
setInterval(pollNotificationsOnce, POLL_MS);

// close notif drawer when tapping outside
document.addEventListener('click', (ev)=> {
  if (!ev.target.closest || (!ev.target.closest('#notifDrawer') && !ev.target.closest('#openNotifBtn'))) {
    notifDrawer.style.display = 'none';
    openNotifBtn.setAttribute('aria-expanded','false');
  }
});

// -------- create community ----------
createCommunityToggle.addEventListener('click', ()=> {
  createCommunityModal.style.display = 'flex';
});
cancelCreateCommunity.addEventListener('click', ()=> {
  createCommunityModal.style.display = 'none';
});
createCommunityForm.addEventListener('submit', async (ev)=> {
  ev.preventDefault();
  const fd = new FormData(createCommunityForm);
  try {
    const r = await fetch('community_interface.php?action=create_community', { method:'POST', body: fd, credentials: 'same-origin' });
    const j = await r.json();
    if (j && j.ok && j.id) {
      createCommunityModal.style.display = 'none';
      // reload communities from first page
      communitiesGrid.innerHTML = '';
      communitiesPage = 1;
      lastPageReached = false;
      await loadCommunitiesPage(1);
      alert('Community created');
    } else {
      alert('Failed to create: ' + (j && j.error ? j.error : 'unknown'));
    }
  } catch (e) {
    console.error('createCommunity', e);
    alert('Network error');
  }
});

// -------- helper: refresh saved rooms periodically --------
setInterval(refreshSavedRooms, 60000);

// accessibility: trap focus inside modal while open (basic)
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    if (createCommunityModal.style.display === 'flex') createCommunityModal.style.display = 'none';
    if (sidebarPanel.classList.contains('open')) toggleSidebar(false);
    if (notifDrawer.style.display === 'block') { notifDrawer.style.display = 'none'; openNotifBtn.setAttribute('aria-expanded','false'); }
  }
});

// initial focus (small)
document.getElementById('openSidebarBtn').addEventListener('keypress', (e)=> { if (e.key === 'Enter') toggleSidebar(true); });

</script>
</body>
</html>
