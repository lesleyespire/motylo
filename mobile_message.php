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
if ($target === '') { die("Missing target user. Use mobile_message.php?user=theirusername"); }
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
.topbar .btn{background:var(--accent);border:0;color:white;padding:12px 14px;border-radius:10px;cursor:pointer;font-size:16px} /* enlarged */
.topbar .topIcon{background:transparent;border:0;color:inherit;padding:10px;border-radius:10px;font-size:20px;cursor:pointer} /* icon-style */
.wrapper{max-width:1100px;margin:12px auto;padding:12px;display:flex;gap:20px}
.sidebar{width:320px;background:var(--panel);padding:14px;border-radius:10px;display:flex;flex-direction:column;gap:12px}
.main{flex:1;background:var(--panel);border-radius:10px;padding:8px;display:flex;flex-direction:column;height:calc(100vh - 140px)}
/* header */
.headerRow{display:flex;gap:12px;align-items:center}
.pfp{width:80px;height:80px;border-radius:12px;overflow:hidden;border:2px solid #111;display:flex;align-items:center;justify-content:center;background:#1b1d20;color:#fff;font-weight:700;font-size:26px}
.pfp img{width:100%;height:100%;object-fit:cover;display:block}
.usernameTitle{font-weight:800;font-size:18px}
.bio{color:var(--muted);font-size:13px;margin-top:6px;min-height:40px}

/* actions */
.actions{display:flex;gap:12px;margin-top:10px;flex-wrap:wrap}
.smallBtn{background:#222;border:1px solid #2d2d2d;color:#fff;padding:12px 14px;border-radius:10px;cursor:pointer;font-size:16px} /* enlarged */
.smallBtn.primary{background:var(--accent);border:none}
.smallBtn.warn{background:#b54b4b}

/* friend lists (boxed cards) */
.sectionTitle{font-weight:700;color:#dfe7ff;margin-bottom:6px;font-size:14px}
.listBox{display:flex;flex-direction:column;gap:8px;padding:6px;background:transparent}
.listBox.small{max-height:180px;overflow:auto} /* slightly larger */
.listBox.large{max-height:320px;overflow:auto}
.friendCard{display:flex;gap:10px;align-items:center;padding:10px;background:var(--card);border-radius:10px;cursor:pointer}
.friendCard img{width:56px;height:56px;border-radius:10px;object-fit:cover;flex:0 0 56px;border:2px solid #111}
.friendCard .name{font-weight:700;font-size:16px;color:#eef7ff}

/* block button row */
.blockRow{margin-top:8px}

/* chat area */
.chatHeader{padding:10px 12px;color:var(--muted);font-size:15px}
.chatWindow{flex:1;overflow:auto;padding:16px;border-radius:8px;background:linear-gradient(180deg, rgba(255,255,255,0.01), transparent)}
.msgRow{display:flex;gap:10px;margin-bottom:10px;align-items:flex-end}
.msgRow.mine{justify-content:flex-end}
.msgBubble{background:rgba(30,30,30,0.95);padding:14px;border-radius:12px;max-width:70%;box-sizing:border-box;word-break:break-word;position:relative}
.msgBubble.mine{background:var(--mine);color:#fff}
.msgMeta{font-size:12px;color:#999;margin-top:6px;text-align:right}
.replySnippet{border-left:3px solid rgba(255,255,255,0.06);padding:8px;margin-bottom:6px;color:#ccc;font-size:13px;border-radius:6px;background:rgba(0,0,0,0.04);overflow:hidden;max-width:100%}
.msgActions{position:absolute;right:6px;top:6px;display:flex;gap:6px;opacity:0;transition:opacity .12s}
.msgBubble:hover .msgActions{opacity:1}
.actionBtn{background:rgba(0,0,0,.35);border:0;color:#fff;padding:6px 8px;border-radius:8px;cursor:pointer;font-size:14px}

/* big emoji */
.big-emoji{font-size:48px;line-height:1;vertical-align:middle;margin:0 2px}

/* reply preview */
#replyPreview { background:#151515;border:1px solid #2a2a2a;padding:10px;border-radius:10px;color:#ddd;margin:8px 12px;display:none;align-items:center;gap:8px; }
#replyPreview .rp-title{font-weight:700;margin-right:8px}
#replyPreview .rp-cancel{margin-left:auto;background:transparent;border:0;color:#f66;cursor:pointer;font-size:18px}

/* input */
.inputArea{display:flex;gap:8px;padding:14px;border-top:1px solid #222;align-items:center}
#msg{flex:1;padding:12px;border-radius:10px;border:0;background:#141416;color:#fff;font-size:16px}
.sendBtn{background:var(--accent);border:0;padding:12px 16px;border-radius:10px;color:white;cursor:pointer;font-size:16px}
.charCount{font-size:13px;color:var(--muted);min-width:8px;text-align:right}

/* notification bell (room.php style) */
.bell { position:relative; cursor:pointer; padding:8px 10px; border-radius:10px; background:rgba(255,255,255,0.02); display:inline-flex; align-items:center; gap:8px;}
.badge { position:absolute; top:-6px; right:-6px; background:#ff4d4f; color:white; border-radius:12px; padding:3px 8px; font-size:13px; min-width:28px; text-align:center; }
.notifBox{position:absolute; right:12px; top:48px; background:#0b1114; border-radius:8px; padding:12px; min-width:320px; max-width:420px; box-shadow:0 8px 24px rgba(0,0,0,.6); display:none; z-index:1000}
.notifGroup{display:flex;gap:10px;align-items:center;padding:8px;border-radius:8px;cursor:pointer}
.notifGroup .avatar{width:44px;height:44px;border-radius:8px;background:#222;display:flex;align-items:center;justify-content:center;color:#fff;flex:0 0 44px;overflow:hidden}
.notifGroup .meta{flex:1;min-width:0}
.notifGroup .meta .title{font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.notifGroup .meta .msg{color:var(--muted);font-size:13px;margin-top:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
#notifDropdown{position:fixed;left:8px;right:8px;top:64px;background:#0d0d0e;padding:10px;border-radius:10px;z-index:60;max-height:60vh;overflow:auto;display:none}
.notifWrap{position:relative}

/* responsive: hide sidebar on small screens until toggled */
@media(max-width:900px){
  .wrapper{padding:8px}
  .sidebar{display:none; position:fixed; left:8px; top:64px; bottom:8px; z-index:1200; width:320px; box-shadow:0 12px 40px rgba(0,0,0,0.6)}
  .sidebar.visible{display:flex}
  /* add a backdrop when sidebar open */
  #sidebarOverlay{display:none; position:fixed; left:0; top:0; right:0; bottom:0; background:rgba(0,0,0,0.45); z-index:1100}
  #sidebarOverlay.visible{display:block}
}

/* helpers */
.divider{height:1px;background:#1f2124;margin:10px 0;border-radius:2px}
.topBtn{background:var(--accent);color:#fff;border:0;padding:10px 12px;border-radius:10px;font-size:16px;cursor:pointer}
</style>
</head>
<body>

<div class="topbar">
  <div style="display:flex;gap:10px;align-items:center">
    <button class="topIcon" id="sidebarToggle" title="Toggle sidebar">☰</button>
    <button class="topBtn" onclick="location.href='mobile_room.php'">← Back</button>
    <div style="color:#cfcfcf;margin-left:6px">Personal messages</div>
  </div>

  <!-- Right side: notification bell inserted here -->
  <div style="position:relative">
		<div id="notifBell" class="iconBtn notifWrap" title="Notifications">🔔<span id="notifBadge" class="badge" style="display:none">0</span></div>
      <span id="notifBadge" class="badge" style="display:none">0</span>
    </div>

  <div id="notifDropdown"></div>

  <!-- action menu (long-press) -->
  <div id="actionMenu" role="menu" aria-hidden="true"></div>

  <audio id="bell" preload="auto"><source src="root/bell.mp3" type="audio/mpeg"></audio>
  <audio id="bell2" preload="auto"><source src="root/bell_2.mp3" type="audio/mpeg"></audio>
</div>

<!-- overlay for mobile sidebar -->
<div id="sidebarOverlay"></div>

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
      </div>
    </div>

    <div class="divider" aria-hidden="true"></div>

    <div>
      <div class="sectionTitle">Your friends</div>
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

const POLL_INTERVAL = 30000; // notifications poll
const NOTIF_API = 'notifications.php';
const notifBell = document.getElementById('notifBell');
const notifBadge = document.getElementById('notifBadge');
const notifDropdown = document.getElementById('notifDropdown');

let lastUnread = 0;
let pollInterval = null;
const POLL_MS = 30000;
const MARKED = new Set();

/* ---------- notifications ---------- */
async function fetchNotifications(limit=50){
  try {
    const res = await fetch(NOTIF_API + '?limit=' + encodeURIComponent(limit), { credentials:'same-origin' });
    if (!res.ok) throw new Error('fetch failed');
    return await res.json();
  } catch (e) { console.error('fetchNotifications', e); return { notifications: [], unread_count: 0 }; }
}
function renderNotifRow(n){
  const row = document.createElement('div');
  row.style.padding = '8px'; row.style.borderBottom = '1px solid rgba(255,255,255,0.02)';
  row.innerHTML = `<div style="font-weight:700">${escapeHtml(n.source_username||'System')}</div><div style="color:var(--muted);font-size:13px">${escapeHtml((n.message||'').slice(0,120))}</div>`;
  row.addEventListener('click', async ()=> {
    try { await fetch(NOTIF_API, { method:'POST', credentials:'same-origin', body: new URLSearchParams({ action:'mark_read', id: n.id }) }); } catch(e){}
    notifDropdown.style.display = 'none';
    if (n.type === 'dm' && n.source_username) location.href = 'mobile_message.php?user=' + encodeURIComponent(n.source_username);
    else if (n.type === 'modmail' && n.ref_id) location.href = 'modmail.php?id=' + encodeURIComponent(n.ref_id);
  });
  return row;
}
async function loadNotifs(){
  const j = await fetchNotifications(200);
  if (!j) return;
  const unread = j.unread_count || 0;
  if (unread > (lastUnread || 0) && lastUnread !== 0) { if (audioUnlocked) try { bell2.currentTime = 0; bell2.play().catch(()=>{}); } catch(e){} }
  lastUnread = unread;
  if (unread > 0) { notifBadge.style.display = 'inline-block'; notifBadge.textContent = unread > 99 ? '99+' : String(unread); } else notifBadge.style.display = 'none';
  notifDropdown.innerHTML = '';
  const rows = Array.isArray(j.notifications) ? j.notifications : [];
  if (rows.length === 0) { notifDropdown.innerHTML = '<div class="empty">No notifications</div>'; return; }
  rows.forEach(n => notifDropdown.appendChild(renderNotifRow(n)));
}
notifBell.addEventListener('click', (e)=> { e.stopPropagation(); notifDropdown.style.display = notifDropdown.style.display === 'block' ? 'none' : 'block'; if (notifDropdown.style.display === 'block') loadNotifs(); });
document.addEventListener('click', (e)=> { if (!e.target.closest || (!e.target.closest('#notifBell') && !e.target.closest('#notifDropdown'))) notifDropdown.style.display = 'none'; });

</script>

<script>
// ---------- rest of message.php JS (mostly unchanged) ----------
/* Notes about changes:
   - buttons enlarged and sidebar toggle implemented
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
  node.addEventListener('click', ()=> { window.location.href = 'mobile_message.php?user=' + encodeURIComponent(f.username || ''); });
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

/* ---------- sidebar toggle (mobile) ---------- */
const sidebar = document.getElementById('sidebar');
const sidebarToggle = document.getElementById('sidebarToggle');
const sidebarOverlay = document.getElementById('sidebarOverlay');

if (sidebarToggle) {
  sidebarToggle.addEventListener('click', (e)=> {
    e.stopPropagation();
    if (sidebar.classList.contains('visible')) {
      sidebar.classList.remove('visible');
      sidebarOverlay.classList.remove('visible');
    } else {
      sidebar.classList.add('visible');
      sidebarOverlay.classList.add('visible');
    }
  });
  // overlay click hides
  if (sidebarOverlay) sidebarOverlay.addEventListener('click', ()=> {
    sidebar.classList.remove('visible');
    sidebarOverlay.classList.remove('visible');
  });
  // clicking outside sidebar should close it on small screens
  document.addEventListener('click', (e)=> {
    if (!e.target.closest || (e.target.closest('#sidebar') || e.target.closest('#sidebarToggle'))) return;
    if (window.innerWidth <= 900) {
      sidebar.classList.remove('visible');
      if (sidebarOverlay) sidebarOverlay.classList.remove('visible');
    }
  });
}

/* ---------- start ---------- */
loadOnce().then(()=> { longPollLoop(); });
window.addEventListener('beforeunload', ()=> running = false);
setInterval(()=> { if (!document.hidden) loadNotifs(); }, POLL_INTERVAL);
</script>

</body>
</html>