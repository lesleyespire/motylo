<?php
require "config.php";

// --- room code ---
$code = $_GET['code'] ?? '';
if ($code === '') { die("No private code provided."); }

// check private_rooms
$stmt = $pdo->prepare("SELECT id, code FROM private_rooms WHERE code = ?");
$stmt->execute([$code]);
$room = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$room) { die("Invalid private code."); }
$room_id = (int)$room['id'];
$room_code = $room['code'];

// optional per-room background detection (safe)
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

// auth user
if (empty($_COOKIE['auth_token'])) { header("Location:index.php"); exit; }
$stmt = $pdo->prepare("SELECT id, username, avatar FROM users WHERE auth_token = ?");
$stmt->execute([$_COOKIE['auth_token']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { header("Location:index.php"); exit; }
$username = htmlspecialchars($user['username']);
$avatar = $user['avatar'];
$user_id = (int)$user['id'];
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Private Room — <?= htmlspecialchars($room_code) ?></title>
<link rel="icon" href="root/favicon.ico" id="favicon">
<style>
:root{
  --accent:#5865F2;
  --bg:#0e0e0e;
  --panel:#121315;
  --muted:#bfbfbf;
  --glass: rgba(255,255,255,0.03);
  --radius:10px;
  --gap:12px;
  font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;
}
html,body{height:100%;margin:0;background:var(--bg);color:#eef3ff}
<?php if ($background_url): ?>
body{background-image:url('<?= htmlspecialchars($background_url, ENT_QUOTES) ?>');background-size:cover;background-position:center;background-repeat:no-repeat}
.bg-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.45);pointer-events:none;z-index:0}
<?php endif; }

