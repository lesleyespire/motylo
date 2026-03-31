<?php
// private_voice.php
require "config.php";

if (empty($_COOKIE["auth_token"])) {
    header("Location:index.php");
    exit;
}

$stmt = $pdo->prepare("SELECT id, username, avatar FROM users WHERE auth_token = ?");
$stmt->execute([$_COOKIE["auth_token"]]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    header("Location:index.php");
    exit;
}

$myId = (int)$user['id'];
$myNameRaw = (string)$user['username'];
$myName = htmlspecialchars($myNameRaw, ENT_QUOTES, 'UTF-8');
$myAvatar = $user['avatar'] ?? null;

// accept code, fallback to room for backwards compatibility, but never default to "main"
$roomCode = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($_GET['code'] ?? ($_GET['room'] ?? '')));
if ($roomCode === '') {
    die("No voice code provided.");
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

function touch_voice_presence(PDO $pdo, string $roomCode, int $userId, string $username, bool $leave = false): void {
    ensure_voice_presence_table($pdo);
    cleanup_voice_presence($pdo, 45);

    if ($leave) {
        try {
            $q = $pdo->prepare("DELETE FROM voice_room_presence WHERE room_code = ? AND user_id = ?");
            $q->execute([$roomCode, $userId]);
        } catch (Exception $e) {
            // ignore
        }
        return;
    }

    try {
        $q = $pdo->prepare("INSERT INTO voice_room_presence (room_code, user_id, username, last_seen)
                            VALUES (?, ?, ?, NOW())
                            ON DUPLICATE KEY UPDATE username = VALUES(username), last_seen = NOW()");
        $q->execute([$roomCode, $userId, $username]);
    } catch (Exception $e) {
        // ignore
    }
}

function room_presence_count(PDO $pdo, string $roomCode): int {
    ensure_voice_presence_table($pdo);
    cleanup_voice_presence($pdo, 45);
    try {
        $q = $pdo->prepare("SELECT COUNT(*) FROM voice_room_presence WHERE room_code = ? AND last_seen >= (NOW() - INTERVAL 45 SECOND)");
        $q->execute([$roomCode]);
        return (int)$q->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

// validate room exists and is a voice room
$stmt = $pdo->prepare("SELECT id, code, name, is_voice FROM private_rooms WHERE code = ? LIMIT 1");
$stmt->execute([$roomCode]);
$room = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$room || empty($room['is_voice'])) {
    die("Invalid voice code.");
}

$roomName = (string)($room['name'] ?? 'Voice');
$roomPresenceCount = room_presence_count($pdo, $roomCode);

// small AJAX helpers for presence heartbeats from the client
if (($_GET['mode'] ?? '') === 'heartbeat') {
    header('Content-Type: application/json; charset=utf-8');
    touch_voice_presence($pdo, $roomCode, $myId, $myNameRaw, false);
    echo json_encode([
        'ok' => true,
        'count' => room_presence_count($pdo, $roomCode)
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
if (($_GET['mode'] ?? '') === 'leave') {
    header('Content-Type: application/json; charset=utf-8');
    touch_voice_presence($pdo, $roomCode, $myId, $myNameRaw, true);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Voice Chat — <?= htmlspecialchars($roomCode, ENT_QUOTES, 'UTF-8') ?></title>
<link rel="icon" href="root/favicon.ico">
<style>
:root{--bg:#0e0e0e;--panel:#181818;--accent:#5865F2}
body{margin:0;background:var(--bg);color:#fff;font-family:Inter,Arial,sans-serif}
#top{display:flex;align-items:center;padding:12px;background:var(--panel);border-bottom:1px solid #222}
.headerAvatar{width:80px;height:80px;border-radius:50%;overflow:hidden;display:flex;align-items:center;justify-content:center;background:var(--accent);font-weight:700;font-size:34px;flex:0 0 80px}
.headerAvatar img{width:100%;height:100%;object-fit:cover}
.headerInfo{margin-left:12px;min-width:0}
.headerInfo strong{display:block;font-size:18px}
.controls{margin-left:auto;display:flex;gap:8px;align-items:center}
.btn{background:var(--accent);color:#fff;border:0;padding:8px 12px;border-radius:8px;cursor:pointer}
.grid{display:flex;flex-wrap:wrap;gap:20px;padding:20px;padding-bottom:120px}
.tile{width:180px;text-align:center}
.tile .avatar{width:160px;height:160px;border-radius:50%;background:#222;margin:0 auto;display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden}
.tile .avatar img{width:100%;height:100%;object-fit:cover}
.tile .username{margin-top:10px;font-weight:700;color:#9bbcff}
.tile.speaking .avatar{box-shadow:0 0 0 6px rgba(0,200,0,0.12)}
.tile .mic-ind{position:absolute;left:8px;top:8px;width:14px;height:14px;border-radius:50%;background:transparent;border:2px solid rgba(255,255,255,0.08)}
#bottomBar{position:fixed;left:0;right:0;bottom:0;background:#111;padding:12px;display:flex;gap:12px;align-items:center;border-top:1px solid #222}
#muteBtn,#unmuteBtn{padding:10px 14px;border-radius:8px;border:0;color:#fff;cursor:pointer}
#muteBtn{background:#c0392b} #unmuteBtn{background:#2ecc71}
.volumeMeter{height:8px;width:180px;background:#222;border-radius:4px;overflow:hidden}
.volumeFill{height:100%;width:0;background:linear-gradient(90deg,#0f0,#ff0);transition:width .05s}
.small{font-size:13px;color:#cfcfcf}
.debug{position:fixed;right:12px;top:12px;background:rgba(0,0,0,0.5);padding:8px;border-radius:8px;font-size:12px;max-width:320px;white-space:pre-wrap}
.connectedBadge{
  display:inline-flex;
  align-items:center;
  gap:8px;
  margin-top:6px;
  padding:5px 10px;
  border-radius:999px;
  background:rgba(76,217,100,0.12);
  color:#d8ffe0;
  font-size:12px;
  font-weight:700;
}
.connectedBadge .dot{
  width:8px;height:8px;border-radius:999px;background:#4cd964;box-shadow:0 0 0 4px rgba(76,217,100,0.12);
}
</style>

<script src="https://js.pusher.com/8.2/pusher.min.js"></script>
</head>
<body>

<div id="top">
    <div class="headerAvatar">
        <?php if ($myAvatar): ?>
            <img src="avatars/<?= htmlspecialchars($myAvatar, ENT_QUOTES, 'UTF-8') ?>" alt="">
        <?php else: ?>
            <?= strtoupper(substr($myNameRaw, 0, 1)) ?>
        <?php endif; ?>
    </div>
    <div class="headerInfo">
        <strong>Voice Room — <?= htmlspecialchars($roomCode, ENT_QUOTES, 'UTF-8') ?></strong>
        <span class="small">You: <b><?= $myName ?></b> — code: <code><?= htmlspecialchars($roomCode, ENT_QUOTES, 'UTF-8') ?></code></span>
        <div id="connectedBadge" class="connectedBadge" style="display:inline-flex">
            <span class="dot"></span>
            <span id="connectedText"><?= (int)$roomPresenceCount ?> connected</span>
        </div>
    </div>
</div>

<div class="grid" id="peers"></div>

<div id="bottomBar">
    <button id="muteBtn">Mute</button>
    <button id="unmuteBtn" style="display:none">Unmute</button>
    <div style="display:flex;flex-direction:column">
        <div class="small">Local mic level</div>
        <div class="volumeMeter"><div id="localLevel" class="volumeFill"></div></div>
    </div>
</div>

<div class="debug" id="debug" style="display:none"></div>

<script>
const PUSHER_KEY = <?= json_encode($pusher_app_key ?? '') ?>;
const PUSHER_CLUSTER = <?= json_encode($pusher_app_cluster ?? '') ?>;
const CHANNEL = "presence-voice-<?= addslashes($roomCode) ?>";
const MY_ID = <?= json_encode((string)$myId) ?>;
const MY_NAME = <?= json_encode($myNameRaw) ?>;
const MY_AVATAR = <?= json_encode($myAvatar) ?>;
const ROOM_CODE = <?= json_encode($roomCode) ?>;

if(!PUSHER_KEY || !PUSHER_CLUSTER){
    alert("Pusher config missing in config.php");
    throw new Error("missing pusher config");
}

const pusher = new Pusher(PUSHER_KEY, {
    cluster: PUSHER_CLUSTER,
    authEndpoint: '/pusher_auth.php',
    forceTLS: true
});
const channel = pusher.subscribe(CHANNEL);

const peersEl = document.getElementById('peers');
const debugEl = document.getElementById('debug');
const connectedText = document.getElementById('connectedText');

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
let heartbeatTimer = null;
let localPresenceSent = false;

function dbg(msg){
    console.debug(msg);
    if(location.search.includes('debug')) {
        debugEl.style.display = 'block';
        debugEl.textContent = (new Date()).toLocaleTimeString() + ' ' + msg + '\n' + debugEl.textContent;
    }
}

function updateConnectedCount(n){
    if (connectedText) connectedText.textContent = `${n} connected`;
}

function sendPresenceHeartbeat(leave=false){
    try {
        const url = 'private_voice.php?code=' + encodeURIComponent(ROOM_CODE) + '&mode=' + (leave ? 'leave' : 'heartbeat');
        if (navigator.sendBeacon) {
            navigator.sendBeacon(url, '');
        } else {
            fetch(url, { method:'GET', credentials:'same-origin', keepalive:true }).catch(()=>{});
        }
    } catch (e) {}
}

function createTile(id, info){
    if(tiles.has(id)) return;
    const tile = document.createElement('div');
    tile.className = 'tile';
    tile.dataset.id = id;

    const avwrap = document.createElement('div');
    avwrap.className = 'avatar';
    if(info && info.avatar){
        const img = document.createElement('img');
        img.src = 'avatars/' + info.avatar;
        avwrap.appendChild(img);
    } else {
        avwrap.textContent = (info && info.username) ? info.username.charAt(0).toUpperCase() : '?';
        avwrap.style.fontSize = '64px';
    }
    const micInd = document.createElement('div');
    micInd.className = 'mic-ind';
    avwrap.appendChild(micInd);

    const nameEl = document.createElement('div');
    nameEl.className='username';
    nameEl.textContent=(info && info.username)?info.username:'Unknown';

    tile.appendChild(avwrap);
    tile.appendChild(nameEl);
    peersEl.appendChild(tile);
    tiles.set(id, tile);
}

function removeTile(id){
    const t = tiles.get(id);
    if(t){ t.remove(); tiles.delete(id); }
}

function setSpeaking(id, yes){
    const t = tiles.get(id);
    if(!t) return;
    if(yes) t.classList.add('speaking'); else t.classList.remove('speaking');
}

async function ensureLocalStream(){
    if(startedLocalPromise) return startedLocalPromise;
    startedLocalPromise = (async ()=>{
        try {
            localStream = await navigator.mediaDevices.getUserMedia({ audio: true });
            try {
                audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                const src = audioCtx.createMediaStreamSource(localStream);
                analyser = audioCtx.createAnalyser();
                analyser.fftSize = 256;
                src.connect(analyser);
                const data = new Uint8Array(analyser.frequencyBinCount);
                function tick(){
                    analyser.getByteFrequencyData(data);
                    let sum=0; for(let i=0;i<data.length;i++) sum+=data[i];
                    let avg = sum / data.length;
                    let pct = Math.min(1, avg / 60);
                    localLevelFill.style.width = Math.round(pct * 100) + '%';
                    setSpeaking(MY_ID, pct > 0.12);
                    requestAnimationFrame(tick);
                }
                tick();
            } catch(e){ console.warn('analyser failed', e); }
            for(const [peerId, pc] of pcs.entries()){
                if(!tracksAdded.get(peerId)){
                    for(const t of localStream.getTracks()) pc.addTrack(t, localStream);
                    tracksAdded.set(peerId, true);
                }
            }
            audioUnlocked = true;
            dbg('local stream obtained');
            if (!localPresenceSent) {
                sendPresenceHeartbeat(false);
                localPresenceSent = true;
                if (heartbeatTimer) clearInterval(heartbeatTimer);
                heartbeatTimer = setInterval(()=> sendPresenceHeartbeat(false), 12000);
            }
            return localStream;
        } catch (e){
            dbg('getUserMedia failed: ' + (e && e.message));
            throw e;
        }
    })();
    return startedLocalPromise;
}

function getOrCreatePC(peerId){
    if(pcs.has(peerId)) return pcs.get(peerId);
    const pc = new RTCPeerConnection({ iceServers:[{urls:'stun:stun.l.google.com:19302'}] });

    pc.ontrack = ev => {
        let audio = remoteAudio.get(peerId);
        if(!audio){
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
                function tickRemote(){
                    an.getByteFrequencyData(data);
                    let sum=0; for(let i=0;i<data.length;i++) sum+=data[i];
                    let avg = sum / data.length;
                    let pct = Math.min(1, avg / 40);
                    setSpeaking(peerId, pct > 0.08);
                    requestAnimationFrame(tickRemote);
                }
                tickRemote();
            } catch(e){ /* ignore */ }
        }
        audio.srcObject = ev.streams[0];
    };

    pc.onicecandidate = e => {
        if(e.candidate){
            channel.trigger('client-signal', {
                from: MY_ID,
                to: peerId,
                type: 'ice',
                candidate: e.candidate
            });
        }
    };

    pc.onconnectionstatechange = ()=> {
        dbg(`pc ${peerId} state ${pc.connectionState}`);
        if(pc.connectionState === 'failed' || pc.connectionState === 'closed' || pc.connectionState === 'disconnected'){
            try { pc.close(); } catch(e){}
            pcs.delete(peerId);
            tracksAdded.delete(peerId);
            const ra = remoteAudio.get(peerId);
            if(ra){ ra.remove(); remoteAudio.delete(peerId); }
            removeTile(peerId);
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
        if(!tracksAdded.get(peerId)){
            for(const t of localStream.getTracks()) pc.addTrack(t, localStream);
            tracksAdded.set(peerId, true);
        }
        const offer = await pc.createOffer();
        await pc.setLocalDescription(offer);
        channel.trigger('client-signal', {
            from: MY_ID,
            to: peerId,
            type: 'description',
            description: pc.localDescription
        });
        dbg(`offer sent to ${peerId}`);
    } catch(e){
        dbg('createOfferTo error: ' + (e && e.message));
    }
}

async function handleClientSignal(payload){
    try {
        if(!payload || (String(payload.to) !== String(MY_ID))) return;

        const from = String(payload.from);
        const pc = getOrCreatePC(from);

        if(payload.type === 'description'){
            const desc = payload.description;
            if(!desc) return;
            const sdpType = desc.type;
            if(sdpType === 'offer'){
                await ensureLocalStream();
                if(!tracksAdded.get(from)){
                    for(const t of localStream.getTracks()) pc.addTrack(t, localStream);
                    tracksAdded.set(from, true);
                }
                await pc.setRemoteDescription(new RTCSessionDescription(desc));
                const answer = await pc.createAnswer();
                await pc.setLocalDescription(answer);
                channel.trigger('client-signal', {
                    from: MY_ID,
                    to: from,
                    type: 'description',
                    description: pc.localDescription
                });
                dbg(`answered offer from ${from}`);
            } else if(sdpType === 'answer'){
                await pc.setRemoteDescription(new RTCSessionDescription(desc));
                dbg(`received answer from ${from}`);
            }
        } else if(payload.type === 'ice'){
            if(payload.candidate){
                try {
                    await pc.addIceCandidate(new RTCIceCandidate(payload.candidate));
                } catch(e){
                    console.warn('addIce failed', e);
                }
            }
        }
    } catch(e){
        dbg('handleClientSignal error: ' + (e && e.message));
    }
}

channel.bind('pusher:subscription_succeeded', members => {
    dbg('subscription_succeeded');
    let count = 0;
    members.each(member => {
        const id = String(member.id);
        const info = member.info || {};
        createTile(id, info);
        count++;
    });
    updateConnectedCount(count);
    sendPresenceHeartbeat(false);
    localPresenceSent = true;
    if (heartbeatTimer) clearInterval(heartbeatTimer);
    heartbeatTimer = setInterval(()=> sendPresenceHeartbeat(false), 12000);

    members.each(member => {
        const id = String(member.id);
        if(id === MY_ID) return;
        if(Number(MY_ID) < Number(id)){
            createOfferTo(id).catch(e=>dbg('offer error:' + e));
        }
    });
});

channel.bind('pusher:member_added', member => {
    const id = String(member.id);
    const info = member.info || {};
    createTile(id, info);
    const current = peersEl.querySelectorAll('.tile').length;
    updateConnectedCount(current);
    if(Number(MY_ID) < Number(id)){
        createOfferTo(id).catch(e=>dbg('offer error:' + e));
    }
    if (!localPresenceSent) {
        sendPresenceHeartbeat(false);
        localPresenceSent = true;
    }
});

channel.bind('pusher:member_removed', member => {
    const id = String(member.id);
    if(pcs.has(id)){
        try { pcs.get(id).close(); } catch(e){}
        pcs.delete(id);
    }
    if(remoteAudio.has(id)){
        remoteAudio.get(id).remove(); remoteAudio.delete(id);
    }
    removeTile(id);
    const current = peersEl.querySelectorAll('.tile').length;
    updateConnectedCount(current);
});

channel.bind('client-signal', data => {
    handleClientSignal(data);
});

leaveBtn.addEventListener('click', ()=> {
    try { sendPresenceHeartbeat(true); } catch(e){}
    for(const [id, pc] of pcs.entries()){ try { pc.close(); } catch(e){} }
    for(const el of remoteAudio.values()) try { el.remove(); } catch(e){}
    pcs.clear(); remoteAudio.clear();
    try { pusher.unsubscribe(CHANNEL); } catch(e){}
    if (heartbeatTimer) clearInterval(heartbeatTimer);
    location.href = 'room.php';
});

document.addEventListener('pointerdown', ()=> {
    ensureLocalStream().catch(e=>dbg('permission error: ' + (e && e.message)));
}, { once:true });

muteBtn.addEventListener('click', ()=>{
    if(!localStream) return;
    localStream.getAudioTracks().forEach(t => t.enabled = false);
    muteBtn.style.display = 'none';
    unmuteBtn.style.display = '';
});
unmuteBtn.addEventListener('click', ()=>{
    if(!localStream) return;
    localStream.getAudioTracks().forEach(t => t.enabled = true);
    muteBtn.style.display = '';
    unmuteBtn.style.display = 'none';
});

pusher.connection.bind('state_change', s=>dbg('pusher state: ' + JSON.stringify(s)));
pusher.connection.bind('error', e=>dbg('pusher error: ' + JSON.stringify(e)));

window.addEventListener('beforeunload', ()=> {
    try { sendPresenceHeartbeat(true); } catch(e){}
});
dbg('voice script loaded. My ID: ' + MY_ID);
</script>
</body>
</html>
