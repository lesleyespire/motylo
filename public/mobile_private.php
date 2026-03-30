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
    foreach ($candidates as $c) {
        if (in_array($c, $cols, true)) { $found = $c; break; }
    }
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
$my_id = (int)$user['id'];
$username = htmlspecialchars($user['username']);
$avatar = $user['avatar'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"/>
<title>Mobile Private — <?= htmlspecialchars($room_code) ?></title>
<link rel="icon" href="root/favicon.ico">

<script>
try {
  if (window.self !== window.top) document.documentElement.classList.add('embedded');
  else document.documentElement.classList.add('standalone');
} catch (e) {
  document.documentElement.classList.add('embedded');
}
</script>

<style>
:root{
  --bg:#0b0b0c; --panel:#0f0f10; --muted:#9aa3b8; --accent:#5865F2; --bubble:#141416; --me:#2d344a;
  font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,Arial; color:#eef2ff;
}
html,body{height:100%;margin:0;background:var(--bg);-webkit-font-smoothing:antialiased}
.app{display:flex;flex-direction:column;height:100vh;position:relative;}
.header{height:56px;display:flex;align-items:center;justify-content:space-between;padding:8px 12px;background:linear-gradient(180deg, rgba(255,255,255,0.02), transparent);z-index:20}
.header .left{display:flex;align-items:center;gap:10px;min-width:0}
.backBtn{background:transparent;border:0;color:inherit;font-size:20px;padding:8px;border-radius:8px}
.roomTitle{font-weight:700;font-size:16px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.topActions{display:flex;gap:8px;align-items:center}
.iconBtn{background:rgba(255,255,255,0.02);border:0;color:inherit;padding:8px;border-radius:10px;font-size:18px}

.main{flex:1;overflow:auto;padding:12px 10px 140px; -webkit-overflow-scrolling:touch;}
.msg{display:flex;gap:8px;margin-bottom:12px;align-items:flex-start}
.msg .avatar{width:44px;height:44px;border-radius:10px;flex:0 0 44px;background:#222;overflow:hidden;display:flex;align-items:center;justify-content:center;border:1px solid rgba(255,255,255,0.02)}
.msg .avatar img{width:100%;height:100%;object-fit:cover;display:block}
.bubble{padding:10px 12px;border-radius:12px;background:var(--bubble);max-width:78%;box-shadow:0 6px 18px rgba(0,0,0,0.45);line-height:1.35}
.metaUser{font-weight:700;margin-bottom:6px;font-size:14px;color:#e9eefc}
.metaUser a{color:inherit;text-decoration:none}
.time{font-size:11px;color:var(--muted);margin-left:6px}
.rowMe{flex-direction:row-reverse}
.rowMe .bubble{background:linear-gradient(180deg,var(--me),#23304a);color:#fff;}
.rowMe .avatar{margin-left:8px;margin-right:0}
.rowOther .avatar{margin-right:8px}
.msgImage{max-width:240px;border-radius:10px;display:block;margin-top:6px;cursor:pointer}
.replySnippet{border-left:3px solid rgba(255,255,255,0.04);padding:6px;margin-bottom:6px;color:#ccc;font-size:13px;border-radius:6px;background:rgba(255,255,255,0.02)}

/* Keep overall text normal, but allow formatting tags to work */
.content{
  font-size:15px;
  line-height:1.45;
  word-break:break-word;
}
.content big{
  font-size:1.9em;
  line-height:1.05;
}
.content small{
  font-size:.8em;
  line-height:1.15;
}
.content b, .content strong{font-weight:800}
.content i, .content em{font-style:italic}
.content u{text-decoration:underline}
.content code{
  font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;
  font-size:.92em;
  background:rgba(255,255,255,0.06);
  padding:0 .3em;
  border-radius:6px;
}
.content pre{
  white-space:pre-wrap;
  word-break:break-word;
  background:rgba(255,255,255,0.05);
  padding:10px;
  border-radius:10px;
  overflow:auto;
}
.content a{color:#8ab4ff;text-decoration:underline}
.content img{max-width:100%;height:auto}
.content .emojiBig{
  font-size:4em;
  line-height:1;
  display:inline-block;
  vertical-align:-0.12em;
}
.msg{
  display:flex;
  gap:8px;
  margin-bottom:12px;
  align-items:flex-start;
  touch-action:pan-y;
}

/* spacer to align when avatar hidden */
.avatarSpacer { width:44px; height:44px; flex:0 0 44px; }

/* typing pill */
.typingPill {
  position:fixed;
  left:12px;
  right:12px;
  bottom:76px;
  z-index:46;
  display:none;
  align-items:center;
  gap:8px;
  padding:8px 12px;
  border-radius:999px;
  background:rgba(0,0,0,0.55);
  color:#e9eefc;
  font-size:13px;
  box-shadow:0 8px 30px rgba(0,0,0,0.7);
}
.typingDots{display:inline-flex;gap:4px;align-items:center}
.typingDots span{width:6px;height:6px;background:#fff;border-radius:50%;opacity:0.3;transform:translateY(0);display:inline-block;animation:dot 1s infinite;}
.typingDots span:nth-child(1){animation-delay:0s}
.typingDots span:nth-child(2){animation-delay:0.12s}
.typingDots span:nth-child(3){animation-delay:0.24s}
@keyframes dot {0%{opacity:0.25; transform:translateY(0)}50%{opacity:1; transform:translateY(-6px)}100%{opacity:0.25; transform:translateY(0)}}

/* input area */
.inputArea{position:fixed;left:8px;right:8px;bottom:12px;background:linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));padding:8px;border-radius:12px;display:flex;gap:8px;align-items:center;z-index:40}
.inputArea input[type="text"]{flex:1;padding:10px;border-radius:10px;border:0;background:#0d0d0e;color:#fff; font-size:16px}
.sendBtn{background:var(--accent);border:0;padding:10px 12px;border-radius:10px;color:#fff;font-weight:700}
.small{font-size:18px;padding:8px;border-radius:10px;background:rgba(255,255,255,0.02);border:0;color:inherit}
.charCount{font-size:12px;color:var(--muted);margin-left:6px}

.notifWrap{position:relative}
.badge{position:absolute;top:-6px;right:-6px;background:#ff4d4f;color:#fff;padding:3px 7px;border-radius:999px;font-size:12px}
.empty{color:var(--muted);text-align:center;padding:12px}
#imageModal{position:fixed;inset:0;background:rgba(0,0,0,0.9);display:none;align-items:center;justify-content:center;z-index:60}
#imageModal img{max-width:96%;max-height:96%;border-radius:12px}
#notifDropdown{position:fixed;left:8px;right:8px;top:64px;background:#0d0d0e;padding:10px;border-radius:10px;z-index:60;max-height:60vh;overflow:auto;display:none}

/* action menu */
#actionMenu { position:fixed; display:none; z-index:70; min-width:140px; background:#0f1011; border:1px solid rgba(255,255,255,0.04); border-radius:10px; box-shadow:0 12px 40px rgba(0,0,0,0.6); padding:8px; }
#actionMenu button { display:block; width:100%; text-align:left; padding:10px 8px; border:0; background:transparent; color:inherit; font-size:15px; border-radius:8px; cursor:pointer; }
#actionMenu button:hover { background: rgba(255,255,255,0.02); }

/* hide the bar entirely only when this page is inside an iframe */
.embedded .header,
.embedded #notifDropdown {
  display:none !important;
}
.embedded .main {
  padding-top:12px;
}

@media(min-width:720px){
  .main{padding:18px; max-width:900px; margin:0 auto}
  .inputArea{left:calc(50% - 420px);right:calc(50% - 420px);max-width:840px}
  .typingPill { left: calc(50% - 420px); right: calc(50% - 420px); bottom: 86px; max-width:840px; }
}
</style>
</head>
<body>
<div class="app" id="app">
  <?php if ($background_url): ?>
    <div style="position:fixed;inset:0;background-image:url('<?= htmlspecialchars($background_url, ENT_QUOTES) ?>');background-size:cover;background-position:center;opacity:.12;z-index:0;filter:blur(2px) saturate(.9)"></div>
  <?php endif; ?>

  <div class="header">
    <div class="left">
      <button class="backBtn" onclick="history.back()">◀</button>
      <div>
        <div class="roomTitle">Private • <?= htmlspecialchars($room_code) ?></div>
        <div style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($username) ?></div>
      </div>
    </div>

    <div class="topActions">
      <div id="notifBell" class="iconBtn notifWrap" title="Notifications">🔔<span id="notifBadge" class="badge" style="display:none">0</span></div>
      <button class="iconBtn" id="refreshBtn" title="Refresh">⟳</button>
      <button class="iconBtn" onclick="location.href='private_voice.php?room=<?= urlencode($room_code) ?>'">🎤</button>
    </div>
  </div>

  <main class="main" id="chat" role="log" aria-live="polite">
    <div id="loading" class="empty">Loading messages…</div>
  </main>

  <div id="imageModal" onclick="this.style.display='none'">
    <img id="imageModalImg" src="" alt="image">
  </div>

  <div id="replyPreview" style="display:none;position:fixed;left:12px;right:12px;bottom:116px;background:#111;padding:8px;border-radius:8px;z-index:45;display:flex;align-items:center;gap:8px">
    <div style="font-weight:700">Replying to <span id="rpUser"></span></div>
    <div style="opacity:.9" id="rpText"></div>
    <button onclick="clearReply()" style="margin-left:auto;background:transparent;border:0;color:#f66">✖</button>
  </div>

  <div class="typingPill" id="typingPill" aria-hidden="true">
    <div id="typingText">Someone is typing…</div>
    <div class="typingDots" aria-hidden="true"><span></span><span></span><span></span></div>
  </div>

  <div class="inputArea" role="form" aria-label="Message input">
    <input id="msg" type="text" maxlength="750" placeholder="Say something…" autocomplete="off" />
    <input id="imageInput" type="file" accept="image/png,image/jpeg,image/webp,image/gif" style="display:none">
    <button id="imageBtn" class="small" title="Attach image">📷</button>
    <button id="sendBtn" class="sendBtn">Send</button>
  </div>

  <div id="emojiPicker" style="display:none;position:fixed;left:8px;right:8px;bottom:110px;background:#0d0d0e;padding:8px;border-radius:10px;z-index:50;display:flex;flex-wrap:wrap;gap:8px"></div>
  <div id="notifDropdown"></div>

  <div id="actionMenu" role="menu" aria-hidden="true"></div>

  <audio id="bell" preload="auto"><source src="root/bell.mp3" type="audio/mpeg"></audio>
  <audio id="bell2" preload="auto"><source src="root/bell_2.mp3" type="audio/mpeg"></audio>
</div>

<script>
/* ---------- constants ---------- */
const ROOM_CODE = <?= json_encode($room_code) ?>;
const PRIVATE_API = 'private_interface.php';
const UPLOAD_API = 'upload_image.php';
const NOTIF_API = 'notifications.php';
const MAX_IMAGE_UPLOAD_BYTES = 6 * 1024 * 1024;
const MAX_MESSAGE_LENGTH = 750;
const POLL_INTERVAL = 30000;
const MY_ID = <?= json_encode($my_id) ?>;

/* ---------- DOM refs ---------- */
const chatEl = document.getElementById('chat');
const loadingEl = document.getElementById('loading');
const msgEl = document.getElementById('msg');
const sendBtn = document.getElementById('sendBtn');
const imageBtn = document.getElementById('imageBtn');
const imageInput = document.getElementById('imageInput');
const notifBell = document.getElementById('notifBell');
const notifBadge = document.getElementById('notifBadge');
const notifDropdown = document.getElementById('notifDropdown');
const refreshBtn = document.getElementById('refreshBtn');
const rpEl = document.getElementById('replyPreview');
const rpUser = document.getElementById('rpUser');
const rpText = document.getElementById('rpText');
const bell = document.getElementById('bell');
const bell2 = document.getElementById('bell2');
const actionMenu = document.getElementById('actionMenu');
const typingPill = document.getElementById('typingPill');
const typingText = document.getElementById('typingText');

let lastUserIdInDOM = null;
let lastMessageTimestampInDOM = 0;
let lastId = 0;
let currentUser = null;
let audioUnlocked = false;
let running = true;
let replyingTo = null;
let inFlightPoll = false;
let lastReceivedAt = 0;

document.addEventListener('pointerdown', ()=> audioUnlocked = true, {once:true});

/* ---------- helpers ---------- */
function escapeHtml(s){ if (s==null) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }

function safeUrl(raw){
  try {
    const u = new URL(String(raw), window.location.href);
    if (!['http:','https:'].includes(u.protocol)) return '';
    return u.href;
  } catch (e) {
    return '';
  }
}
function escapeAttr(s){ return escapeHtml(s).replace(/`/g,'&#096;'); }

function decorateEmojis(root){
  if (!root) return;

  let emojiRe;
  try {
    emojiRe = new RegExp('[\\u{1F300}-\\u{1FAFF}\\u{1F1E6}-\\u{1F1FF}\\u{2600}-\\u{27BF}]', 'gu');
  } catch (e) {
    return;
  }

  const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
  const nodes = [];
  while (walker.nextNode()) nodes.push(walker.currentNode);

  for (const node of nodes) {
    const text = node.nodeValue || '';
    const parent = node.parentElement;
    if (parent && parent.closest('code, pre')) continue;

    emojiRe.lastIndex = 0;
    if (!emojiRe.test(text)) continue;
    emojiRe.lastIndex = 0;

    const frag = document.createDocumentFragment();
    let lastIndex = 0;
    let match;

    while ((match = emojiRe.exec(text)) !== null) {
      if (match.index > lastIndex) {
        frag.appendChild(document.createTextNode(text.slice(lastIndex, match.index)));
      }

      const span = document.createElement('span');
      span.className = 'emojiBig';
      span.textContent = match[0];
      frag.appendChild(span);

      lastIndex = match.index + match[0].length;
    }

    if (lastIndex < text.length) {
      frag.appendChild(document.createTextNode(text.slice(lastIndex)));
    }

    node.parentNode.replaceChild(frag, node);
  }
}    
    
function sanitizeAndFormatHtml(input){
  if (!input) return '';
  let text = String(input);

  // Preserve uploaded images
  text = text.replace(/!\[image\]\((\/images\/[^\s)]+)\)/g, function(_, path){
    const safe = safeUrl(path);
    if (!safe) return '';
    return `<img class="msgImage" data-fullsrc="${escapeAttr(safe)}" src="${escapeAttr(safe)}" alt="image" loading="lazy">`;
  });

  const parser = new DOMParser();
  const doc = parser.parseFromString(`<div id="root">${text}</div>`, 'text/html');
  const root = doc.getElementById('root');

  const allowedTags = new Set(['b','i','u','strong','em','big','small','br','span','a','code','pre','blockquote','img']);

  function walk(node){
    if (!node) return '';
    if (node.nodeType === Node.TEXT_NODE) {
      return escapeHtml(node.nodeValue || '');
    }
    if (node.nodeType !== Node.ELEMENT_NODE) return '';

    const tag = node.tagName.toLowerCase();
    if (!allowedTags.has(tag)) {
      return Array.from(node.childNodes).map(walk).join('');
    }

    if (tag === 'br') return '<br>';

    if (tag === 'img') {
      const srcRaw = node.getAttribute('data-fullsrc') || node.getAttribute('src') || '';
      const src = safeUrl(srcRaw);
      if (!src) return '';
      return `<img class="msgImage" data-fullsrc="${escapeAttr(src)}" src="${escapeAttr(src)}" alt="image" loading="lazy">`;
    }

    let attrs = '';
    if (tag === 'a') {
      const href = safeUrl(node.getAttribute('href') || '');
      if (!href) return Array.from(node.childNodes).map(walk).join('');
      attrs += ` href="${escapeAttr(href)}" target="_top" rel="noopener noreferrer"`;
    }

    if (tag === 'span') {
      const cls = node.getAttribute('class');
      if (cls) attrs += ` class="${escapeAttr(cls)}"`;
    }

    const inner = Array.from(node.childNodes).map(walk).join('');
    return `<${tag}${attrs}>${inner}</${tag}>`;
  }

  return Array.from(root.childNodes).map(walk).join('');
}

function openImageModal(src){
  const modal = document.getElementById('imageModal');
  const img = document.getElementById('imageModalImg');
  img.src = src;
  modal.style.display = 'flex';
}

function isNearBottom(threshold = 140){
  const scrollBottom = chatEl.scrollHeight - (chatEl.scrollTop + chatEl.clientHeight);
  return scrollBottom <= threshold;
}

/* ---------- build message DOM ---------- */
function buildMessageDom(m, showAvatar){
  const row = document.createElement('div');
  row.className = 'msg ' + ((m.user_id === MY_ID) ? 'rowMe' : 'rowOther');
  row.dataset.id = m.id;
  row.dataset.username = m.username || '';
  row.dataset.userId = m.user_id || '';
  row.dataset.excerpt = (m.message || '').slice(0,200);
  row.dataset.message = m.message || '';

  const bubble = document.createElement('div');
  bubble.className = 'bubble';

  if (m.reply_to_username || m.reply_to_excerpt) {
    const rp = document.createElement('div');
    rp.className = 'replySnippet';
    const ruser = document.createElement('div');
    ruser.textContent = m.reply_to_username || '…';
    ruser.style.fontWeight = '700';
    const rex = document.createElement('div');
    rex.textContent = m.reply_to_excerpt || (m.reply_to_message ? m.reply_to_message.slice(0,120) : '');
    rp.appendChild(ruser);
    rp.appendChild(rex);
    bubble.appendChild(rp);
  }

  if (m.deleted_at){
    bubble.textContent = 'Message removed by a site moderator';
    bubble.style.fontStyle = 'italic';
    bubble.style.opacity = '.6';
  } else {
    if (showAvatar) {
      const meta = document.createElement('div');
      meta.className = 'metaUser';
      const a = document.createElement('a');
      a.href = 'mobile_user.php?username=' + encodeURIComponent(m.username || '');
      a.textContent = m.username || '…';
      a.addEventListener('click', (ev)=> { ev.preventDefault(); location.href = a.href; });
      meta.appendChild(a);
      bubble.appendChild(meta);
    }

    const content = document.createElement('div');
    content.className = 'content';
    content.innerHTML = sanitizeAndFormatHtml(m.message || '');
    decorateEmojis(content);

    content.querySelectorAll('img.msgImage').forEach(img => {
      img.addEventListener('click', (ev) => {
        ev.stopPropagation();
        openImageModal(img.src);
      });
    });

    bubble.appendChild(content);
  }

  const time = document.createElement('div');
  time.className = 'time';
  time.textContent = (m.created_at ? (new Date(m.created_at)).toLocaleTimeString() : '');
  bubble.appendChild(time);

  if (showAvatar) {
    const avatarWrap = document.createElement('div');
    avatarWrap.className = 'avatar';
    if (m.avatar){
      const img = document.createElement('img');
      img.src = (m.avatar.indexOf('/')===0 || m.avatar.startsWith('http')) ? m.avatar : 'avatars/' + encodeURIComponent(m.avatar);
      avatarWrap.appendChild(img);
    } else {
      avatarWrap.textContent = (m.username || '?')[0].toUpperCase();
    }

    avatarWrap.style.cursor = 'pointer';
    avatarWrap.addEventListener('click', ()=> {
      if (m.username) location.href = 'mobile_user.php?username=' + encodeURIComponent(m.username);
    });

    if (m.user_id === MY_ID) { row.appendChild(bubble); row.appendChild(avatarWrap); }
    else { row.appendChild(avatarWrap); row.appendChild(bubble); }
  } else {
    const spacer = document.createElement('div');
    spacer.className = 'avatarSpacer';
    if (m.user_id === MY_ID) { row.appendChild(bubble); row.appendChild(spacer); }
    else { row.appendChild(spacer); row.appendChild(bubble); }
  }

  return row;
}

/* ---------- appendMessages ---------- */
function appendMessages(messages){
  if (!Array.isArray(messages) || messages.length === 0) return;
  const atBottomBefore = isNearBottom();
  let newReceived = false;

  for (const m of messages){
    if (!m || !m.id) continue;
    if (m.id <= lastId) continue;

    const uid = (m.user_id !== undefined && m.user_id !== null) ? m.user_id : null;
    let created_ts = Date.now();
    if (m.created_at) {
      const parsed = Date.parse(m.created_at);
      if (!isNaN(parsed)) created_ts = parsed;
    }

    const GAP = 2 * 60 * 1000;
    const timeGap = (created_ts - (lastMessageTimestampInDOM || 0));
    const showAvatar = (uid !== lastUserIdInDOM) || (timeGap > GAP) || !!m.deleted_at;

    const dom = buildMessageDom(m, showAvatar);
    chatEl.appendChild(dom);

    lastId = Math.max(lastId, m.id || 0);
    lastUserIdInDOM = uid;
    lastMessageTimestampInDOM = created_ts;
    newReceived = true;
  }

  if (loadingEl) loadingEl.style.display = 'none';
  if (atBottomBefore) chatEl.scrollTop = chatEl.scrollHeight;
  if (newReceived) lastReceivedAt = Date.now();
}

/* ---------- typing UI ---------- */
function updateTypingUI(typingList){
  if (!Array.isArray(typingList) || typingList.length === 0) {
    typingPill.style.display = 'none';
    typingPill.setAttribute('aria-hidden','true');
    return;
  }
  const others = typingList.filter(u => u && u !== (currentUser && currentUser.username));
  if (others.length === 0) {
    typingPill.style.display = 'none';
    typingPill.setAttribute('aria-hidden','true');
    return;
  }

  let txt = '';
  if (others.length === 1) txt = `${others[0]} is typing…`;
  else if (others.length === 2) txt = `${others[0]} and ${others[1]} are typing…`;
  else txt = `${others[0]} and ${others.length - 1} others are typing…`;

  typingText.textContent = txt;
  typingPill.style.display = 'flex';
  typingPill.setAttribute('aria-hidden','false');

  if (window._typingHideTimer) clearTimeout(window._typingHideTimer);
  window._typingHideTimer = setTimeout(()=> {
    typingPill.style.display = 'none';
    typingPill.setAttribute('aria-hidden','true');
  }, 4000);
}

/* ---------- load & poll ---------- */
async function initialLoad(){
  try {
    clearReply();
    lastUserIdInDOM = null;
    lastMessageTimestampInDOM = 0;

    const r = await fetch(PRIVATE_API + '?room=' + encodeURIComponent(ROOM_CODE), { credentials:'same-origin' });
    if (!r.ok) {
      chatEl.innerHTML = '<div class="empty">Failed to load messages</div>';
      return;
    }
    const j = await r.json();
    if (j.error) {
      chatEl.innerHTML = '<div class="empty">' + escapeHtml(j.error || 'Failed') + '</div>';
      return;
    }
    if (j.user) currentUser = j.user;
    const messages = j.messages || [];
    chatEl.innerHTML = '';
    lastId = 0;
    appendMessages(messages);

    if (Array.isArray(j.typing)) updateTypingUI(j.typing);
    clearReply();
  } catch (e) {
    console.error('initialLoad', e);
    chatEl.innerHTML = '<div class="empty">Load error</div>';
  }
}

async function immediatePoll(){
  if (inFlightPoll) return;
  inFlightPoll = true;
  try {
    const r = await fetch(PRIVATE_API + '?room=' + encodeURIComponent(ROOM_CODE) + '&since=' + encodeURIComponent(lastId), { credentials:'same-origin' });
    if (!r.ok) { inFlightPoll = false; return; }
    const j = await r.json();
    if (j.user) currentUser = j.user;

    if (Array.isArray(j.typing)) updateTypingUI(j.typing);

    if (Array.isArray(j.messages) && j.messages.length) {
      const fromOthers = j.messages.some(m => m.user_id !== MY_ID);
      appendMessages(j.messages);

      if (audioUnlocked && fromOthers) {
        try { bell.currentTime = 0; bell.play().catch(()=>{}); } catch(e){}
      }

      inFlightPoll = false;
      return true;
    }
  } catch (e) {
    console.error('immediatePoll error', e);
  }
  inFlightPoll = false;
  return false;
}

let visible = !document.hidden;
document.addEventListener('visibilitychange', ()=> visible = !document.hidden);

async function pollLoop(){
  const FAST = 2000, SLOW = 8000;
  let interval = FAST;
  while (running) {
    try {
      if (visible) {
        const got = await immediatePoll();
        if (got) interval = FAST;
        else {
          if (Date.now() - lastReceivedAt > 10000) interval = SLOW;
        }
      }
    } catch (e) {
      console.error('pollLoop error', e);
    }
    await new Promise(r => setTimeout(r, interval));
  }
}

/* ---------- send ---------- */
async function send(){
  const ta = msgEl;
  const text = (ta.value || '').trim();
  if (!text) return;
  if (Array.from(text).length > MAX_MESSAGE_LENGTH) { alert('Message too long'); return; }
  try {
    const body = new URLSearchParams();
    body.append('message', text);
    if (replyingTo && replyingTo.id) body.append('reply_to', replyingTo.id);
    const resp = await fetch(PRIVATE_API + '?room=' + encodeURIComponent(ROOM_CODE) + '&mode=send', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: body.toString(),
      credentials:'same-origin'
    });
    const j = await resp.json().catch(()=>null);
    if (j && j.error) console.warn('send warning', j.error);
  } catch (e) {
    console.error('send failed', e);
  }
  ta.value = '';
  clearReply();
  await immediatePoll();
}
sendBtn.addEventListener('click', send);
msgEl.addEventListener('keydown', (e)=> {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    send();
  }
});

/* ---------- typing beacon ---------- */
let lastTypingAt = 0;
function sendTypingIfNeeded(){
  const now = Date.now();
  if (now - lastTypingAt < 1000) return;
  lastTypingAt = now;
  navigator.sendBeacon
    ? navigator.sendBeacon(PRIVATE_API + '?room=' + encodeURIComponent(ROOM_CODE) + '&mode=typing')
    : fetch(PRIVATE_API + '?room=' + encodeURIComponent(ROOM_CODE) + '&mode=typing', { method:'POST', keepalive:true, credentials:'same-origin' }).catch(()=>{});
}
msgEl.addEventListener('input', sendTypingIfNeeded);

/* ---------- image upload ---------- */
imageBtn.addEventListener('click', ()=> imageInput.click());
imageInput.addEventListener('change', async (e) => {
  const f = (e.target.files && e.target.files[0]) ? e.target.files[0] : null;
  if (!f) return;
  await uploadAndSendImage(f);
  imageInput.value = '';
});
async function uploadAndSendImage(file){
  try {
    if (!file) return;
    if (file.size > MAX_IMAGE_UPLOAD_BYTES) { alert('Image too large'); return; }
    const allowed = ['image/png','image/jpeg','image/webp','image/gif'];
    if (!allowed.includes(file.type)) { alert('Unsupported'); return; }
    if (!confirm('Upload and send this image?')) return;
    const fd = new FormData(); fd.append('image', file);
    const status = document.createElement('div');
    status.textContent='Uploading…';
    status.style.position='fixed';
    status.style.left='50%';
    status.style.transform='translateX(-50%)';
    status.style.bottom='160px';
    status.style.background='rgba(0,0,0,0.7)';
    status.style.padding='8px 12px';
    status.style.borderRadius='8px';
    status.style.zIndex=1000;
    document.body.appendChild(status);
    const resp = await fetch(UPLOAD_API, { method:'POST', body: fd, credentials:'same-origin' });
    const j = await resp.json().catch(()=>null);
    document.body.removeChild(status);
    if (!j || !j.ok) { alert('Upload failed: ' + (j && j.error ? j.error : 'unknown')); return; }
    const imageUrl = j.url;
    const body = new URLSearchParams(); body.append('message', `![image](${imageUrl})`);
    await fetch(PRIVATE_API + '?room=' + encodeURIComponent(ROOM_CODE) + '&mode=send', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body.toString(), credentials:'same-origin' });
    await immediatePoll();
  } catch (err) { console.error('upload error', err); alert('Upload failed'); }
}

/* ---------- reply/edit/delete helpers ---------- */
function setReplyFromRow(row){
  const id = row.dataset.id;
  const username = row.dataset.username || '';
  const excerpt = row.dataset.excerpt || '';
  replyingTo = { id: id, username: username, excerpt: excerpt };
  rpEl.style.display = 'flex';
  rpUser.textContent = username || '…';
  rpText.textContent = excerpt;
  msgEl.focus();
}
function clearReply(){
  replyingTo = null;
  rpEl.style.display = 'none';
  rpUser.textContent = '';
  rpText.textContent = '';
}

async function startEditById(id){
  const row = chatEl.querySelector(`.msg[data-id="${id}"]`);
  const currentText = row ? (row.dataset.message || '') : '';
  const newText = prompt('Edit message:', currentText || '');
  if (newText === null) return;
  if (Array.from(newText).length > MAX_MESSAGE_LENGTH) return alert('Message too long');
  try {
    const body = new URLSearchParams({ id: id, message: newText });
    const resp = await fetch(PRIVATE_API + '?room=' + encodeURIComponent(ROOM_CODE) + '&mode=edit', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: body.toString(),
      credentials:'same-origin'
    });
    const j = await resp.json().catch(()=>null);
    if (j && j.ok) { await immediatePoll(); }
    else alert(j && j.error ? j.error : 'Edit failed');
  } catch(e){ alert('Edit failed'); console.error(e); }
}
async function doDelete(id){
  if (!confirm('Delete this message?')) return;
  try {
    const body = new URLSearchParams({ id: id });
    const resp = await fetch(PRIVATE_API + '?room=' + encodeURIComponent(ROOM_CODE) + '&mode=delete', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: body.toString(),
      credentials:'same-origin'
    });
    const j = await resp.json().catch(()=>null);
    if (j && j.ok) { await immediatePoll(); }
    else alert(j && j.error ? j.error : 'Delete failed');
  } catch (e) { alert('Delete failed'); console.error(e); }
}

/* ---------- action menu ---------- */
let longPressTimer = null;
const LONGPRESS_MS = 600;

function showActionMenuForRow(row, clientX, clientY){
  if (!row) return;
  const uid = row.dataset.userId || '';
  const username = row.dataset.username || '';
  const id = row.dataset.id || '';
  actionMenu.innerHTML = '';

  if (String(uid) === String(MY_ID)) {
    const editBtn = document.createElement('button');
    editBtn.textContent = 'Edit';
    editBtn.addEventListener('click', ()=> { hideActionMenu(); startEditById(id); });
    actionMenu.appendChild(editBtn);

    const delBtn = document.createElement('button');
    delBtn.textContent = 'Delete';
    delBtn.style.color = '#ffbbbb';
    delBtn.addEventListener('click', ()=> { hideActionMenu(); doDelete(id); });
    actionMenu.appendChild(delBtn);
  } else {
    const profileBtn = document.createElement('button');
    profileBtn.textContent = 'Open profile';
    profileBtn.addEventListener('click', ()=> {
      hideActionMenu();
      if (username) location.href = 'mobile_user.php?username=' + encodeURIComponent(username);
    });
    actionMenu.appendChild(profileBtn);
  }

  actionMenu.style.display = 'block';
  actionMenu.setAttribute('aria-hidden','false');
  const pad = 8;
  actionMenu.style.left = '0px';
  actionMenu.style.top = '-9999px';
  const mRect = actionMenu.getBoundingClientRect();
  const menuW = mRect.width || 160;
  const menuH = mRect.height || (3 * 44);
  let left = clientX;
  let top = clientY;
  if (left + menuW + pad > window.innerWidth) left = window.innerWidth - menuW - pad;
  if (top + menuH + pad > window.innerHeight) top = window.innerHeight - menuH - pad;
  if (left < pad) left = pad;
  if (top < pad) top = pad;
  actionMenu.style.left = left + 'px';
  actionMenu.style.top = top + 'px';
}

function hideActionMenu(){
  actionMenu.style.display = 'none';
  actionMenu.setAttribute('aria-hidden','true');
}
    
let swipeReplyState = null;
const SWIPE_REPLY_THRESHOLD = 48;
const SWIPE_REPLY_FEEDBACK = 28;

function resetSwipeReply(row){
  if (!row) return;
  row.style.transform = '';
  row.style.transition = '';
}

chatEl.addEventListener('touchstart', (ev) => {
  const row = ev.target.closest('.msg');
  if (!row) return;

  const t = ev.touches && ev.touches[0];
  if (!t) return;

  swipeReplyState = {
    row,
    startX: t.clientX,
    startY: t.clientY,
    activated: false
  };

  row.style.transition = 'transform .12s ease';
}, { passive:true });

chatEl.addEventListener('touchmove', (ev) => {
  if (!swipeReplyState || !swipeReplyState.row) return;

  const t = ev.touches && ev.touches[0];
  if (!t) return;

  const dx = t.clientX - swipeReplyState.startX;
  const dy = t.clientY - swipeReplyState.startY;

  if (Math.abs(dy) > Math.abs(dx) && Math.abs(dy) > 14) {
    resetSwipeReply(swipeReplyState.row);
    swipeReplyState = null;
    return;
  }

  const limited = Math.max(-SWIPE_REPLY_FEEDBACK, Math.min(SWIPE_REPLY_FEEDBACK, dx * 0.2));
  swipeReplyState.row.style.transform = `translateX(${limited}px)`;

  if (!swipeReplyState.activated && Math.abs(dx) > SWIPE_REPLY_THRESHOLD && Math.abs(dx) > Math.abs(dy)) {
    swipeReplyState.activated = true;
    setReplyFromRow(swipeReplyState.row);
    if (navigator.vibrate) navigator.vibrate(10);
  }
}, { passive:true });

chatEl.addEventListener('touchend', () => {
  if (swipeReplyState?.row) resetSwipeReply(swipeReplyState.row);
  swipeReplyState = null;
}, { passive:true });

chatEl.addEventListener('touchcancel', () => {
  if (swipeReplyState?.row) resetSwipeReply(swipeReplyState.row);
  swipeReplyState = null;
}, { passive:true });

chatEl.addEventListener('touchstart', (ev)=>{
  const row = ev.target.closest('.msg');
  if (!row) return;
  longPressTimer = setTimeout(()=> {
    const touch = ev.touches && ev.touches[0];
    const x = touch ? touch.clientX : (window.innerWidth/2);
    const y = touch ? touch.clientY : (window.innerHeight/2);
    showActionMenuForRow(row, x, y);
    longPressTimer = null;
  }, LONGPRESS_MS);
}, {passive:true});

chatEl.addEventListener('touchend', ()=> {
  if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }
});
chatEl.addEventListener('touchmove', ()=> {
  if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }
});

chatEl.addEventListener('mousedown', (ev)=> {
  if (ev.button !== 0) return;
  const row = ev.target.closest('.msg');
  if (!row) return;
  longPressTimer = setTimeout(()=> {
    showActionMenuForRow(row, ev.clientX, ev.clientY);
    longPressTimer = null;
  }, LONGPRESS_MS);
});
document.addEventListener('mouseup', ()=> {
  if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }
});

chatEl.addEventListener('contextmenu', (ev)=> {
  const row = ev.target.closest('.msg');
  if (!row) return;
  ev.preventDefault();
  showActionMenuForRow(row, ev.clientX, ev.clientY);
});

document.addEventListener('click', (ev)=> {
  if (!ev.target.closest || (!ev.target.closest('#actionMenu') && !ev.target.closest('.msg'))) hideActionMenu();
});
window.addEventListener('scroll', hideActionMenu, {passive:true});

/* ---------- notifications ---------- */
let lastUnread = 0;
async function fetchNotifications(limit=50){
  try {
    const res = await fetch(NOTIF_API + '?limit=' + encodeURIComponent(limit), { credentials:'same-origin' });
    if (!res.ok) throw new Error('fetch failed');
    return await res.json();
  } catch (e) {
    console.error('fetchNotifications', e);
    return { notifications: [], unread_count: 0 };
  }
}
function renderNotifRow(n){
  const row = document.createElement('div');
  row.style.padding = '8px';
  row.style.borderBottom = '1px solid rgba(255,255,255,0.02)';
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
  if (unread > (lastUnread || 0) && lastUnread !== 0) {
    if (audioUnlocked) try { bell2.currentTime = 0; bell2.play().catch(()=>{}); } catch(e){}
  }
  lastUnread = unread;
  if (unread > 0) {
    notifBadge.style.display = 'inline-block';
    notifBadge.textContent = unread > 99 ? '99+' : String(unread);
  } else notifBadge.style.display = 'none';

  notifDropdown.innerHTML = '';
  const rows = Array.isArray(j.notifications) ? j.notifications : [];
  if (rows.length === 0) {
    notifDropdown.innerHTML = '<div class="empty">No notifications</div>';
    return;
  }
  rows.forEach(n => notifDropdown.appendChild(renderNotifRow(n)));
}
notifBell.addEventListener('click', (e)=> {
  e.stopPropagation();
  notifDropdown.style.display = notifDropdown.style.display === 'block' ? 'none' : 'block';
  if (notifDropdown.style.display === 'block') loadNotifs();
});
document.addEventListener('click', (e)=> {
  if (!e.target.closest || (!e.target.closest('#notifBell') && !e.target.closest('#notifDropdown'))) notifDropdown.style.display = 'none';
});

/* ---------- small helper & start ---------- */
refreshBtn.addEventListener('click', ()=> { chatEl.innerHTML = ''; loadingEl.style.display = 'block'; initialLoad(); });
window.addEventListener('beforeunload', ()=> running = false);

/* ---------- kick off ---------- */
initialLoad().then(()=> {
  setInterval(()=> { if (!document.hidden) loadNotifs(); }, POLL_INTERVAL);
  pollLoop();
}).catch((e)=> {
  console.error('startup error', e);
  loadNotifs();
});
</script>
</body>
</html>
