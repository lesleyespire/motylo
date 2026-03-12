<?php
// message.php - Direct message UI (client) that uses message_interface.php
require "config.php";

// --- auth ---
if (empty($_COOKIE['auth_token'])) { header("Location:index.php"); exit; }
$stmt = $pdo->prepare("SELECT id, username, avatar FROM users WHERE auth_token = ?");
$stmt->execute([$_COOKIE['auth_token']]);
$me = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$me) { header("Location:index.php"); exit; }
$me_username = $me['username'];
$me_avatar = $me['avatar'] ?? null;
$me_id = (int)$me['id'];

// --- target user param ---
$target = trim((string)($_GET['user'] ?? ''));
if ($target === '') { die("Missing target user. Use message.php?user=theirusername"); }
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Message — <?= htmlspecialchars($target, ENT_QUOTES) ?></title>
<link rel="icon" href="root/favicon.ico">
<style>
:root{--bg:#0e0f12;--panel:#121315;--muted:#bfbfbf;--accent:#5865F2; --mine:#2b6fb2; --card:#0f1113}
html,body{height:100%;margin:0;background:var(--bg);color:#eee;font-family:Inter,Arial,Helvetica,sans-serif}
.topbar{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;background:#18181b;border-bottom:1px solid #222}
.topbar .btn{background:var(--accent);border:0;color:white;padding:7px 10px;border-radius:8px;cursor:pointer}
.wrapper{max-width:1100px;margin:18px auto;padding:12px;display:flex;gap:20px}
.sidebar{width:320px;background:var(--panel);padding:14px;border-radius:10px;display:flex;flex-direction:column;gap:12px}
.main{flex:1;background:var(--panel);border-radius:10px;padding:8px;display:flex;flex-direction:column;height:calc(100vh - 140px)}
/* header */
.headerRow{display:flex;gap:12px;align-items:center}
.pfp{width:80px;height:80px;border-radius:12px;overflow:hidden;border:2px solid #111;display:flex;align-items:center;justify-content:center;background:#1b1d20;color:#fff;font-weight:700;font-size:26px}
.pfp img{width:100%;height:100%;object-fit:cover;display:block}
.usernameTitle{font-weight:800;font-size:18px}
.bio{color:var(--muted);font-size:13px;margin-top:6px;min-height:40px}

/* actions */
.actions{display:flex;gap:8px;margin-top:10px;flex-wrap:wrap}
.smallBtn{background:#222;border:1px solid #2d2d2d;color:#fff;padding:8px 10px;border-radius:8px;cursor:pointer}
.smallBtn.primary{background:var(--accent);border:none}
.smallBtn.warn{background:#b54b4b}

/* friend lists (boxed cards) */
.sectionTitle{font-weight:700;color:#dfe7ff;margin-bottom:6px;font-size:13px}
.listBox{display:flex;flex-direction:column;gap:8px;padding:6px;background:transparent}
.listBox.small{max-height:140px;overflow:auto} /* their friends: smaller */
.listBox.large{max-height:300px;overflow:auto} /* your friends: larger */
.friendCard{display:flex;gap:10px;align-items:center;padding:8px;background:var(--card);border-radius:8px;cursor:pointer}
.friendCard img{width:56px;height:56px;border-radius:10px;object-fit:cover;flex:0 0 56px;border:2px solid #111}
.friendCard .name{font-weight:700;font-size:15px;color:#eef7ff}

/* block button row */
.blockRow{margin-top:8px}

/* chat area */
.chatHeader{padding:6px 8px;color:var(--muted);font-size:13px}
.chatWindow{flex:1;overflow:auto;padding:16px;border-radius:8px;background:linear-gradient(180deg, rgba(255,255,255,0.01), transparent)}
.msgRow{display:flex;gap:10px;margin-bottom:10px;align-items:flex-end}
.msgRow.mine{justify-content:flex-end}
.msgBubble{background:rgba(30,30,30,0.95);padding:12px;border-radius:10px;max-width:70%;box-sizing:border-box;word-break:break-word;position:relative}
.msgBubble.mine{background:var(--mine);color:#fff}
.msgMeta{font-size:11px;color:#999;margin-top:6px;text-align:right}
.replySnippet{border-left:3px solid rgba(255,255,255,0.06);padding:8px;margin-bottom:6px;color:#ccc;font-size:13px;border-radius:6px;background:rgba(0,0,0,0.04);overflow:hidden;max-width:100%}
.msgActions{position:absolute;right:6px;top:6px;display:flex;gap:6px;opacity:0;transition:opacity .12s}
.msgBubble:hover .msgActions{opacity:1}
.actionBtn{background:rgba(0,0,0,.35);border:0;color:#fff;padding:4px 6px;border-radius:6px;cursor:pointer;font-size:12px}

/* big emoji */
.big-emoji{font-size:48px;line-height:1;vertical-align:middle;margin:0 2px}

/* reply preview */
#replyPreview { background:#151515;border:1px solid #2a2a2a;padding:8px;border-radius:8px;color:#ddd;margin:8px 12px;display:none;align-items:center;gap:8px; }
#replyPreview .rp-title{font-weight:700;margin-right:8px}
#replyPreview .rp-cancel{margin-left:auto;background:transparent;border:0;color:#f66;cursor:pointer}

/* input */
.inputArea{display:flex;gap:8px;padding:12px;border-top:1px solid #222;align-items:center}
#msg{flex:1;padding:10px;border-radius:8px;border:0;background:#141416;color:#fff;font-size:15px}
.sendBtn{background:var(--accent);border:0;padding:10px 14px;border-radius:8px;color:white;cursor:pointer}
.charCount{font-size:12px;color:var(--muted);min-width:8px;text-align:right}

/* notification bell (room.php style) */
.bell { position:relative; cursor:pointer; padding:6px 8px; border-radius:8px; background:rgba(255,255,255,0.02); display:inline-flex; align-items:center; gap:6px;}
.badge { position:absolute; top:-6px; right:-6px; background:#ff4d4f; color:white; border-radius:12px; padding:2px 6px; font-size:12px; min-width:24px; text-align:center; }
.notifBox{position:absolute; right:12px; top:48px; background:#0b1114; border-radius:8px; padding:12px; min-width:320px; max-width:420px; box-shadow:0 8px 24px rgba(0,0,0,.6); display:none; z-index:1000}
.notifGroup{display:flex;gap:10px;align-items:center;padding:8px;border-radius:8px;cursor:pointer}
.notifGroup .avatar{width:44px;height:44px;border-radius:8px;background:#222;display:flex;align-items:center;justify-content:center;color:#fff;flex:0 0 44px;overflow:hidden}
.notifGroup .meta{flex:1;min-width:0}
.notifGroup .meta .title{font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.notifGroup .meta .msg{color:var(--muted);font-size:13px;margin-top:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

/* small helpers */
.divider{height:1px;background:#1f2124;margin:10px 0;border-radius:2px}
</style>
</head>
<body>

<div class="topbar">
  <div style="display:flex;gap:10px;align-items:center">
    <button class="topBtn" onclick="location.href='room.php'">← Back</button>
    <div style="color:#cfcfcf">Personal messages</div>
  </div>

  <!-- Right side: notification bell inserted here -->
  <div style="position:relative">
    <!-- DM sound (for incoming DMs) -->
    <audio id="dm_bell" preload="auto"><source src="root/bell.mp3" type="audio/mpeg"></audio>
    <!-- Notification sound (for new notifications) -->
    <audio id="notif_bell" preload="auto"><source src="root/bell_2.mp3" type="audio/mpeg"></audio>

    <div id="notifBtn" class="bell" title="Notifications" style="margin-right:12px">
      🔔
      <span id="notifBadge" class="badge" style="display:none">0</span>
    </div>

    <div id="notifDropdown" class="notifBox" aria-hidden="true" style="display:none; right:12px; top:48px;">
      <div style="display:flex;justify-content:flex-end;margin-bottom:8px"><button id="markAllBtn" style="background:transparent;border:0;color:var(--accent);cursor:pointer">Mark all read</button></div>
      <div id="notifList" style="max-height:320px;overflow:auto">
        <div style="padding:12px;color:var(--muted)">Loading…</div>
      </div>
    </div>
  </div>
</div>

<div class="wrapper">
  <!-- sidebar -->
  <aside class="sidebar" id="sidebar">
    <div>
      <div class="headerRow">
        <div class="pfp" id="sidePfp"></div>
        <div style="flex:1">
          <div class="usernameTitle" id="sideUsername"><?= htmlspecialchars($target, ENT_QUOTES) ?></div>
          <div id="sideRole" style="color:var(--muted);font-size:12px"></div>
        </div>
      </div>
      <div class="bio" id="sideBio">Loading bio…</div>

      <div class="actions" id="friendActions">
        <button class="smallBtn primary" id="friendBtn">Friend</button>
        <button class="smallBtn" id="acquaintBtn">Acquaintance</button>
      </div>
    </div>

    <div class="divider" aria-hidden="true"></div>

    <div>
      <div class="sectionTitle">Their friends</div>
      <!-- changed to .listBox.small to make it smaller/scrollable (shows ALL their friends if given) -->
      <div id="theirFriends" class="listBox small">
        <div style="color:var(--muted)">Loading…</div>
      </div>
    </div>

    <div class="divider" aria-hidden="true"></div>

    <div>
      <div class="sectionTitle">Your friends</div>
      <!-- keep your friends taller -->
      <div id="yourFriends" class="listBox large">
        <div style="color:var(--muted)">Loading…</div>
      </div>
    </div>

    <div class="divider" aria-hidden="true"></div>

    <div class="blockRow">
      <button class="smallBtn warn" id="blockBtn">Block</button>
    </div>
  </aside>

  <!-- main -->
  <main class="main">
    <div class="chatHeader">Conversation with <strong id="targetTitle"><?= htmlspecialchars($target, ENT_QUOTES) ?></strong></div>

    <div id="typingIndicator" style="padding:6px 12px;color:var(--muted);min-height:20px"></div>

    <div class="chatWindow" id="chat" aria-live="polite"></div>

    <div id="replyPreview" role="status" aria-live="polite">
      <span class="rp-title">Replying to <strong id="rpUser"></strong>:</span>
      <span id="rpText" style="opacity:.9"></span>
      <button class="rp-cancel" id="rpCancel" title="Cancel reply">✖</button>
    </div>

    <div class="inputArea" id="inputArea">
      <input id="msg" placeholder="Send a message..." maxlength="750" autocomplete="off" />
      <div class="charCount" id="charCount">0/750</div>
      <button class="sendBtn" id="sendBtn">Send</button>
    </div>
  </main>
</div>

<script>
/* ----------------- notification bell (room.php style UI) ----------------- */
/* single global audioUnlocked flag used for gating both notification & message sounds */
let audioUnlocked = false;
document.addEventListener('pointerdown', ()=> audioUnlocked = true, { once: true });

const NOTIF_BTN = document.getElementById('notifBtn'); // bell button wrapper
const BADGE = document.getElementById('notifBadge');
const DROPDOWN = document.getElementById('notifDropdown');
const NOTIF_LIST = document.getElementById('notifList');
const MARK_ALL = document.getElementById('markAllBtn');
const API = 'notifications.php'; // server endpoint for notifications

let lastUnread = 0;
let pollInterval = null;
const POLL_MS = 30000;
const MARKED = new Set();

async function fetchNotifications(limit=50) {
  try {
    const r = await fetch(API + '?limit=' + encodeURIComponent(limit), { credentials: 'same-origin' });
    if (!r.ok) return null;
    return await r.json();
  } catch (e) { return null; }
}

function groupNotifications(rows) {
  const groups = new Map();
  for (const n of (rows || [])) {
    let key;
    if ((n.type || '') === 'modmail' && n.ref_id) key = 'modmail|ref|' + (n.ref_id || 0);
    else key = (n.type || '') + '|' + (n.source_user_id || 0);

    if (!groups.has(key)) groups.set(key, { key, type: n.type, source_user_id: n.source_user_id, source_username: n.source_username, source_avatar: n.source_avatar, ids: [], latest: null, count: 0, important: (n.important||0), ref_id: n.ref_id || null, firstCreated: n.created_at });
    const g = groups.get(key);
    g.ids.push(n.id); g.count++;
    if (!g.latest || (n.id && n.id > g.latest.id)) g.latest = n;
    if (!g.firstCreated || new Date(n.created_at) > new Date(g.firstCreated)) g.firstCreated = n.created_at;
    if (n.important) g.important = 1;
  }
  return Array.from(groups.values()).sort((a,b) => (b.latest?.id || 0) - (a.latest?.id || 0));
}

function renderGroup(g) {
  const el = document.createElement('div'); el.className = 'notifGroup' + (g.latest && !g.latest.is_read ? ' unread' : '');
  el.dataset.ids = g.ids.join(',');

  const av = document.createElement('div'); av.className='avatar';
  if (g.source_avatar) {
    const img = document.createElement('img'); img.src = (g.source_avatar.indexOf('/') === 0 || g.source_avatar.startsWith('http')) ? g.source_avatar : 'avatars/' + encodeURIComponent(g.source_avatar);
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
  } else if (g.type === 'modmail') {
    titleText = (g.important ? '🔔 IMPORTANT — ' : '') + (g.latest.message || 'Modmail');
    if (titleText.length > 80) titleText = titleText.slice(0,80) + '…';
  } else {
    titleText = g.latest && g.latest.message ? String(g.latest.message) : (g.type || 'Notification');
    if (g.count > 1) titleText += ` (${g.count})`;
  }
  title.textContent = titleText;
  const sub = document.createElement('div'); sub.className='msg'; sub.textContent = g.latest && g.latest.created_at ? new Date(g.latest.created_at).toLocaleString() : '';
  meta.appendChild(title); meta.appendChild(sub);

  const right = document.createElement('div'); right.style.display='flex'; right.style.flexDirection='column'; right.style.alignItems='flex-end';
  const cnt = document.createElement('div'); cnt.className='notifCount'; cnt.textContent = g.count > 1 ? g.count : '';
  if (g.count <= 1) cnt.style.display='none';
  right.appendChild(cnt);
  const time = document.createElement('div'); time.className='time'; time.textContent = g.latest && g.latest.created_at ? new Date(g.latest.created_at).toLocaleTimeString() : '';
  right.appendChild(time);

  el.appendChild(av); el.appendChild(meta); el.appendChild(right);

  if (g.latest && !g.latest.is_read) {
    const dot = document.createElement('div'); dot.className='unreadDot'; el.appendChild(dot);
  }

  el.addEventListener('click', async (ev) => {
    ev.stopPropagation();
    // mark all ids
    for (const id of g.ids) {
      if (!MARKED.has(id)) {
        try {
          await fetch(API, { method:'POST', credentials:'same-origin', body: new URLSearchParams({ action:'mark_read', id: id }) });
          MARKED.add(id);
        } catch (e) {}
      }
    }
    if (g.type === 'modmail' && g.latest && g.latest.ref_id) {
      window.location.href = 'modmail.php?id=' + encodeURIComponent(g.latest.ref_id);
    } else if (g.source_username) {
      window.location.href = 'message.php?user=' + encodeURIComponent(g.source_username);
    } else {
      toggleDropdown(false);
    }
  });

  return el;
}

async function loadNotifList() {
  NOTIF_LIST.innerHTML = '<div style="padding:12px;color:var(--muted)">Loading…</div>';
  const j = await fetchNotifications(100);
  if (!j || !Array.isArray(j.notifications)) { NOTIF_LIST.innerHTML = '<div style="padding:12px;color:#f66">Failed to load</div>'; return; }
  NOTIF_LIST.innerHTML = '';
  const groups = groupNotifications(j.notifications || []);
  if (groups.length === 0) { NOTIF_LIST.innerHTML = '<div style="padding:12px;color:var(--muted)">No notifications</div>'; return; }
  for (const g of groups) NOTIF_LIST.appendChild(renderGroup(g));
  setupObserver();
}

let observer = null;
function setupObserver() {
  if (observer) { observer.disconnect(); observer = null; }
  const opts = { root: NOTIF_LIST, rootMargin: '0px', threshold: 0.6 };
  observer = new IntersectionObserver(async (entries) => {
    for (const ent of entries) {
      if (!ent.isIntersecting) continue;
      const el = ent.target;
      const ids = (el.dataset.ids || '').split(',').map(s=>parseInt(s,10)).filter(Boolean);
      const toMark = ids.filter(id => !MARKED.has(id));
      if (toMark.length > 0) {
        for (const id of toMark) {
          try {
            await fetch(API, { method:'POST', credentials:'same-origin', body: new URLSearchParams({ action:'mark_read', id: id }) });
            MARKED.add(id);
          } catch (e) {}
        }
        el.classList.remove('unread');
        const dot = el.querySelector('.unreadDot'); if (dot) dot.remove();
        lastUnread = Math.max(0, lastUnread - toMark.length);
        if (lastUnread > 0) BADGE.textContent = lastUnread > 99 ? '99+' : String(lastUnread); else BADGE.style.display='none';
      }
      observer.unobserve(el);
    }
  }, opts);

  const items = NOTIF_LIST.querySelectorAll('.notifGroup');
  items.forEach(it => {
    const ids = (it.dataset.ids || '').split(',').map(s=>parseInt(s,10)).filter(Boolean);
    const any = ids.some(id => !MARKED.has(id));
    if (any) observer.observe(it);
  });
}

function toggleDropdown(force) {
  const open = DROPDOWN.style.display === 'block';
  const next = (typeof force === 'boolean') ? force : !open;
  DROPDOWN.style.display = next ? 'block' : 'none';
  if (next) loadNotifList();
}

/* use notif button element to toggle */
NOTIF_BTN.addEventListener('click', (e)=> { e.stopPropagation(); toggleDropdown(); });
document.addEventListener('click', (e)=> { if (!e.target.closest || (!e.target.closest('#notifBtn') && !e.target.closest('#notifDropdown'))) toggleDropdown(false); });

// mark all
MARK_ALL.addEventListener('click', async (e)=> {
  e.stopPropagation();
  try {
    await fetch(API, { method:'POST', credentials:'same-origin', body: new URLSearchParams({ action:'mark_all_read' }) });
    document.querySelectorAll('.notifGroup').forEach(it => { it.classList.remove('unread'); const d=it.querySelector('.unreadDot'); if (d) d.remove(); });
    lastUnread = 0; BADGE.style.display='none';
    const j = await fetchNotifications(200);
    if (Array.isArray(j.notifications)) for (const n of j.notifications) MARKED.add(n.id);
  } catch (e) { console.error(e); }
});

async function startPolling() {
  try {
    const j = await fetchNotifications(5);
    const unread = j && j.unread_count ? j.unread_count : 0;
    lastUnread = unread;
    if (unread > 0) { BADGE.style.display='inline-block'; BADGE.textContent = unread > 99 ? '99+' : String(unread); } else BADGE.style.display='none';
  } catch (e) {}
  pollInterval = setInterval(async ()=>{
    try {
      const j = await fetchNotifications(5);
      if (!j) return;
      const unread = j.unread_count || 0;
      if (unread > (lastUnread || 0) && lastUnread !== 0) {
        if (audioUnlocked) {
          try {
            const audio = document.getElementById('notif_bell');
            if (audio) { audio.currentTime = 0; audio.play().catch(()=>{}); }
          } catch (e) {}
        }
      }
      lastUnread = unread;
      if (unread > 0) { BADGE.style.display='inline-block'; BADGE.textContent = unread > 99 ? '99+' : String(unread); } else BADGE.style.display='none';
      if (DROPDOWN.style.display === 'block') loadNotifList();
    } catch (e) { console.error('notif poll', e); }
  }, POLL_MS);
}
startPolling();
</script>

<script>
// ---------- rest of message.php JS (mostly unchanged) ----------
/* Notes about changes:
   - Big emoji rendering for messages
   - Their friends shows relationship.their_friends if provided (all their friends), fallback to mutual_friends
   - DM sound uses #dm_bell; notification sound uses #notif_bell
*/

/* ---------- emoji / helper constants ---------- */
const TARGET = <?= json_encode($target) ?>;
const MY_ID = <?= json_encode($me_id) ?>;
const MAX_MESSAGE_LENGTH = 750;
let lastId = 0;
let running = true;
let relationship = { status: 'none', allowed: false, initiator: null, requested_kind: null, mutual_friends: [] };
document.addEventListener('pointerdown', ()=> audioUnlocked = true, { once: true });

const EMOJIS = ["😡","😭","🙄","😒","😝","😖","☹️","😢","😀","😁","😂","🤣","😃","😄","😅","😆","😉","😊","🙂","🙃","😍","😘","😗","😙","😚","😋","😜","🤪","🤨","🧐","🤓","😎","🤩","🥳","🤗","🤔","🤭","🤫","🤥","😶","😐","😑","😬","🙄","😯","😦","😧","😮","😲","😴","🤤","😪","😵","🤐","🥴","🤢","🤮","🤧","😷","🤒","🤕","😇","🥰","💩","👻","💀","🤖","🎃","😺","😸","😹","😻","😼","🙈","🙉","🙊","👍","👎","👏","🙌","🙏","💪","🤝","👑","⭐","✨","🔥","💥","💫","🌟","💯","✔️","❌","❤️","💛","💚","💙","💜","🖤","💔","💕","☀️","🌤️","⛅","🌧️","🌩️","🌨️","🌈","🍕","🍔","🍟","🍣","☕","🍺","🍷","🎂","🍩","🍪","⚽","🏀","🏈","🎮","🎧","🎵","🎶","🎸","🎹"];

function escapeHtml(s){ if (s==null) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }
function parseServerTS(ts){ if (!ts) return null; if (/[zZ]|[+\-]\d{2}:\d{2}$/.test(ts)) return new Date(ts); return new Date(ts + 'Z'); }
function relativeTime(ts){ const d = parseServerTS(ts); if (!d || isNaN(d)) return ''; const diff=(Date.now()-d.getTime())/1000; if (diff<5) return 'just now'; if (diff<60) return Math.floor(diff)+'s'; if (diff<3600) return Math.floor(diff/60)+'m'; if (diff<86400) return Math.floor(diff/3600)+'h'; return d.toLocaleDateString(); }
async function apiGet(params){ const q = new URLSearchParams(params).toString(); const r = await fetch('message_interface.php?user=' + encodeURIComponent(TARGET) + (q ? '&' + q : ''), { credentials:'same-origin' }); return await r.json(); }

function escapeForRegex(s){ return s.replace(/[.*+?^${}()|[\]\\]/g,'\\$&'); }
const emojiRegex = new RegExp('(' + EMOJIS.map(escapeForRegex).join('|') + ')', 'g');
function highlightEmojisEscaped(escapedText){ return escapedText.replace(emojiRegex, '<span class="big-emoji">$1</span>'); }

/* ---------- render helpers ---------- */
const chatEl = document.getElementById('chat');
const msgEl = document.getElementById('msg');
const sendBtn = document.getElementById('sendBtn');
const charCount = document.getElementById('charCount');
const friendBtn = document.getElementById('friendBtn');
const acquaintBtn = document.getElementById('acquaintBtn');
const blockBtn = document.getElementById('blockBtn');
const sideBio = document.getElementById('sideBio');
const sidePfp = document.getElementById('sidePfp');
const sideUsernameEl = document.getElementById('sideUsername');
const theirFriendsEl = document.getElementById('theirFriends');
const yourFriendsEl = document.getElementById('yourFriends');
const typingIndicator = document.getElementById('typingIndicator');

const rpCancel = document.getElementById('rpCancel');
const rpUser = document.getElementById('rpUser');
const rpText = document.getElementById('rpText');
let replyingTo = null;

rpCancel.addEventListener('click', ()=> { clearReply(); });

function renderTargetHeader(target) {
  sideUsernameEl.textContent = target && target.username ? target.username : TARGET;
  sideBio.textContent = target && target.bio ? target.bio : '(no bio)';
  if (target && target.avatar) {
    sidePfp.innerHTML = '<img src="avatars/' + encodeURIComponent(target.avatar) + '" alt="">';
  } else {
    sidePfp.textContent = (target && target.username) ? target.username[0].toUpperCase() : '?';
  }
}

function makeFriendNode(f) {
  const node = document.createElement('div');
  node.className = 'friendCard';
  const img = document.createElement('img');
  img.src = f.avatar ? ('avatars/' + encodeURIComponent(f.avatar)) : 'root/default_avatar.png';
  img.alt = f.username || '';
  const name = document.createElement('div'); name.className = 'name'; name.textContent = f.username || '';
  node.appendChild(img);
  node.appendChild(name);
  node.addEventListener('click', ()=> { window.location.href = 'message.php?user=' + encodeURIComponent(f.username || ''); });
  return node;
}

/* ---------- messages append (big-emoji + correct audio) ---------- */
function appendMessages(messages){
  let appended=false, playSound=false;
  for (const m of messages) {
    if (!m || !m.id || m.id <= lastId) continue;
    appended = true;
    const isMine = (m.user_id === MY_ID || m.username === <?= json_encode($me_username) ?>);
    if (!isMine) playSound = true;

    const row = document.createElement('div'); row.className = 'msgRow' + (isMine ? ' mine' : '');
    row.dataset.id = m.id;

    const bubble = document.createElement('div'); bubble.className = 'msgBubble' + (isMine ? ' mine' : '');

    if (m.reply_to_username || m.reply_to_excerpt) {
      const sn = document.createElement('div'); sn.className='replySnippet';
      const ru = document.createElement('div'); ru.style.fontWeight='700'; ru.style.marginBottom='4px'; ru.textContent = m.reply_to_username || '…';
      const rx = document.createElement('div'); rx.textContent = m.reply_to_excerpt || '';
      sn.appendChild(ru); sn.appendChild(rx);
      bubble.appendChild(sn);
    }

    const content = document.createElement('div'); content.className='msgContent';
    const raw = m.message || '';
    content.innerHTML = highlightEmojisEscaped(escapeHtml(raw));
    bubble.appendChild(content);

    const meta = document.createElement('div'); meta.className='msgMeta';
    meta.textContent = (m.edited_at ? 'edited • ' : '') + (relativeTime(m.created_at) || '');
    bubble.appendChild(meta);

    const actions = document.createElement('div'); actions.className='msgActions';
    const replyBtn = document.createElement('button'); replyBtn.className='actionBtn replyBtn'; replyBtn.dataset.id = m.id; replyBtn.dataset.user = m.username || ''; replyBtn.dataset.excerpt = (m.message||'').slice(0,140); replyBtn.textContent = 'Reply';
    actions.appendChild(replyBtn);

    // edit permission
    let canEdit = false;
    if (isMine && m.created_at) {
      const createdMs = parseServerTS(m.created_at).getTime();
      if (!isNaN(createdMs) && (Date.now() - createdMs) <= (10*60*1000)) canEdit = true;
    }
    if (canEdit) {
      const editBtn = document.createElement('button'); editBtn.className='actionBtn editBtn'; editBtn.dataset.id = m.id; editBtn.textContent='Edit';
      actions.appendChild(editBtn);
    }

    bubble.appendChild(actions);
    row.appendChild(bubble);
    chatEl.appendChild(row);
    lastId = Math.max(lastId, m.id);
  }

  if (appended) {
    chatEl.scrollTop = chatEl.scrollHeight;
    if (playSound && audioUnlocked) {
      try {
        const dmAudio = document.getElementById('dm_bell');
        if (dmAudio) { dmAudio.currentTime = 0; dmAudio.play().catch(()=>{}); }
        else { const a = new Audio('root/bell.mp3'); a.play().catch(()=>{}); }
      } catch(e){}
    }
  }
}

/* ---------- typing indicator ---------- */
function updateTyping(list) {
  const names = (list || []).filter(Boolean);
  if (names.length === 0) typingIndicator.textContent = '';
  else if (names.length === 1) typingIndicator.textContent = names[0] + ' is typing…';
  else typingIndicator.textContent = names.slice(0,3).join(', ') + ' are typing…';
}

/* ---------- reply preview ---------- */
function showReplyPreview(obj) {
  if (!obj) return clearReply();
  replyingTo = { id: obj.id || obj.id, username: obj.username || obj.user || '', excerpt: obj.excerpt || obj.text || '' };
  rpUser.textContent = replyingTo.username || '…';
  rpText.textContent = (replyingTo.excerpt || '').slice(0,240);
  document.getElementById('replyPreview').style.display = 'flex';
}
function clearReply() {
  replyingTo = null;
  document.getElementById('replyPreview').style.display = 'none';
  rpUser.textContent = '';
  rpText.textContent = '';
}

/* ---------- delegated click handlers for reply/edit ---------- */
chatEl.addEventListener('click', async (ev)=>{
  const btn = ev.target.closest('.replyBtn, .editBtn');
  if (!btn) return;
  if (btn.classList.contains('replyBtn')) {
    const id = btn.dataset.id; const user = btn.dataset.user || ''; const excerpt = btn.dataset.excerpt || '';
    showReplyPreview({ id:id, username:user, excerpt:excerpt });
    msgEl.focus();
    return;
  }
  if (btn.classList.contains('editBtn')) {
    const id = btn.dataset.id;
    const row = btn.closest('.msgRow'); if (!row) return;
    const bubble = row.querySelector('.msgBubble'); const content = bubble.querySelector('.msgContent');
    if (!content) return;
    if (bubble.querySelector('.editArea')) return;
    const orig = content.textContent || '';
    content.style.display = 'none';
    const editArea = document.createElement('div'); editArea.className = 'editArea';
    const ta = document.createElement('textarea'); ta.value = orig; ta.style.width = '100%'; ta.style.minHeight='60px';
    const save = document.createElement('button'); save.className='actionBtn'; save.textContent='Save';
    const cancel = document.createElement('button'); cancel.className='actionBtn'; cancel.textContent='Cancel';
    editArea.appendChild(ta); editArea.appendChild(save); editArea.appendChild(cancel);
    bubble.appendChild(editArea);
    ta.focus();
    save.addEventListener('click', async ()=>{
      const newText = ta.value.trim();
      if (!newText) { alert('Message cannot be blank'); return; }
      if (Array.from(newText).length > MAX_MESSAGE_LENGTH) { alert('Message too long'); return; }
      const fd = new FormData(); fd.append('id', id); fd.append('message', newText);
      const res = await fetch('message_interface.php?user=' + encodeURIComponent(TARGET) + '&mode=edit', { method:'POST', body: fd, credentials:'same-origin' });
      const j = await res.json();
      if (j && j.ok) {
        content.innerHTML = highlightEmojisEscaped(escapeHtml(newText));
        content.style.display = '';
        editArea.remove();
      } else {
        alert(j && j.error ? j.error : 'Edit failed');
      }
    });
    cancel.addEventListener('click', ()=>{ editArea.remove(); content.style.display=''; });
  }
});

/* ---------- friend/acquaintance/block actions ---------- */
friendBtn.addEventListener('click', async ()=>{
  const txt = friendBtn.textContent.toLowerCase();
  let action;
  if (txt.includes('remove')) action = 'remove_friend';
  else if (txt.includes('cancel')) action = 'cancel_request';
  else if (txt.includes('accept')) {
    if (relationship.requested_kind === 'acquaintance') action = 'accept_acquaintance';
    else action = 'accept_friend';
  } else {
    action = 'request_friend';
  }
  const fd = new FormData(); fd.append('action', action);
  const r = await fetch('message_interface.php?user=' + encodeURIComponent(TARGET) + '&mode=friend_action', { method:'POST', body: fd, credentials:'same-origin' });
  const j = await r.json();
  if (j && j.ok) loadOnce(); else { alert(j && j.error ? j.error : 'Action failed'); loadOnce(); }
});

acquaintBtn.addEventListener('click', async ()=>{
  if (relationship.status === 'requested' && relationship.requested_kind === 'acquaintance' && relationship.initiator && Number(relationship.initiator) !== Number(MY_ID)) {
    if (!confirm('Decline acquaintance request?')) return;
    const fd = new FormData(); fd.append('action','decline');
    const r = await fetch('message_interface.php?user=' + encodeURIComponent(TARGET) + '&mode=friend_action', { method:'POST', body: fd, credentials:'same-origin' });
    const j = await r.json();
    if (j && j.ok) loadOnce(); else alert('Failed');
    return;
  }
  if (relationship.status === 'acquaintance') {
    if (!confirm('Remove acquaintance?')) return;
    const fd = new FormData(); fd.append('action','remove_acquaintance');
    const r = await fetch('message_interface.php?user=' + encodeURIComponent(TARGET) + '&mode=friend_action', { method:'POST', body: fd, credentials:'same-origin' });
    const j = await r.json();
    if (j && j.ok) loadOnce(); else alert('Failed');
  } else {
    const fd = new FormData(); fd.append('action','request_acquaintance');
    const r = await fetch('message_interface.php?user=' + encodeURIComponent(TARGET) + '&mode=friend_action', { method:'POST', body: fd, credentials:'same-origin' });
    const j = await r.json();
    if (j && j.ok) loadOnce(); else alert('Failed');
  }
});

blockBtn.addEventListener('click', async ()=>{
  if (blockBtn.dataset.blocked === '1') {
    const fd = new FormData(); fd.append('action','unblock');
    const r = await fetch('message_interface.php?user=' + encodeURIComponent(TARGET) + '&mode=block_action', { method:'POST', body: fd, credentials:'same-origin' });
    const j = await r.json();
    if (j && j.ok) { blockBtn.dataset.blocked='0'; blockBtn.textContent='Block'; loadOnce(); } else alert('Failed');
  } else {
    if (!confirm('Block this user?')) return;
    const fd = new FormData(); fd.append('action','block');
    const r = await fetch('message_interface.php?user=' + encodeURIComponent(TARGET) + '&mode=block_action', { method:'POST', body: fd, credentials:'same-origin' });
    const j = await r.json();
    if (j && j.ok) { blockBtn.dataset.blocked='1'; blockBtn.textContent='Unblock'; loadOnce(); } else alert('Failed');
  }
});

/* ---------- typing beacon + char counter ---------- */
let lastTyping=0;
msgEl.addEventListener('input', ()=> {
  const len = Array.from(msgEl.value).length;
  charCount.textContent = len + '/' + MAX_MESSAGE_LENGTH;
  const now = Date.now();
  if (now - lastTyping > 800) {
    lastTyping = now;
    navigator.sendBeacon ? navigator.sendBeacon('message_interface.php?user=' + encodeURIComponent(TARGET) + '&mode=typing') : fetch('message_interface.php?user=' + encodeURIComponent(TARGET) + '&mode=typing', { method:'POST', keepalive:true, credentials:'same-origin' }).catch(()=>{});
  }
});

/* ---------- send message ---------- */
sendBtn.addEventListener('click', send);
msgEl.addEventListener('keydown', (e)=> { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } });
async function send(){
  const text = msgEl.value.trim();
  if (!text) return;
  if (Array.from(text).length > MAX_MESSAGE_LENGTH) { alert('Message too long'); return; }
  if (relationship.allowed === false) { alert("You can't message this user yet."); return; }
  const fd = new FormData(); fd.append('message', text);
  if (replyingTo && replyingTo.id) fd.append('reply_to', replyingTo.id);
  await fetch('message_interface.php?user=' + encodeURIComponent(TARGET) + '&mode=send', { method:'POST', body: fd, credentials:'same-origin' });
  msgEl.value = ''; charCount.textContent = '0/' + MAX_MESSAGE_LENGTH;
  clearReply();
  await pollOnce();
}

/* ---------- load/poll ---------- */
async function loadOnce(){
  try {
    const r = await apiGet({});
    if (r.error) { console.error(r); return; }
    if (r.target) renderTargetHeader(r.target);
    if (r.relationship) {
      relationship = r.relationship;

      // show ALL of their friends if provided (relationship.their_friends),
      // otherwise fall back to mutual_friends.
      theirFriendsEl.innerHTML = '';
      if (relationship.status === 'friends') {
        const theirList = Array.isArray(relationship.their_friends) ? relationship.their_friends : (Array.isArray(relationship.mutual_friends) ? relationship.mutual_friends : []);
        if (!theirList || theirList.length === 0) {
          theirFriendsEl.innerHTML = '<div style="color:var(--muted)">No visible friends</div>';
        } else {
          theirList.forEach(f => theirFriendsEl.appendChild(makeFriendNode(f)));
        }
      } else {
        theirFriendsEl.innerHTML = '<div style="color:var(--muted)">You need to be friends to view this list</div>';
      }

      updateRelationshipButtons();
    }
    if (Array.isArray(r.friends)) {
      yourFriendsEl.innerHTML = '';
      if (r.friends.length === 0) yourFriendsEl.innerHTML = '<div style="color:var(--muted)">No friends yet</div>';
      else r.friends.forEach(f => yourFriendsEl.appendChild(makeFriendNode(f)));
    }
    if (r.typing) updateTyping(r.typing);
    chatEl.innerHTML=''; lastId=0;
    appendMessages(r.messages || []);
  } catch (e) { console.error('loadOnce', e); }
}

function updateRelationshipButtons(){
  const rel = relationship || {};
  if (rel.status === 'friends') { friendBtn.textContent='Remove friend'; friendBtn.classList.remove('primary'); }
  else if (rel.status === 'acquaintance') { friendBtn.textContent='Request friend'; friendBtn.classList.add('primary'); }
  else if (rel.status === 'requested') {
    if (rel.initiator && Number(rel.initiator) === Number(MY_ID)) { friendBtn.textContent='Cancel request'; friendBtn.classList.remove('primary'); }
    else {
      if (rel.requested_kind === 'acquaintance') friendBtn.textContent='Accept acquaintance';
      else friendBtn.textContent='Accept request';
      friendBtn.classList.add('primary');
    }
  } else { friendBtn.textContent='Send friend request'; friendBtn.classList.add('primary'); }

  if (rel.status === 'acquaintance') { acquaintBtn.textContent='Remove acquaintance'; acquaintBtn.classList.remove('primary'); }
  else if (rel.status === 'requested' && rel.requested_kind === 'acquaintance' && rel.initiator && Number(rel.initiator) !== Number(MY_ID)) { acquaintBtn.textContent='Decline acquaintance'; acquaintBtn.classList.remove('primary'); }
  else { acquaintBtn.textContent='Request acquaintance'; acquaintBtn.classList.add('primary'); }

  if (rel.blocked) { blockBtn.textContent='Unblock'; blockBtn.dataset.blocked='1'; } else { blockBtn.textContent='Block'; blockBtn.dataset.blocked='0'; }
  if (!rel.allowed) { document.getElementById('inputArea').style.display='none'; } else { document.getElementById('inputArea').style.display='flex'; }
}

/* ---------- polling ---------- */
let inFlight=false;
async function pollOnce(){
  if (inFlight) return;
  inFlight=true;
  try {
    const r = await fetch('message_interface.php?user=' + encodeURIComponent(TARGET) + '&since=' + encodeURIComponent(lastId), { credentials:'same-origin' });
    if (!r.ok) { inFlight=false; return; }
    const j = await r.json();
    if (Array.isArray(j.messages) && j.messages.length) appendMessages(j.messages);
    if (j.relationship) { relationship = j.relationship; updateRelationshipButtons(); }
    if (j.typing) updateTyping(j.typing);
    if (j.target) renderTargetHeader(j.target);
    if (Array.isArray(j.friends)) { yourFriendsEl.innerHTML=''; j.friends.forEach(f => yourFriendsEl.appendChild(makeFriendNode(f))); }
  } catch (e) { console.error('pollOnce', e); }
  inFlight=false;
}

async function longPollLoop(){
  while (running) {
    try {
      await pollOnce();
      await new Promise(r => setTimeout(r, 700));
    } catch (e) { await new Promise(r => setTimeout(r, 2000)); }
  }
}

/* ---------- start ---------- */
loadOnce().then(()=> { longPollLoop(); });
window.addEventListener('beforeunload', ()=> running = false);
</script>

</body>
</html>