/* ---------- general layout ---------- */
.app{max-width:1200px;margin:20px auto;padding:0 12px;display:grid;grid-template-columns:320px 1fr;gap:18px;align-items:start}
.panel{background:linear-gradient(180deg, rgba(255,255,255,0.02), transparent);padding:12px;border-radius:12px}
.sidebar{height:calc(100vh - 48px);overflow:auto}
.header{display:flex;align-items:center;justify-content:space-between;padding:10px 12px;background:transparent;border-radius:8px}
.header .left{display:flex;gap:12px;align-items:center}
.avatarWrap{width:52px;height:52px;border-radius:10px;overflow:hidden;background:#111;display:flex;align-items:center;justify-content:center;flex:0 0 52px}
.avatarWrap img{width:100%;height:100%;object-fit:cover;display:block}
.usernameTitle{font-weight:800}
.roomInfo{color:var(--muted);font-size:13px}

/* chat area */
.chatArea{display:flex;flex-direction:column;gap:10px;height:calc(100vh - 80px)}
.chatWindow{flex:1;padding:18px;overflow:auto;border-radius:12px;background:rgba(0,0,0,0.02)}
.msgRow{display:flex;gap:10px;margin-bottom:10px;align-items:flex-start;position:relative}
.msgRow.mine{justify-content:flex-end}
.avatarSmall{width:42px;height:42px;border-radius:8px;overflow:hidden;flex:0 0 42px}
.avatarSmall img{width:100%;height:100%;object-fit:cover}
.msgBubble{background:rgba(30,30,30,0.95);padding:10px;border-radius:10px;max-width:70%;box-sizing:border-box;word-break:break-word}
.msgBubble.mine{background:var(--accent);color:#fff}
.username{font-weight:bold;margin-bottom:6px}
.msgTime{font-size:11px;color:#888;min-width:25px;text-align:right;align-self:flex-end}

/* input bar */
.inputBar{display:flex;gap:8px;align-items:center;padding:10px;border-radius:12px;background:linear-gradient(180deg, rgba(255,255,255,0.01), transparent)}
#msg{flex:1;padding:10px;border-radius:8px;border:0;background:#121214;color:#fff;font-size:15px}
.sendBtn{background:var(--accent);border:0;padding:10px 14px;border-radius:10px;color:#fff;cursor:pointer}
.smallBtn{background:transparent;border:1px solid rgba(255,255,255,0.04);color:#fff;padding:8px 10px;border-radius:10px;cursor:pointer}

/* reply preview */
#replyPreview{display:none;align-items:center;gap:10px;background:#151515;border:1px solid #222;padding:8px;border-radius:8px;color:#ddd}

/* emoji picker */
#emojiPicker{display:none;position:absolute;bottom:86px;left:12px;width:360px;max-height:260px;overflow:auto;background:#222;border:1px solid #444;border-radius:8px;padding:8px;z-index:200}

/* notifications */
.bell{position:relative;cursor:pointer;padding:8px;border-radius:8px;background:var(--glass);display:inline-flex;align-items:center;gap:8px}
.badge{position:absolute;top:-6px;right:-6px;background:#ff4d4f;color:#fff;border-radius:999px;padding:4px 7px;font-size:12px;min-width:24px;text-align:center}
.notifBox{position:absolute;right:12px;top:56px;background:var(--panel);border-radius:10px;padding:10px;min-width:340px;max-height:420px;overflow:auto;box-shadow:0 8px 28px rgba(0,0,0,0.6);display:none;z-index:1000}
.notifGroup{display:flex;gap:10px;align-items:center;padding:8px;border-radius:8px;cursor:pointer}
.notifGroup.unread{background:rgba(255,255,255,0.02)}
.unreadDot{width:10px;height:10px;border-radius:50%;background:#ff4d4f;margin-left:8px}

/* hover card */
#hoverCard{position:absolute;z-index:1500;min-width:180px;background:#111;border:1px solid #222;padding:10px;border-radius:8px;color:#fff;box-shadow:0 6px 24px rgba(0,0,0,.6);display:none}

/* small helper */
.hidden{display:none}

/* ---------- MOBILE ADAPTIVE ---------- */
@media (max-width:900px){
  .app{grid-template-columns:1fr;gap:8px;margin:0}
  .sidebar{order:2;height:auto}
  .chatArea{order:1;height:calc(100vh - 120px)}
  .panel{border-radius:0;padding:10px}
  .header{padding:10px}
  .chatWindow{padding:12px;height:calc(100vh - 210px)}
  /* bottom fixed input for mobile */
  .inputBar{position:fixed;left:8px;right:8px;bottom:12px;z-index:120;border-radius:12px;padding:10px;background:linear-gradient(180deg, rgba(0,0,0,0.45), rgba(0,0,0,0.25))}
  body{padding-bottom:86px}
  /* banners / click targets larger */
  .avatarWrap{width:44px;height:44px}
  .avatarSmall{width:40px;height:40px}
  #emojiPicker{left:8px;bottom:110px;width:calc(100% - 16px)}
  .notifBox{left:8px;right:8px;top:56px;min-width:auto}
}
</style>
</head>
<body>
<?php if ($background_url): ?><div class="bg-overlay"></div><?php endif; ?>

<!-- sounds -->
<audio id="bell" preload="auto"><source src="root/bell.mp3" type="audio/mpeg"></audio>
<audio id="bell2" preload="auto"><source src="root/bell_2.mp3" type="audio/mpeg"></audio>

<div class="app" role="application" aria-label="Private chat app">

  <!-- SIDEBAR -->
  <aside class="panel sidebar" id="sidebar">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px">
      <?php if($avatar): ?>
        <div class="avatarWrap"><a href="user.php?username=<?= rawurlencode($username) ?>"><img src="avatars/<?= htmlspecialchars($avatar, ENT_QUOTES) ?>" alt="avatar"></a></div>
      <?php else: ?>
        <div class="avatarWrap" style="font-weight:800;display:flex;align-items:center;justify-content:center"><?= strtoupper($username[0] ?? '?') ?></div>
      <?php endif; ?>
      <div>
        <div class="usernameTitle"><?= $username ?></div>
        <div class="roomInfo">Room: <strong><?= htmlspecialchars($room_code) ?></strong></div>
      </div>
    </div>

    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
      <button class="smallBtn" onclick="joinPrivate()">Join Room</button>
      <a class="smallBtn" href="private_voice.php?room=<?= urlencode($room_code) ?>">Voice</a>
      <a class="smallBtn" href="private_mobile.php?code=<?= urlencode($room_code) ?>">Mobile UI</a>
    </div>

    <div>
      <div style="font-weight:700;margin-bottom:8px;color:#eaf0ff">Friends</div>
      <div id="friendsList" style="color:var(--muted);font-size:14px;max-height:40vh;overflow:auto">Loading…</div>
    </div>
  </aside>

  <!-- MAIN -->
  <main class="panel chatArea" id="main">
    <div class="header" role="banner">
      <div class="left">
        <div>
          <div style="font-weight:900;font-size:18px">Private — <?= htmlspecialchars($room_code) ?></div>
          <div style="color:var(--muted);font-size:13px">Personal conversation</div>
        </div>
      </div>

      <div style="display:flex;align-items:center;gap:8px">
        <div id="notifBell" class="bell" title="Notifications" aria-haspopup="true" aria-expanded="false">🔔 <span id="notifBadge" class="badge" style="display:none">0</span></div>
        <button class="smallBtn" id="openFriendsBtn" title="Friends">👥</button>
      </div>

      <!-- notifications dropdown -->
      <div id="notifDropdown" class="notifBox" aria-hidden="true" style="display:none">
        <div class="markAll" style="display:flex;justify-content:flex-end;margin-bottom:8px"><button id="markAllBtn" style="background:transparent;border:0;color:var(--accent);cursor:pointer">Mark all read</button></div>
        <div id="notifList" style="max-height:420px;overflow:auto"><div style="padding:12px;color:var(--muted)">Loading…</div></div>
      </div>
    </div>

    <div id="chat" class="chatWindow" aria-live="polite" role="log"></div>

    <div id="replyPreview" role="status" aria-live="polite">
      <span class="rp-title">Replying to <strong id="rpUser"></strong>:</span>
      <span id="rpText" style="opacity:.9"></span>
      <button class="rp-cancel" id="rpCancel" title="Cancel reply">✖</button>
    </div>

    <div class="inputBar" id="inputBar" role="form" aria-label="Message input">
      <button id="emojiBtn" class="smallBtn" aria-label="Emoji picker">😊</button>
      <input id="msg" placeholder="Send your message here..." autocomplete="off" aria-label="Message" maxlength="750" />
      <input id="imageInput" type="file" accept="image/*" style="display:none" />
      <button id="imageBtn" class="smallBtn" title="Upload image">📷</button>
      <div style="display:flex;align-items:center;gap:8px">
        <div id="charCount" style="font-size:12px;color:var(--muted)">0/750</div>
        <button class="sendBtn" id="sendBtn" onclick="send()">Send</button>
      </div>
    </div>

  </main>

</div>

<!-- emoji picker -->
<div id="emojiPicker" aria-hidden="true"></div>

<!-- hover card -->
<div id="hoverCard" role="tooltip" aria-hidden="true"></div>

<!-- image modal -->
<div id="imageModal" onclick="this.style.display='none'" style="display:none;position:fixed;inset:0;align-items:center;justify-content:center;background:rgba(0,0,0,0.85);z-index:1200">
  <img id="imageModalImg" src="" style="max-width:94%;max-height:94%;border-radius:10px;box-shadow:0 12px 40px rgba(0,0,0,.6)">
</div>

<script>
/* ---------- constants & helpers ---------- */
const ROOM_CODE = <?= json_encode($room_code) ?>;
const MY_ID = <?= json_encode($user_id) ?>;
const API_NOTIF = 'notifications.php';
const API_PRIVATE = 'private_interface.php';
const API_UPLOAD = 'upload_image.php';
const POLL_INTERVAL = 30000; // notifications poll
const MSG_POLL_MS = 1000; // message poll
const MAX_MESSAGE_LENGTH = 750;
const MAX_IMAGE_UPLOAD_BYTES = 6 * 1024 * 1024;

const chatEl = document.getElementById('chat');
const msgEl = document.getElementById('msg');
const sendBtn = document.getElementById('sendBtn');
const charCountEl = document.getElementById('charCount');
const notifBell = document.getElementById('notifBell');
const notifBadge = document.getElementById('notifBadge');
const notifDropdown = document.getElementById('notifDropdown');
const notifList = document.getElementById('notifList');
const markAllBtn = document.getElementById('markAllBtn');
const emojiPicker = document.getElementById('emojiPicker');
const emojiBtn = document.getElementById('emojiBtn');
const imageBtn = document.getElementById('imageBtn');
const imageInput = document.getElementById('imageInput');
const replyPreviewEl = document.getElementById('replyPreview');
const rpUser = document.getElementById('rpUser');
const rpText = document.getElementById('rpText');
const rpCancel = document.getElementById('rpCancel');
const hoverCard = document.getElementById('hoverCard');

const bell = document.getElementById('bell');
const bell2 = document.getElementById('bell2');
let audioUnlocked = false;
document.addEventListener('pointerdown', ()=> audioUnlocked = true, { once:true });

let lastId = 0;
let lastUsernameInDOM = null;
let currentUser = null;
let running = true;
let pollInFlight = false;
let replyingTo = null;
let lastUnread = 0;
let notifPolling = null;
const marked = new Set();
let notifObserver = null;

/* helper */
function escapeHtml(s){ if (s==null) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }
function parseServerTS(ts){ if (!ts) return null; if (/[zZ]|[+\-]\d{2}:\d{2}$/.test(ts)) return new Date(ts); return new Date(ts + 'Z'); }
function relativeTimeObj(ts){ const d = parseServerTS(ts); if (!d || isNaN(d)) return null; const now = Date.now(); const diff = Math.round((now - d.getTime())/1000); let txt=''; if (diff < 5) txt='just now'; else if (diff < 60) txt = diff + 's'; else if (diff < 3600) txt = Math.round(diff/60) + 'm'; else if (diff < 86400) txt = Math.round(diff/3600) + 'h'; else if (diff < 7*86400) txt = Math.round(diff/86400) + 'd'; else txt = d.toLocaleDateString(); return { label: txt, title: d.toLocaleString() }; }

/* ---------- emoji picker ---------- */
const EMOJIS = ["😀","😁","😂","🤣","😃","😄","😅","😉","😊","🙂","🙃","😍","😘","😎","🤔","🤨","😴","😡","😭","👍","👎","👏","🙏","🔥","✨","💯","❤️","⭐","🍕","☕","🎉"];
function populateEmojiPicker(){
  emojiPicker.innerHTML = EMOJIS.map(e=>`<button style="font-size:20px;padding:6px;margin:4px;border:0;background:transparent">${e}</button>`).join('');
  emojiPicker.querySelectorAll('button').forEach(btn => btn.addEventListener('click', ()=>{
    const start = msgEl.selectionStart || 0; const end = msgEl.selectionEnd || 0;
    msgEl.value = msgEl.value.slice(0,start) + btn.textContent + msgEl.value.slice(end);
    msgEl.focus(); msgEl.selectionStart = msgEl.selectionEnd = start + btn.textContent.length;
    updateCharCount();
    emojiPicker.style.display = 'none';
  }));
}
emojiBtn.addEventListener('click', (e)=>{ e.stopPropagation(); if (!emojiPicker.innerHTML) populateEmojiPicker(); emojiPicker.style.display = emojiPicker.style.display === 'block' ? 'none' : 'block'; });
document.addEventListener('click', e=>{ if (!emojiPicker.contains(e.target) && e.target !== emojiBtn) emojiPicker.style.display='none'; });

/* ---------- message rendering ---------- */
function highlightEmojisEscaped(escapedText){
  return escapedText.replace(/([\u231A-\u1F9FF\u2600-\u26FF\u2700-\u27BF])/g, '<span class="big-emoji">$1</span>');
}
const IMAGE_MD_RE = /!\[image\]\((\/images\/[^\s)]+)\)/g;
function renderMessageContentRaw(msg){
  if (!msg) return '';
  let escaped = escapeHtml(msg);
  escaped = escaped.replace(IMAGE_MD_RE, function(full, path){
    if (!path || !path.startsWith('/images/')) return full;
    const encoded = '/images/' + encodeURIComponent(path.slice('/images/'.length));
    return `<img src="${encoded}" class="chatImage chatImageSmall" loading="lazy" onclick="openImageModal(this.src)" />`;
  });
  escaped = highlightEmojisEscaped(escaped);
  return escaped;
}

function openImageModal(src){
  const modal = document.getElementById('imageModal');
  const img = document.getElementById('imageModalImg');
  img.src = src;
  modal.style.display = 'flex';
}

function appendMessages(messages){
  if (!Array.isArray(messages) || !messages.length) return;
  for (const m of messages){
    if (!m || !m.id) continue;
    if (m.id <= lastId) continue;

    const sameAsPrevious = (m.username && lastUsernameInDOM && m.username === lastUsernameInDOM);
    const role_color = m.role_color || '#9bbcff';
    const badgeText = m.role_badge || '';
    const isMine = currentUser && ((Number(currentUser.id) === Number(m.user_id)) || (currentUser.username === m.username));
    const createdDate = parseServerTS(m.created_at);
    const canEdit = isMine && createdDate && ((Date.now() - createdDate.getTime()) <= (10*60*1000)) && !m.deleted_at;

    const row = document.createElement('div');
    row.className = 'msgRow' + (isMine ? ' mine' : '');
    row.dataset.id = m.id;
    row.dataset.userId = m.user_id || '';
    row.dataset.username = m.username || '';
    row.dataset.created = m.created_at || '';
    row.dataset.edited = m.edited_at || '';
    row.dataset.deleted = m.deleted_at || '';

    // avatar
    if (!sameAsPrevious){
      const avWrap = document.createElement('div'); avWrap.className = 'avatarSmall';
      const a = document.createElement('a'); a.href = 'user.php?username=' + encodeURIComponent(m.username || '');
      if (m.avatar) a.innerHTML = `<img src="avatars/${escapeHtml(m.avatar)}">`; else a.textContent = (m.username||'?')[0].toUpperCase();
      avWrap.appendChild(a);
      row.appendChild(avWrap);
    } else {
      const spacer = document.createElement('div'); spacer.style.width = '42px'; row.appendChild(spacer);
    }

    // bubble
    const bubble = document.createElement('div');
    bubble.className = 'msgBubble' + (isMine ? ' mine' : '');
    if (!sameAsPrevious && m.username){
      const nameDiv = document.createElement('div'); nameDiv.className='username'; nameDiv.style.color = role_color;
      const nameA = document.createElement('a'); nameA.className='userLink'; nameA.href = 'user.php?username=' + encodeURIComponent(m.username || ''); nameA.dataset.username = m.username || ''; nameA.dataset.avatar = m.avatar || ''; nameA.dataset.role = m.role || ''; nameA.textContent = m.username;
      nameDiv.appendChild(nameA); bubble.appendChild(nameDiv);
    }

    if (m.reply_to_username || m.reply_to_excerpt){
      const snippet = document.createElement('div'); snippet.className='replySnippet';
      const ruser = document.createElement('div'); ruser.className='r-user'; ruser.textContent = m.reply_to_username || '…'; snippet.appendChild(ruser);
      const rex = document.createElement('div'); rex.textContent = m.reply_to_excerpt || ''; snippet.appendChild(rex);
      bubble.appendChild(snippet);
    }

    const content = document.createElement('div'); content.className = 'msgText';
    if (m.deleted_at){ content.style.opacity = '.6'; content.style.fontStyle = 'italic'; content.textContent = m.message || ''; }
    else content.innerHTML = renderMessageContentRaw(m.message || '');
    bubble.appendChild(content);

    // actions
    const actions = document.createElement('div'); actions.className='msgActions';
    if (!m.deleted_at){
      const replyBtn = document.createElement('button'); replyBtn.className='actionBtn replyBtn'; replyBtn.dataset.id = m.id; replyBtn.dataset.user = m.username || ''; replyBtn.dataset.excerpt = (m.message || '').slice(0,140); replyBtn.textContent='Reply'; actions.appendChild(replyBtn);
    }
    if (canEdit){ const editBtn = document.createElement('button'); editBtn.className='actionBtn editBtn'; editBtn.dataset.id = m.id; editBtn.textContent='Edit'; actions.appendChild(editBtn); }
    bubble.appendChild(actions);

    row.appendChild(bubble);

    const timeDiv = document.createElement('div'); timeDiv.className='msgTime';
    const rel = relativeTimeObj(m.created_at);
    timeDiv.title = rel ? rel.title : (m.created_at || '');
    timeDiv.textContent = (rel ? rel.label : (m.created_at || '')) + (m.edited_at ? ' • edited' : '');
    row.appendChild(timeDiv);

    chatEl.appendChild(row);
    lastUsernameInDOM = m.username || null;
    lastId = Math.max(lastId, m.id || 0);
  }

  // scroll to bottom when messages appended
  chatEl.scrollTop = chatEl.scrollHeight;
}

/* ---------- hover card (profile preview) ---------- */
function showHoverCardFromData(data, x, y){
  if (!data) return hideHoverCard();
  hoverCard.innerHTML = '';
  const row = document.createElement('div'); row.className='hc-row';
  const av = document.createElement('div'); av.className='hc-avatar';
  if (data.avatar) { const img = document.createElement('img'); img.src = 'avatars/' + encodeURIComponent(data.avatar); img.style.width='56px'; img.style.height='56px'; img.style.objectFit='cover'; av.appendChild(img); }
  else { const ph = document.createElement('div'); ph.style.width='56px'; ph.style.height='56px'; ph.style.display='flex'; ph.style.alignItems='center'; ph.style.justifyContent='center'; ph.style.background='#5865F2'; ph.style.borderRadius='50%'; ph.textContent = (data.username||'?')[0]||'?'; av.appendChild(ph); }
  row.appendChild(av);
  const info = document.createElement('div');
  const name = document.createElement('div'); name.className='hc-name'; name.textContent = data.username||''; info.appendChild(name);
  if (data.role) { const r = document.createElement('div'); r.className='hc-role'; r.textContent = (data.role_badge ? data.role_badge + ' ' : '') + data.role; info.appendChild(r); }
  const link = document.createElement('a'); link.className='profileLink'; link.href = 'user.php?username=' + encodeURIComponent(data.username||''); link.textContent='View profile'; info.appendChild(link);
  row.appendChild(info);
  hoverCard.appendChild(row);
  const pad = 12, cardW = 220, cardH = 88;
  let left = x + 12, top = y + 18;
  if (left + cardW + pad > window.innerWidth) left = window.innerWidth - cardW - pad;
  if (top + cardH + pad > window.innerHeight) top = window.innerHeight - cardH - pad;
  hoverCard.style.left = left + 'px'; hoverCard.style.top = top + 'px'; hoverCard.style.display = 'block'; hoverCard.setAttribute('aria-hidden','false');
}
function hideHoverCard(){ hoverCard.style.display = 'none'; hoverCard.setAttribute('aria-hidden','true'); }

/* delegated hover to show hover card */
document.addEventListener('mouseover', ev => { const el = ev.target.closest('.userLink, .avatarLink'); if (!el) return; const data = { username: el.getAttribute('data-username') || el.dataset.username || el.textContent || '', avatar: el.getAttribute('data-avatar') || el.dataset.avatar || '', role: el.getAttribute('data-role') || el.dataset.role || '' }; showHoverCardFromData(data, ev.pageX, ev.pageY); });
document.addEventListener('mousemove', ev => { const el = ev.target.closest('.userLink, .avatarLink'); if (!el) return; const data = { username: el.getAttribute('data-username') || el.dataset.username || el.textContent || '', avatar: el.getAttribute('data-avatar') || el.dataset.avatar || '', role: el.getAttribute('data-role') || el.dataset.role || '' }; showHoverCardFromData(data, ev.pageX, ev.pageY); });
document.addEventListener('mouseout', ev => { if (!ev.relatedTarget || !ev.relatedTarget.closest || !ev.target.closest('.userLink, .avatarLink')) hideHoverCard(); });

/* ---------- delegated click handlers for reply/edit/delete ---------- */
chatEl.addEventListener('click', async (ev) => {
  const btn = ev.target.closest('.replyBtn, .editBtn, .deleteBtn');
  if (!btn) return;
  const id = btn.dataset.id; if (!id) return;

  if (btn.classList.contains('replyBtn')) {
    replyingTo = { id: id, username: btn.dataset.user || '', excerpt: btn.dataset.excerpt || '' }; showReplyPreview(replyingTo); msgEl.focus(); return;
  }

  if (btn.classList.contains('editBtn')) {
    const row = btn.closest('.msgRow'); if (!row) return; if (row.querySelector('.editArea')) return;
    const textDiv = row.querySelector('.msgText'); const orig = textDiv ? textDiv.textContent : ''; textDiv.style.display = 'none';
    const editArea = document.createElement('div'); editArea.className='editArea';
    const ta = document.createElement('textarea'); ta.value = orig; ta.style.width = '100%'; ta.style.minHeight = '80px';
    editArea.appendChild(ta);
    const save = document.createElement('button'); save.className = 'smallBtn'; save.textContent = 'Save';
    const cancel = document.createElement('button'); cancel.className = 'smallBtn'; cancel.textContent = 'Cancel';
    editArea.appendChild(save); editArea.appendChild(cancel);
    row.querySelector('.msgBubble').appendChild(editArea); ta.focus();
    let pending = false;
    save.addEventListener('click', async ()=> {
      if (pending) return; const newText = ta.value.trim(); if (!newText) return alert('Message cannot be blank'); if (Array.from(newText).length > MAX_MESSAGE_LENGTH) return alert('Message too long');
      pending = true; save.disabled = cancel.disabled = true;
      try {
        const body = 'id=' + encodeURIComponent(id) + '&message=' + encodeURIComponent(newText);
        const resp = await fetch(API_PRIVATE + '?room=' + encodeURIComponent(ROOM_CODE) + '&mode=edit', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body, credentials:'same-origin' });
        const j = await resp.json();
        if (j && j.ok) { textDiv.innerHTML = renderMessageContentRaw(newText); textDiv.style.display=''; row.dataset.edited = new Date().toISOString(); const tdiv = row.querySelector('.msgTime'); if (tdiv) tdiv.textContent = (tdiv.title || '') + ' • edited'; editArea.remove(); } else { alert(j.error || 'Edit failed'); save.disabled = cancel.disabled = false; }
      } catch(e){ alert('Edit failed'); save.disabled = cancel.disabled = false; }
      pending = false;
    });
    cancel.addEventListener('click', ()=> { editArea.remove(); textDiv.style.display=''; });
    return;
  }

  if (btn.classList.contains('deleteBtn')) {
    if (!confirm('Delete this message?')) return;
    try {
      const body = 'id=' + encodeURIComponent(id);
      const resp = await fetch(API_PRIVATE + '?room=' + encodeURIComponent(ROOM_CODE) + '&mode=delete', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body, credentials:'same-origin' });
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
rpCancel.addEventListener('click', clearReply);
function showReplyPreview(obj){ if (!obj) return clearReply(); replyPreviewEl.style.display='flex'; rpUser.textContent = obj.username || '…'; rpText.textContent = (obj.excerpt || '').slice(0,240); }
function clearReply(){ replyingTo = null; replyPreviewEl.style.display='none'; rpUser.textContent=''; rpText.textContent=''; }

/* ---------- typing notification ---------- */
let lastTypingAt = 0;
function sendTypingIfNeeded(){ const now = Date.now(); if (now - lastTypingAt < 1000) return; lastTypingAt = now; navigator.sendBeacon ? navigator.sendBeacon(API_PRIVATE + '?room=' + encodeURIComponent(ROOM_CODE) + '&mode=typing') : fetch(API_PRIVATE + '?room=' + encodeURIComponent(ROOM_CODE) + '&mode=typing', { method:'POST', keepalive:true, credentials:'same-origin' }).catch(()=>{}); }
msgEl.addEventListener('input', sendTypingIfNeeded);

/* ---------- send message ---------- */
async function send(){
  const ta = msgEl;
  const text = (ta.value || '').trim();
  if (!text) return;
  if (Array.from(text).length > MAX_MESSAGE_LENGTH) { alert('Message too long'); return; }
  try {
    const fd = new FormData();
    fd.append('message', text);
    if (replyingTo && replyingTo.id) fd.append('reply_to', replyingTo.id);
    await fetch(API_PRIVATE + '?room=' + encodeURIComponent(ROOM_CODE) + '&mode=send', { method:'POST', body: fd, credentials:'same-origin' });
  } catch(e){ console.error('send failed', e); alert('Send failed'); }
  ta.value=''; updateCharCount(); clearReply(); await immediatePoll();
}
msgEl.addEventListener('keydown', (e)=>{ if (e.key === 'Enter' && !e.shiftKey){ e.preventDefault(); send(); }});

/* ---------- char count ---------- */
function updateCharCount(){ const len = Array.from(msgEl.value || '').length; charCountEl.textContent = len + '/' + MAX_MESSAGE_LENGTH; if (len > MAX_MESSAGE_LENGTH) charCountEl.style.color = '#ff6b6b'; else if (len > (MAX_MESSAGE_LENGTH*0.8)) charCountEl.style.color = '#ffd166'; else charCountEl.style.color = '#bdbdbd'; }
msgEl.addEventListener('input', updateCharCount);

/* ---------- image upload ---------- */
imageBtn.addEventListener('click', ()=> imageInput.click());
imageInput.addEventListener('change', async (e) => {
  const f = e.target.files && e.target.files[0] ? e.target.files[0] : null;
  if (!f) return;
  await uploadAndSendImage(f);
  imageInput.value = '';
});

async function uploadAndSendImage(file){
  try {
    if (!file) return;
    if (file.size > MAX_IMAGE_UPLOAD_BYTES) { alert('Image too large. Max ' + (MAX_IMAGE_UPLOAD_BYTES/1024/1024) + ' MB'); return; }
    const allowed = ['image/png','image/jpeg','image/webp','image/gif'];
    if (!allowed.includes(file.type)) { alert('Unsupported image type'); return; }
    if (!confirm('Upload and send this image?')) return;
    const fd = new FormData(); fd.append('image', file);
    const status = document.createElement('div'); status.textContent = 'Uploading image…'; status.style.position='fixed'; status.style.left='50%'; status.style.bottom='140px'; status.style.transform='translateX(-50%)'; status.style.background='rgba(0,0,0,0.7)'; status.style.padding='8px 12px'; status.style.borderRadius='8px'; status.style.zIndex=2000; document.body.appendChild(status);
    const resp = await fetch(API_UPLOAD, { method:'POST', body: fd, credentials:'same-origin' });
    const j = await resp.json();
    document.body.removeChild(status);
    if (!j || !j.ok) { alert('Upload failed: ' + (j && j.error ? j.error : 'unknown')); return; }
    const imageUrl = j.url;
    const fd2 = new FormData(); fd2.append('message', `![image](${imageUrl})`);
    await fetch(API_PRIVATE + '?room=' + encodeURIComponent(ROOM_CODE) + '&mode=send', { method:'POST', body: fd2, credentials:'same-origin' });
    await immediatePoll();
  } catch (err) { console.error('upload error', err); alert('Upload failed'); }
}

/* ---------- initial load & polling ---------- */
async function initialLoad(){
  try {
    const r = await fetch(API_PRIVATE + '?room=' + encodeURIComponent(ROOM_CODE), { credentials:'same-origin' });
    const j = await r.json();
    if (j.error) { console.error('initial load err', j); return; }
    if (j.user) currentUser = j.user;
    const messages = j.messages || [];
    chatEl.innerHTML = ''; lastId = 0; lastUsernameInDOM = null;
    appendMessages(messages);
    updateCharCount();
  } catch(e){ console.error('initialLoad', e); }
}

let pollInFlight = false;
async function immediatePoll(){
  if (pollInFlight) return;
  pollInFlight = true;
  try {
    const r = await fetch(API_PRIVATE + '?room=' + encodeURIComponent(ROOM_CODE) + '&since=' + encodeURIComponent(lastId), { credentials:'same-origin' });
    pollInFlight = false;
    const j = await r.json();
    if (j.user) currentUser = j.user;
    if (Array.isArray(j.messages) && j.messages.length) {
      appendMessages(j.messages);
      if (audioUnlocked) { try { bell.currentTime = 0; bell.play().catch(()=>{}); } catch(e){} }
    }
  } catch(e){ pollInFlight = false; console.error('immediatePoll error', e); }
}

let visible = !document.hidden;
document.addEventListener('visibilitychange', ()=> visible = !document.hidden);
async function longPollLoop(){
  const hiddenDelay = 30000;
  while (running){
    try {
      if (!visible) await new Promise(r => setTimeout(r, hiddenDelay));
      await immediatePoll();
      await new Promise(r => setTimeout(r, MSG_POLL_MS));
    } catch(e){ console.error('longPollLoop error', e); await new Promise(r => setTimeout(r, 2000)); }
  }
}

/* ---------- notifications: fetch, group, render, mark on scroll ---------- */
async function fetchNotifications(limit=100){
  try {
    const res = await fetch(API_NOTIF + '?limit=' + encodeURIComponent(limit), { credentials: 'same-origin' });
    if (!res.ok) throw new Error('failed fetch');
    return await res.json();
  } catch(e){ console.error('fetchNotifications', e); return { notifications: [], unread_count: 0 }; }
}
function groupNotifications(rows){
  const groups = new Map();
  for (const n of rows){
    const key = ((n.type || '') === 'modmail' && n.ref_id) ? ('modmail|ref|' + (n.ref_id||0)) : ((n.type||'') + '|' + (n.source_user_id||0));
    if (!groups.has(key)) groups.set(key, { key, type: n.type, source_user_id: n.source_user_id, source_username: n.source_username, source_avatar: n.source_avatar, ids: [], latest: null, count:0 });
    const g = groups.get(key);
    g.ids.push(n.id); g.count++; if (!g.latest || n.id > g.latest.id) g.latest = n;
  }
  return Array.from(groups.values()).sort((a,b) => (b.latest?.id || 0) - (a.latest?.id || 0));
}
function renderGroupElement(g){
  const el = document.createElement('div'); el.className = 'notifGroup' + (g.latest && !g.latest.is_read ? ' unread' : '');
  el.dataset.ids = g.ids.join(',');
  const av = document.createElement('div'); av.className='avatar'; av.style.width='44px'; av.style.height='44px'; av.style.borderRadius='8px'; av.style.background='#222'; av.style.display='flex'; av.style.alignItems='center'; av.style.justifyContent='center';
  if (g.source_avatar){ const img = document.createElement('img'); img.src = (g.source_avatar.indexOf('/')===0 || g.source_avatar.startsWith('http')) ? g.source_avatar : 'avatars/' + encodeURIComponent(g.source_avatar); img.style.width='100%'; img.style.height='100%'; img.style.objectFit='cover'; av.appendChild(img); } else av.textContent = (g.source_username ? g.source_username[0].toUpperCase() : '?');
  const meta = document.createElement('div'); meta.className='meta'; meta.style.flex='1';
  const title = document.createElement('div'); title.className = 'title';
  let titleText = '';
  if (g.type === 'dm'){ titleText = g.source_username ? `${g.source_username}` : 'Someone'; if (g.count > 1) titleText += ` — ${g.count} messages`; else titleText += ' — sent you a message'; }
  else if (g.type === 'friend_request'){ titleText = g.source_username ? `${g.source_username} sent you a friend request` : 'Friend request'; }
  else if (g.type === 'friend_accept' || g.type === 'friend_acceptance'){ titleText = g.source_username ? `${g.source_username} accepted your request` : 'Friend accepted'; }
  else if (g.type === 'modmail'){ titleText = (g.latest && g.latest.message) ? g.latest.message.slice(0,80) : 'Modmail'; }
  else { titleText = g.latest && g.latest.message ? String(g.latest.message).slice(0,80) : (g.type || 'Notification'); if (g.count > 1) titleText += ` (${g.count})`; }
  title.textContent = titleText;
  const msg = document.createElement('div'); msg.className='msg'; msg.style.color='var(--muted)'; msg.style.fontSize='13px'; msg.textContent = g.latest && g.latest.created_at ? new Date(g.latest.created_at).toLocaleString() : '';
  meta.appendChild(title); meta.appendChild(msg);
  const right = document.createElement('div'); right.style.display='flex'; right.style.flexDirection='column'; right.style.alignItems='flex-end';
  const time = document.createElement('div'); time.className='time'; time.style.fontSize='12px'; time.style.color='var(--muted)'; time.textContent = g.latest && g.latest.created_at ? new Date(g.latest.created_at).toLocaleTimeString() : '';
  const cnt = document.createElement('div'); cnt.className='notifCount'; cnt.style.background='#ff4d4f'; cnt.style.color='#fff'; cnt.style.padding='4px 8px'; cnt.style.borderRadius='999px'; cnt.style.fontWeight='700'; cnt.style.fontSize='12px'; cnt.style.minWidth='36px'; cnt.style.textAlign='center'; cnt.textContent = g.count > 1 ? g.count : '';
  if (g.count <= 1) cnt.style.display = 'none';
  right.appendChild(cnt); right.appendChild(time);
  el.appendChild(av); el.appendChild(meta); el.appendChild(right);
  if (g.latest && !g.latest.is_read){ const dot = document.createElement('div'); dot.className='unreadDot'; el.appendChild(dot); }
  el.addEventListener('click', async (ev) => {
    ev.stopPropagation();
    for (const id of g.ids) {
      if (!marked.has(id)) {
        try { await fetch(API_NOTIF, { method:'POST', credentials:'same-origin', body: new URLSearchParams({ action:'mark_read', id: id }) }); marked.add(id); } catch(e){}
      }
    }
    if (g.type === 'modmail' && g.latest && g.latest.ref_id) { window.location.href = 'modmail.php?id=' + encodeURIComponent(g.latest.ref_id); }
    else if (g.source_username) { window.location.href = 'message.php?user=' + encodeURIComponent(g.source_username); }
    else { notifDropdown.style.display = 'none'; }
  });
  return el;
}

async function loadNotifications(opened=false){
  try {
    const j = await fetchNotifications(200);
    const unread = j.unread_count || 0;
    if (unread > (lastUnread || 0) && lastUnread !== 0) {
      try { if (audioUnlocked) { bell2.currentTime = 0; bell2.play().catch(()=>{}); } } catch(e){}
    }
    lastUnread = unread;
    if (unread > 0) { notifBadge.style.display = 'inline-block'; notifBadge.textContent = unread > 99 ? '99+' : String(unread); } else notifBadge.style.display='none';
    const rows = Array.isArray(j.notifications) ? j.notifications : [];
    const groups = groupNotifications(rows);
    notifList.innerHTML = '';
    if (groups.length === 0) { notifList.innerHTML = '<div style="padding:12px;color:var(--muted)">No notifications</div>'; return; }
    groups.forEach(g => { const el = renderGroupElement(g); el.dataset.ids = g.ids.join(','); notifList.appendChild(el); });
    setupNotifObserver();
  } catch(e){ notifList.innerHTML = '<div style="padding:12px;color:#f66">Failed to load notifications</div>'; console.error('loadNotifications', e); }
}

function setupNotifObserver(){
  if (notifObserver) { notifObserver.disconnect(); notifObserver = null; }
  const opts = { root: notifList, rootMargin: '0px', threshold: 0.6 };
  notifObserver = new IntersectionObserver(async (entries)=>{
    for (const ent of entries){
      if (!ent.isIntersecting) continue;
      const el = ent.target;
      const idsStr = el.dataset.ids || '';
      if (!idsStr) continue;
      const ids = idsStr.split(',').map(s => parseInt(s,10)).filter(Boolean);
      const toMark = ids.filter(id => !marked.has(id));
      if (toMark.length > 0) {
        await markIdsRead(toMark);
        el.classList.remove('unread');
        const dot = el.querySelector('.unreadDot'); if (dot) dot.remove();
        lastUnread = Math.max(0, lastUnread - toMark.length);
        if (lastUnread > 0) notifBadge.textContent = lastUnread > 99 ? '99+' : String(lastUnread); else notifBadge.style.display='none';
      }
      notifObserver.unobserve(el);
    }
  }, opts);

  const items = notifList.querySelectorAll('.notifGroup');
  items.forEach(it => {
    const ids = (it.dataset.ids || '').split(',').map(s => parseInt(s,10)).filter(Boolean);
    const anyUnmarked = ids.some(id => !marked.has(id));
    if (anyUnmarked) notifObserver.observe(it);
  });
}

async function markIdsRead(ids){
  for (const id of ids){
    if (marked.has(id)) continue;
    try { await fetch(API_NOTIF, { method:'POST', credentials:'same-origin', body: new URLSearchParams({ action:'mark_read', id: id }) }); marked.add(id); } catch(e){ console.error('markIdsRead', e); }
  }
}

function toggleNotifDropdown(force){
  const current = notifDropdown.style.display === 'block';
  const next = (typeof force === 'boolean') ? force : !current;
  notifDropdown.style.display = next ? 'block' : 'none';
  if (next) loadNotifications(true);
}
notifBell.addEventListener('click', (e)=> { e.stopPropagation(); toggleNotifDropdown(); });
document.addEventListener('click', (e)=> { if (!e.target.closest || (!e.target.closest('#notifBell') && !e.target.closest('#notifDropdown'))) toggleNotifDropdown(false); });

markAllBtn.addEventListener('click', async (e)=>{
  e.stopPropagation();
  try {
    await fetch(API_NOTIF, { method:'POST', credentials:'same-origin', body: new URLSearchParams({ action:'mark_all_read' }) });
    const items = document.querySelectorAll('.notifGroup'); items.forEach(it => { it.classList.remove('unread'); const dot = it.querySelector('.unreadDot'); if (dot) dot.remove(); });
    lastUnread = 0; notifBadge.style.display='none';
    const j = await fetchNotifications(200);
    if (Array.isArray(j.notifications)) { for (const n of j.notifications) marked.add(n.id); }
  } catch(e){ console.error(e); }
});

/* ---------- start polling for notifications (light) ---------- */
async function startNotifPolling(){
  try {
    const j = await fetchNotifications(5);
    lastUnread = j.unread_count || 0;
    if (lastUnread > 0) notifBadge.style.display='inline-block', notifBadge.textContent = lastUnread > 99 ? '99+' : String(lastUnread);
    else notifBadge.style.display='none';
  } catch(e){ console.error('initial notifications', e); }
  notifPolling = setInterval(async ()=>{
    try {
      const j = await fetchNotifications(5);
      if (!j) return;
      const unreadNow = j.unread_count || 0;
      if (unreadNow > (lastUnread || 0)) { try { if (audioUnlocked) { bell2.currentTime = 0; bell2.play().catch(()=>{}); } } catch(e){} }
      else if (unreadNow > 0 && lastUnread === 0) { try { if (audioUnlocked) { bell.currentTime = 0; bell.play().catch(()=>{}); } } catch(e){} }
      lastUnread = unreadNow;
      if (lastUnread > 0) notifBadge.style.display='inline-block', notifBadge.textContent = lastUnread > 99 ? '99+' : String(lastUnread);
      else notifBadge.style.display='none';
      if (notifDropdown.style.display === 'block') await loadNotifications(true);
    } catch(e){ console.error('poll', e); }
  }, POLL_INTERVAL);
}

/* ---------- friends sidebar load (small helper) ---------- */
async function loadFriends(){
  try {
    const r = await fetch('friends_list.php', { credentials:'same-origin' }).catch(()=>null);
    if (!r) { document.getElementById('friendsList').textContent = 'Unable to load.'; return; }
    const j = await r.json();
    const container = document.getElementById('friendsList');
    if (!Array.isArray(j.friends) || j.friends.length === 0) { container.innerHTML = '<div style="color:var(--muted)">No friends</div>'; return; }
    container.innerHTML = '';
    j.friends.forEach(f => {
      const d = document.createElement('div'); d.style.display='flex'; d.style.justifyContent='space-between'; d.style.alignItems='center'; d.style.padding='6px 0';
      const left = document.createElement('div'); left.style.display='flex'; left.style.gap='8px'; left.style.alignItems='center';
      const av = document.createElement('div'); av.style.width='36px'; av.style.height='36px'; av.style.borderRadius='8px'; av.style.background='#222'; av.style.display='flex'; av.style.alignItems='center'; av.style.justifyContent='center'; av.textContent = (f.username||'?')[0].toUpperCase();
      const name = document.createElement('div'); name.textContent = f.username; left.appendChild(av); left.appendChild(name);
      const btn = document.createElement('a'); btn.className='smallBtn'; btn.href = 'message.php?user=' + encodeURIComponent(f.username); btn.textContent = 'Message';
      d.appendChild(left); d.appendChild(btn);
      container.appendChild(d);
    });
  } catch(e){ console.error('loadFriends', e); document.getElementById('friendsList').textContent = 'Failed to load'; }
}

/* ---------- initialise ---------- */
initialLoad().then(()=> { longPollLoop(); startNotifPolling(); loadFriends(); }).catch(()=>{ startNotifPolling(); loadFriends(); });

window.addEventListener('beforeunload', ()=> running = false);
</script>
</body>
</html>
