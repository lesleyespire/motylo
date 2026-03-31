<?php
require "config.php";

if (empty($_COOKIE['auth_token'])) { header("Location:index.php"); exit; }
$stmt = $pdo->prepare("SELECT id, username, avatar FROM users WHERE auth_token = ?");
$stmt->execute([$_COOKIE['auth_token']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { header("Location:index.php"); exit; }

$myId = (int)$user['id'];
$myName = htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8');
$myAvatar = $user['avatar'] ?? null;
$targetName = trim((string)($_GET['user'] ?? ''));
$room = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($_GET['room'] ?? ''));
$embed = !empty($_GET['embed']);

$target = null;
if ($targetName !== '') {
    $s = $pdo->prepare("SELECT id, username, avatar FROM users WHERE username = ? LIMIT 1");
    $s->execute([$targetName]);
    $target = $s->fetch(PDO::FETCH_ASSOC);
}

if (!$room) {
    if ($target && (int)$target['id'] !== $myId) {
        $a = min($myId, (int)$target['id']);
        $b = max($myId, (int)$target['id']);
        $room = 'dmv_' . $a . '_' . $b;
    } else {
        $room = 'dmv_' . $myId . '_0';
    }
}

$titleName = $target['username'] ?? ($targetName ?: 'Voice');
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Voice — <?= e($titleName) ?></title>
<link rel="icon" href="root/favicon.ico">
<style>
:root{--bg:#0b0d12;--panel:#10131a;--accent:#5865F2;--muted:#aab4c5;--text:#eef4ff}
*{box-sizing:border-box}
html,body{height:100%;margin:0;background:var(--bg);color:var(--text);font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,Arial;-webkit-font-smoothing:antialiased;overflow:hidden}
body{display:flex;flex-direction:column}
.top{display:flex;align-items:center;gap:12px;padding:12px 12px calc(12px + env(safe-area-inset-top));background:linear-gradient(180deg, rgba(16,19,26,.98), rgba(16,19,26,.92));border-bottom:1px solid rgba(255,255,255,.04)}
.avatar{width:58px;height:58px;border-radius:18px;overflow:hidden;display:flex;align-items:center;justify-content:center;background:#1c2230;font-weight:850;font-size:24px;flex:0 0 58px;border:1px solid rgba(255,255,255,.04)}
.avatar img{width:100%;height:100%;object-fit:cover;display:block}
.head{min-width:0;flex:1}
.head strong{display:block;font-size:16px;line-height:1.1}
.head .sub{color:var(--muted);font-size:12px;margin-top:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.controls{display:flex;gap:8px;align-items:center;flex:0 0 auto}
.btn{background:linear-gradient(135deg,var(--accent),#7b89ff);border:0;color:#fff;padding:10px 12px;border-radius:14px;font-weight:800;cursor:pointer}
.btn.secondary{background:rgba(255,255,255,.04)}
.grid{flex:1;min-height:0;overflow:auto;padding:16px;display:flex;flex-wrap:wrap;justify-content:center;gap:14px}
.tile{width:min(170px, 44vw);text-align:center}
.tile .ring{width:100%;aspect-ratio:1/1;border-radius:50%;overflow:hidden;background:#1c2230;display:flex;align-items:center;justify-content:center;position:relative;border:1px solid rgba(255,255,255,.04);box-shadow:0 12px 28px rgba(0,0,0,.18)}
.tile .ring img{width:100%;height:100%;object-fit:cover}
.tile .ring .mic{position:absolute;left:10px;top:10px;width:14px;height:14px;border-radius:999px;background:transparent;border:2px solid rgba(255,255,255,.12)}
.tile.speaking .ring{box-shadow:0 0 0 5px rgba(76,217,100,.14), 0 12px 28px rgba(0,0,0,.18)}
.tile.speaking .mic{background:#4cd964;border-color:#4cd964}
.tile .name{margin-top:10px;font-weight:850;color:#dce6ff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tile .state{margin-top:4px;color:var(--muted);font-size:12px}
.bottom{position:sticky;bottom:0;background:linear-gradient(180deg, rgba(11,13,18,0), rgba(11,13,18,.92) 18%, rgba(11,13,18,.98));padding:12px 12px calc(12px + env(safe-area-inset-bottom));border-top:1px solid rgba(255,255,255,.04);display:flex;gap:10px;align-items:center}
.meterWrap{flex:1}
.meterLabel{font-size:12px;color:var(--muted);margin-bottom:6px}
.meter{height:10px;background:#1c2230;border-radius:999px;overflow:hidden}
.fill{height:100%;width:0;background:linear-gradient(90deg,#4cd964,#ffd94d);transition:width .05s linear}
.small{font-size:12px;color:var(--muted)}
.debug{position:fixed;right:12px;top:12px;background:rgba(0,0,0,.55);padding:8px;border-radius:10px;font-size:12px;white-space:pre-wrap;max-width:320px;display:none;z-index:50}
@media(min-width:900px){
  .top{padding:14px 14px 14px}
  .grid{padding:18px;gap:18px}
  .tile{width:190px}
}
</style>
<script src="https://js.pusher.com/8.2/pusher.min.js"></script>
</head>
<body>
<div class="top">
  <div class="avatar"><?php if ($myAvatar): ?><img src="avatars/<?= e($myAvatar) ?>" alt=""><?php else: ?><?= strtoupper(substr($myName,0,1)) ?><?php endif; ?></div>
  <div class="head">
    <strong>Voice with <?= e($titleName) ?></strong>
    <div class="sub">Room: <code><?= e($room) ?></code> · You: <?= e($myName) ?></div>
  </div>
  <div class="controls">
    <button id="muteBtn" class="btn secondary">Mute</button>
    <button id="unmuteBtn" class="btn secondary" style="display:none">Unmute</button>
    <button id="leaveBtn" class="btn"><?= $embed ? 'Close' : 'Leave' ?></button>
  </div>
</div>

<div class="grid" id="peers"></div>

<div class="bottom">
  <div class="meterWrap">
    <div class="meterLabel">Local mic level</div>
    <div class="meter"><div class="fill" id="localLevel"></div></div>
  </div>
</div>

<div class="debug" id="debug"></div>

<script>
const PUSHER_KEY = <?= json_encode($pusher_app_key ?? '') ?>;
const PUSHER_CLUSTER = <?= json_encode($pusher_app_cluster ?? '') ?>;
const ROOM = <?= json_encode($room) ?>;
const MY_ID = <?= json_encode((string)$myId) ?>;
const MY_NAME = <?= json_encode($myName) ?>;
const MY_AVATAR = <?= json_encode($myAvatar) ?>;
const EMBED = <?= $embed ? 'true' : 'false' ?>;

if (!PUSHER_KEY || !PUSHER_CLUSTER) { alert('Pusher config missing'); throw new Error('missing pusher config'); }

const pusher = new Pusher(PUSHER_KEY, { cluster: PUSHER_CLUSTER, authEndpoint:'/pusher_auth.php', forceTLS:true });
const channel = pusher.subscribe('presence-voice-' + ROOM);
const peersEl = document.getElementById('peers');
const debugEl = document.getElementById('debug');
const muteBtn = document.getElementById('muteBtn');
const unmuteBtn = document.getElementById('unmuteBtn');
const leaveBtn = document.getElementById('leaveBtn');
const localLevelFill = document.getElementById('localLevel');

const pcs = new Map();
const remoteAudio = new Map();
const tiles = new Map();
const tracksAdded = new Map();
let localStream = null;
let audioCtx = null;
let analyser = null;
let startedLocalPromise = null;
let audioUnlocked = false;

function dbg(msg){
  console.debug(msg);
  if (location.search.includes('debug')) {
    debugEl.style.display = 'block';
    debugEl.textContent = (new Date()).toLocaleTimeString() + ' ' + msg + '\n' + debugEl.textContent;
  }
}
function postStatus(text){
  try { window.parent && window.parent.postMessage({ type:'voice-status', text }, '*'); } catch(e){}
}
function createTile(id, info){
  if (tiles.has(id)) return;
  const tile = document.createElement('div'); tile.className = 'tile'; tile.dataset.id = id;
  const ring = document.createElement('div'); ring.className = 'ring';
  if (info && info.avatar) {
    const img = document.createElement('img');
    img.src = (String(info.avatar).indexOf('/') === 0 || String(info.avatar).startsWith('http')) ? info.avatar : 'avatars/' + encodeURIComponent(info.avatar);
    ring.appendChild(img);
  } else {
    ring.textContent = (info && info.username) ? info.username.charAt(0).toUpperCase() : '?';
    ring.style.fontSize = '64px';
  }
  const mic = document.createElement('div'); mic.className = 'mic'; ring.appendChild(mic);
  const name = document.createElement('div'); name.className = 'name'; name.textContent = (info && info.username) ? info.username : 'Unknown';
  const state = document.createElement('div'); state.className = 'state'; state.textContent = (id === MY_ID) ? 'You' : 'Connected';
  tile.appendChild(ring); tile.appendChild(name); tile.appendChild(state);
  peersEl.appendChild(tile); tiles.set(id, tile);
}
function removeTile(id){ const t = tiles.get(id); if (t) { t.remove(); tiles.delete(id); } }
function setSpeaking(id, yes){ const t = tiles.get(id); if (!t) return; t.classList.toggle('speaking', !!yes); }

async function ensureLocalStream(){
  if (startedLocalPromise) return startedLocalPromise;
  startedLocalPromise = (async ()=>{
    localStream = await navigator.mediaDevices.getUserMedia({ audio:true });
    try {
      audioCtx = new (window.AudioContext || window.webkitAudioContext)();
      const src = audioCtx.createMediaStreamSource(localStream);
      analyser = audioCtx.createAnalyser();
      analyser.fftSize = 256;
      src.connect(analyser);
      const data = new Uint8Array(analyser.frequencyBinCount);
      (function tick(){
        analyser.getByteFrequencyData(data);
        let sum = 0; for (let i=0;i<data.length;i++) sum += data[i];
        const avg = sum / data.length;
        const pct = Math.min(1, avg / 60);
        localLevelFill.style.width = Math.round(pct * 100) + '%';
        setSpeaking(MY_ID, pct > 0.12);
        requestAnimationFrame(tick);
      })();
    } catch(e){ console.warn('analyser failed', e); }
    for (const [peerId, pc] of pcs.entries()) {
      if (!tracksAdded.get(peerId)) {
        for (const t of localStream.getTracks()) pc.addTrack(t, localStream);
        tracksAdded.set(peerId, true);
      }
    }
    audioUnlocked = true;
    postStatus('Connected');
    dbg('local stream obtained');
    return localStream;
  })();
  return startedLocalPromise;
}

function getOrCreatePC(peerId){
  if (pcs.has(peerId)) return pcs.get(peerId);
  const pc = new RTCPeerConnection({ iceServers:[{ urls:'stun:stun.l.google.com:19302' }] });
  pc.ontrack = ev => {
    let audio = remoteAudio.get(peerId);
    if (!audio) {
      audio = document.createElement('audio');
      audio.autoplay = true;
      audio.controls = false;
      audio.style.display = 'none';
      document.body.appendChild(audio);
      remoteAudio.set(peerId, audio);
      try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const src = ctx.createMediaElementSource(audio);
        const an = ctx.createAnalyser();
        an.fftSize = 256;
        src.connect(an);
        an.connect(ctx.destination);
        const data = new Uint8Array(an.frequencyBinCount);
        (function tickRemote(){
          an.getByteFrequencyData(data);
          let sum = 0; for (let i=0;i<data.length;i++) sum += data[i];
          const avg = sum / data.length;
          const pct = Math.min(1, avg / 40);
          setSpeaking(peerId, pct > 0.08);
          requestAnimationFrame(tickRemote);
        })();
      } catch(e){}
    }
    audio.srcObject = ev.streams[0];
    createTile(peerId, ev.transceiver && ev.transceiver.sender && ev.transceiver.sender.track ? { username: peerId } : {});
  };
  pc.onicecandidate = e => {
    if (e.candidate) channel.trigger('client-signal', { from: MY_ID, to: peerId, type:'ice', candidate:e.candidate });
  };
  pc.onconnectionstatechange = ()=> {
    dbg('pc ' + peerId + ' state ' + pc.connectionState);
    if (['failed','closed','disconnected'].includes(pc.connectionState)) {
      try { pc.close(); } catch(e){}
      pcs.delete(peerId);
      tracksAdded.delete(peerId);
      const ra = remoteAudio.get(peerId);
      if (ra) { ra.remove(); remoteAudio.delete(peerId); }
      removeTile(peerId);
      postStatus('Connecting…');
    }
  };
  pcs.set(peerId, pc);
  tracksAdded.set(peerId, false);
  return pc;
}

async function createOfferTo(peerId){
  try {
    await ensureLocalStream();
    const pc = getOrCreatePC(peerId);
    if (!tracksAdded.get(peerId)) {
      for (const t of localStream.getTracks()) pc.addTrack(t, localStream);
      tracksAdded.set(peerId, true);
    }
    const offer = await pc.createOffer();
    await pc.setLocalDescription(offer);
    channel.trigger('client-signal', { from: MY_ID, to: peerId, type:'description', description: pc.localDescription });
    dbg('offer to ' + peerId);
  } catch(e) { dbg('createOfferTo error: ' + (e && e.message)); }
}

async function handleClientSignal(payload){
  try {
    if (!payload || String(payload.to) !== String(MY_ID)) return;
    const from = String(payload.from);
    const pc = getOrCreatePC(from);
    if (payload.type === 'description') {
      const desc = payload.description;
      if (!desc) return;
      if (desc.type === 'offer') {
        await ensureLocalStream();
        if (!tracksAdded.get(from)) {
          for (const t of localStream.getTracks()) pc.addTrack(t, localStream);
          tracksAdded.set(from, true);
        }
        await pc.setRemoteDescription(new RTCSessionDescription(desc));
        const answer = await pc.createAnswer();
        await pc.setLocalDescription(answer);
        channel.trigger('client-signal', { from: MY_ID, to: from, type:'description', description: pc.localDescription });
        dbg('answered offer from ' + from);
      } else if (desc.type === 'answer') {
        await pc.setRemoteDescription(new RTCSessionDescription(desc));
        dbg('received answer from ' + from);
      }
    } else if (payload.type === 'ice' && payload.candidate) {
      try { await pc.addIceCandidate(new RTCIceCandidate(payload.candidate)); } catch(e) { console.warn('addIce failed', e); }
    }
  } catch(e) { dbg('handleClientSignal error: ' + (e && e.message)); }
}

channel.bind('pusher:subscription_succeeded', members => {
  dbg('subscription_succeeded');
  members.each(member => { createTile(String(member.id), member.info || {}); });
  const count = members.count || (members.members ? Object.keys(members.members).length : 0);
  postStatus(count > 1 ? 'Connected' : 'Waiting for your friend…');
  members.each(member => {
    const id = String(member.id);
    if (id === MY_ID) return;
    if (Number(MY_ID) < Number(id)) createOfferTo(id).catch(e => dbg('offer error: ' + e));
  });
});
channel.bind('pusher:member_added', member => {
  createTile(String(member.id), member.info || {});
  const id = String(member.id);
  if (Number(MY_ID) < Number(id)) createOfferTo(id).catch(e => dbg('offer error: ' + e));
  postStatus('Connected');
});
channel.bind('pusher:member_removed', member => {
  const id = String(member.id);
  if (pcs.has(id)) { try { pcs.get(id).close(); } catch(e){} pcs.delete(id); }
  if (remoteAudio.has(id)) { remoteAudio.get(id).remove(); remoteAudio.delete(id); }
  removeTile(id);
  postStatus('Waiting for your friend…');
});
channel.bind('client-signal', data => handleClientSignal(data));

leaveBtn.addEventListener('click', ()=> {
  for (const [,pc] of pcs.entries()) { try { pc.close(); } catch(e){} }
  for (const a of remoteAudio.values()) { try { a.remove(); } catch(e){} }
  pcs.clear(); remoteAudio.clear();
  try { pusher.unsubscribe('presence-voice-' + ROOM); } catch(e){}
  if (EMBED) {
    try { window.parent.postMessage({ type:'close-message-voice' }, '*'); } catch(e){}
  } else {
    history.back();
  }
});

muteBtn.addEventListener('click', ()=> { if (!localStream) return; localStream.getAudioTracks().forEach(t => t.enabled = false); muteBtn.style.display = 'none'; unmuteBtn.style.display = ''; });
unmuteBtn.addEventListener('click', ()=> { if (!localStream) return; localStream.getAudioTracks().forEach(t => t.enabled = true); muteBtn.style.display = ''; unmuteBtn.style.display = 'none'; });

voiceStatus.textContent = 'Waiting for your friend…';
postStatus('Waiting for your friend…');
dbg('voice loaded. My ID: ' + MY_ID + ' room=' + ROOM);

document.addEventListener('pointerdown', ()=> { ensureLocalStream().catch(e => dbg('permission error: ' + (e && e.message))); }, { once:true });
pusher.connection.bind('state_change', s=>dbg('pusher state: ' + JSON.stringify(s)));
pusher.connection.bind('error', e=>dbg('pusher error: ' + JSON.stringify(e)));
</script>
</body>
</html>
