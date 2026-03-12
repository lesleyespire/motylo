<?php
// voice.php
require "config.php";

// must be logged in
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
$myName = htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8');
$myAvatar = $user['avatar'] ?? null;
$room = preg_replace('/[^A-Za-z0-9_-]/', '', ($_GET['room'] ?? 'main'));

// require Pusher config in config.php: $pusher_app_key, $pusher_app_cluster
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Voice Chat</title>
<link rel="icon" href="root/favicon.ico">
<style>
:root{--bg:#0e0e0e;--panel:#181818;--accent:#5865F2}
body{margin:0;background:var(--bg);color:#fff;font-family:Inter,Arial,sans-serif}
#top{display:flex;align-items:center;padding:12px;background:var(--panel);border-bottom:1px solid #222}
.headerAvatar{width:80px;height:80px;border-radius:50%;overflow:hidden;display:flex;align-items:center;justify-content:center;background:var(--accent);font-weight:700;font-size:34px}
.headerInfo{margin-left:12px}
.headerInfo strong{display:block;font-size:18px}
.controls{margin-left:auto;display:flex;gap:8px;align-items:center}
.btn{background:var(--accent);color:#fff;border:0;padding:8px 12px;border-radius:8px;cursor:pointer}
.grid{display:flex;flex-wrap:wrap;gap:20px;padding:20px}
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
.debug{position:fixed;right:12px;top:12px;background:rgba(0,0,0,0.5);padding:8px;border-radius:8px;font-size:12px}
</style>

<script src="https://js.pusher.com/8.2/pusher.min.js"></script>
</head>
<body>

<div id="top">
    <div class="headerAvatar"><?php if($myAvatar): ?><img src="avatars/<?= htmlspecialchars($myAvatar) ?>"><?php else: ?><?= strtoupper($myName[0]) ?><?php endif; ?></div>
    <div class="headerInfo">
        <strong>Voice Room — <?= htmlspecialchars($room) ?></strong>
        <span class="small">You: <b><?= $myName ?></b> — room: <code><?= htmlspecialchars($room) ?></code></span>
    </div>
    <div class="controls">
        <button id="leaveBtn" class="btn">Leave</button>
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
const CHANNEL = "presence-voice-<?= addslashes($room) ?>";
const MY_ID = <?= json_encode((string)$myId) ?>;
const MY_NAME = <?= json_encode($myName) ?>;
const MY_AVATAR = <?= json_encode($myAvatar) ?>;

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

const muteBtn = document.getElementById('muteBtn');
const unmuteBtn = document.getElementById('unmuteBtn');
const leaveBtn = document.getElementById('leaveBtn');
const localLevelFill = document.getElementById('localLevel');

/* WebRTC state */
const pcs = new Map(); // peerId -> RTCPeerConnection
const remoteAudio = new Map(); // peerId -> audio element
const tiles = new Map(); // peerId -> tile element
const tracksAdded = new Map(); // peerId -> bool (whether local tracks were already added to that pc)
let localStream = null;
let audioCtx = null;
let analyser = null;
let startedLocalPromise = null;
let audioUnlocked = false;

/* debug helper */
function dbg(msg){
    console.debug(msg);
    if(location.search.includes('debug')) {
        debugEl.style.display = 'block';
        debugEl.textContent = (new Date()).toLocaleTimeString() + ' ' + msg + '\n' + debugEl.textContent;
    }
}

/* Create UI tile */
function createTile(id, info){
    if(tiles.has(id)) return;
    const tile = document.createElement('div');
    tile.className = 'tile';
    tile.dataset.id = id;

    const avwrap = document.createElement('div');
    avwrap.className = 'avatar';
    if(info && info.avatar){
        const img = document.createElement('img'); img.src = 'avatars/' + info.avatar; avwrap.appendChild(img);
    } else {
        avwrap.textContent = (info && info.username) ? info.username.charAt(0).toUpperCase() : '?';
        avwrap.style.fontSize = '64px';
    }
    const micInd = document.createElement('div'); micInd.className = 'mic-ind';
    avwrap.appendChild(micInd);

    const nameEl = document.createElement('div'); nameEl.className='username'; nameEl.textContent=(info && info.username)?info.username:'Unknown';

    tile.appendChild(avwrap);
    tile.appendChild(nameEl);
    peersEl.appendChild(tile);
    tiles.set(id, tile);
}

/* remove tile */
function removeTile(id){
    const t = tiles.get(id);
    if(t){ t.remove(); tiles.delete(id); }
}

/* set speaking class */
function setSpeaking(id, yes){
    const t = tiles.get(id);
    if(!t) return;
    if(yes) t.classList.add('speaking'); else t.classList.remove('speaking');
}

/* ensure local media - returns a promise we can await */
async function ensureLocalStream(){
    if(startedLocalPromise) return startedLocalPromise;
    startedLocalPromise = (async ()=>{
        try {
            localStream = await navigator.mediaDevices.getUserMedia({ audio: true });
            // create analyser for local level
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
            // add to existing PCs
            for(const [peerId, pc] of pcs.entries()){
                if(!tracksAdded.get(peerId)){
                    for(const t of localStream.getTracks()) pc.addTrack(t, localStream);
                    tracksAdded.set(peerId, true);
                }
            }
            audioUnlocked = true;
            dbg('local stream obtained');
            return localStream;
        } catch (e){
            dbg('getUserMedia failed: ' + (e && e.message));
            throw e;
        }
    })();
    return startedLocalPromise;
}

/* create RTCPeerConnection for a peer id */
function getOrCreatePC(peerId){
    if(pcs.has(peerId)) return pcs.get(peerId);
    const pc = new RTCPeerConnection({ iceServers:[{urls:'stun:stun.l.google.com:19302'}] });

    // on track
    pc.ontrack = ev => {
        let audio = remoteAudio.get(peerId);
        if(!audio){
            audio = document.createElement('audio');
            audio.autoplay = true;
            audio.controls = false;
            audio.style.display = 'none';
            document.body.appendChild(audio);
            remoteAudio.set(peerId, audio);

            // option: create analyser for remote to show speaking indicator
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

    // ICE -> forward to remote via pusher client event
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
            // cleanup
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

/* Create offer to peer (only called when we decide we should be initiator) */
async function createOfferTo(peerId){
    try {
        await ensureLocalStream();
        const pc = getOrCreatePC(peerId);
        // add local tracks if not added
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

/* Handle incoming client-signal */
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
                // ensure local stream, add tracks, then setRemote and answer
                await ensureLocalStream();
                // add local tracks if not added
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

/* Pusher presence handlers */
channel.bind('pusher:subscription_succeeded', members => {
    dbg('subscription_succeeded');
    members.each(member => {
        const id = String(member.id);
        const info = member.info || {};
        createTile(id, info);
    });
    // After listing members, create offers to appropriate peers
    members.each(member => {
        const id = String(member.id);
        if(id === MY_ID) return;
        // deterministic initiator: smaller numeric id initiates
        if(Number(MY_ID) < Number(id)){
            // ensure local stream and then create offer
            createOfferTo(id).catch(e=>dbg('offer error:' + e));
        }
    });
});

channel.bind('pusher:member_added', member => {
    const id = String(member.id);
    const info = member.info || {};
    createTile(id, info);
    // if I'm smaller numeric id, create offer
    if(Number(MY_ID) < Number(id)){
        createOfferTo(id).catch(e=>dbg('offer error:' + e));
    }
});

channel.bind('pusher:member_removed', member => {
    const id = String(member.id);
    // cleanup pc + audio + tile
    if(pcs.has(id)){
        try { pcs.get(id).close(); } catch(e){}
        pcs.delete(id);
    }
    if(remoteAudio.has(id)){
        remoteAudio.get(id).remove(); remoteAudio.delete(id);
    }
    removeTile(id);
});

/* client events for signaling */
channel.bind('client-signal', data => {
    // data: {from,to,type,description? , candidate?}
    handleClientSignal(data);
});

/* UI: leave */
leaveBtn.addEventListener('click', ()=> {
    // close all pc, remove tiles, unsubscribe
    for(const [id, pc] of pcs.entries()){ try { pc.close(); } catch(e){} }
    for(const el of remoteAudio.values()) try { el.remove(); } catch(e){}
    pcs.clear(); remoteAudio.clear();
    pusher.unsubscribe(CHANNEL);
    location.href = 'room.php';
});

/* Start local audio on first gesture (browsers require gesture) */
document.addEventListener('pointerdown', ()=> {
    ensureLocalStream().catch(e=>dbg('permission error: ' + (e && e.message)));
}, { once:true });

/* mute/unmute */
muteBtn.addEventListener('click', ()=>{
    if(!localStream) return;
    localStream.getAudioTracks().forEach(t => t.enabled = false);
    muteBtn.style.display = 'none'; unmuteBtn.style.display = '';
});
unmuteBtn.addEventListener('click', ()=>{
    if(!localStream) return;
    localStream.getAudioTracks().forEach(t => t.enabled = true);
    muteBtn.style.display = ''; unmuteBtn.style.display = 'none';
});

/* pusher connection debugging */
pusher.connection.bind('state_change', s=>dbg('pusher state: ' + JSON.stringify(s)));
pusher.connection.bind('error', e=>dbg('pusher error: ' + JSON.stringify(e)));

dbg('voice script loaded. My ID: ' + MY_ID);

</script>
</body>
</html>
