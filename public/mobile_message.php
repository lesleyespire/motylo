<?php
require "config.php";

if (empty($_COOKIE['auth_token'])) { header("Location:index.php"); exit; }
$stmt = $pdo->prepare("SELECT id, username, avatar FROM users WHERE auth_token = ?");
$stmt->execute([$_COOKIE['auth_token']]);
$me = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$me) { header("Location:index.php"); exit; }

$me_id = (int)$me['id'];
$me_username = $me['username'];
$me_avatar = $me['avatar'] ?? null;
$target = trim((string)($_GET['user'] ?? ''));
if ($target === '') { die('Missing target user. Use mobile_message.php?user=theirusername'); }

function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Message — <?= e($target) ?></title>
<link rel="icon" href="root/favicon.ico">
<style>
:root{
  --bg:#0b0d12;
  --panel:#10131a;
  --panel2:#0d1118;
  --muted:#aab4c5;
  --text:#eef4ff;
  --accent:#5865F2;
  --accent-rgb:88,101,242;
  --mine:#234f85;
  --card:rgba(255,255,255,.03);
}
*{box-sizing:border-box}
html,body{height:100%;margin:0;color:var(--text);font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,Arial;-webkit-font-smoothing:antialiased;overflow:hidden}
body{
  display:flex;flex-direction:column;
  background:
    radial-gradient(circle at 20% 0%, rgba(var(--accent-rgb), .16), transparent 38%),
    radial-gradient(circle at 80% 0%, rgba(255,255,255,.04), transparent 26%),
    var(--bg);
}
button,input,textarea{font:inherit}
.shell{display:flex;flex-direction:column;height:100vh;min-height:0}
.topbar{
  position:sticky;top:0;z-index:30;
  display:flex;align-items:center;justify-content:space-between;gap:10px;
  padding:10px 12px calc(10px + env(safe-area-inset-top));
  background:linear-gradient(180deg, rgba(16,19,26,.98), rgba(16,19,26,.92));
  backdrop-filter:blur(18px);
  border-bottom:1px solid rgba(255,255,255,.04);
}
.topLeft{display:flex;align-items:center;gap:10px;min-width:0}
.iconBtn,.ghostBtn,.pillBtn,.smallBtn,.sendBtn,.topBtn{
  border:0;border-radius:14px;color:var(--text);cursor:pointer
}
.iconBtn{width:42px;height:42px;background:rgba(255,255,255,.03);font-size:18px;position:relative;flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;line-height:1}
.badge{position:absolute;top:-6px;right:-6px;background:#ff4d4f;color:#fff;padding:3px 7px;border-radius:999px;font-size:12px;line-height:1}
.titleWrap{min-width:0;display:flex;flex-direction:column;gap:2px}
.pageTitle{font-weight:850;font-size:15px;line-height:1.1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:54vw}
.pageSub{color:var(--muted);font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:54vw}
.topActions{display:flex;align-items:center;gap:8px;flex:0 0 auto}
.topBtn{background:rgba(255,255,255,.03);padding:10px 12px;font-weight:700}

.main{flex:1;min-height:0;display:flex;flex-direction:column;padding:10px 10px 8px;gap:10px;overflow:hidden}
.chatHeader{
  display:flex;align-items:center;justify-content:space-between;gap:10px;
  padding:12px 14px;border-radius:18px;background:linear-gradient(135deg, rgba(255,255,255,.03), rgba(var(--accent-rgb), .10));
  border:1px solid rgba(255,255,255,.04);box-shadow:0 16px 40px rgba(0,0,0,.22)
}
.chatHeader .who{min-width:0;display:flex;align-items:center;gap:12px}
.chatHeader .avatar{width:46px;height:46px;border-radius:14px;overflow:hidden;background:#202633;display:flex;align-items:center;justify-content:center;font-weight:800;flex:0 0 46px}
.chatHeader .avatar img{width:100%;height:100%;object-fit:cover;display:block}
.chatHeader .name{font-weight:850;font-size:16px;line-height:1.1}
.chatHeader .meta{color:var(--muted);font-size:12px;margin-top:3px}
.headerBtns{display:flex;gap:8px;flex:0 0 auto}
.headerBtns .pillBtn{background:rgba(255,255,255,.04);padding:10px 12px;font-weight:750}
.headerBtns .pillBtn.primary{background:linear-gradient(135deg, var(--accent), #7a86ff)}

.chatWindow{
  flex:1;min-height:0;overflow:auto;
  padding:6px 2px 12px;scroll-behavior:smooth;
}
.emptyState{color:var(--muted);text-align:center;padding:18px 12px}
.msgRow{display:flex;align-items:flex-start;gap:10px;margin-bottom:12px;position:relative}
.msgRow.mine{flex-direction:row-reverse}
.msgAvatar{width:40px;height:40px;border-radius:13px;overflow:hidden;flex:0 0 40px;background:#1d2230;display:flex;align-items:center;justify-content:center;border:1px solid rgba(255,255,255,.04)}
.msgAvatar img{width:100%;height:100%;object-fit:cover;display:block}
.msgAvatar.hidden{visibility:hidden}
.msgBubble{
  max-width:min(78%, 620px);
  padding:11px 12px 10px;
  border-radius:18px;
  background:rgba(21,24,32,.96);
  box-shadow:0 8px 24px rgba(0,0,0,.22);
  border:1px solid rgba(255,255,255,.03);
  position:relative;
  transform:translateX(0);
  transition:transform .14s ease, background .14s ease;
  touch-action:pan-y;
  word-break:break-word;
}
.msgRow.mine .msgBubble{background:linear-gradient(180deg, rgba(37,64,110,.98), rgba(31,56,98,.98));}
.msgBubble.replying{box-shadow:0 0 0 2px rgba(88,101,242,.28), 0 10px 28px rgba(0,0,0,.22)}
.msgUser{font-weight:800;font-size:13px;color:#f1f5ff;margin-bottom:6px}
.msgText{font-size:15px;line-height:1.45;white-space:pre-wrap;word-break:break-word}
.msgText a{color:#9ec4ff;text-decoration:underline}
.msgText img{max-width:100%;height:auto;border-radius:12px;display:block;margin-top:6px}
.msgMeta{margin-top:6px;font-size:11px;color:var(--muted);text-align:right}
.replySnippet{border-left:3px solid rgba(255,255,255,.08);padding:7px 8px;margin-bottom:8px;color:#d4dcf1;font-size:13px;border-radius:10px;background:rgba(255,255,255,.03)}
.replySnippet .ruser{font-weight:800;margin-bottom:3px}
.bigEmoji{font-size:1.95em;line-height:1;vertical-align:-0.12em;display:inline-block}

.composerWrap{padding:0 0 calc(6px + env(safe-area-inset-bottom));}
.replyPreview{
  display:none;align-items:center;gap:8px;
  margin:0 10px 8px;padding:10px 12px;border-radius:16px;background:rgba(255,255,255,.04);
  border:1px solid rgba(255,255,255,.04);color:var(--text)
}
.replyPreview .rpText{opacity:.92;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.replyPreview .rpCancel{margin-left:auto;background:transparent;border:0;color:#ff8f8f;font-size:18px;cursor:pointer}
.composer{
  display:flex;align-items:flex-end;gap:8px;
  padding:10px;border-radius:20px;background:linear-gradient(180deg, rgba(var(--accent-rgb), .04), rgba(255,255,255,.02));
  border:1px solid rgba(255,255,255,.04);box-shadow:0 12px 30px rgba(0,0,0,.18)
}
#msg{flex:1;min-height:46px;max-height:140px;resize:none;padding:12px 12px;border-radius:14px;border:0;background:#0d1118;color:#fff;font-size:16px;outline:none}
#msg::placeholder{color:#77839a}
.sendBtn{background:linear-gradient(135deg,var(--accent),#7b89ff);padding:12px 14px;font-weight:800;min-height:46px}
.smallBtn{background:rgba(255,255,255,.04);padding:12px 12px;min-height:46px;min-width:46px;font-size:18px}
.charCount{font-size:12px;color:var(--muted);min-width:42px;text-align:right;padding-bottom:6px}

.incomingCall{position:fixed;inset:0;z-index:9999;display:none;align-items:center;justify-content:center;background:radial-gradient(circle at center, rgba(88,101,242,.18), rgba(4,6,10,.88) 55%, rgba(0,0,0,.96) 100%);backdrop-filter:blur(12px);animation:callBackdropIn .18s ease-out}
.incomingCall::before{content:'';position:absolute;inset:0;background:linear-gradient(180deg, rgba(88,101,242,.12), transparent 35%, rgba(255,77,79,.08));pointer-events:none}
.incomingCard{position:relative;width:min(94vw, 460px);border-radius:28px;padding:20px 18px 18px;background:linear-gradient(180deg, rgba(20,24,34,.99), rgba(10,13,20,.99));border:1px solid rgba(255,255,255,.08);box-shadow:0 28px 100px rgba(0,0,0,.68);transform:translateY(0) scale(1);animation:callCardPop .18s ease-out}
.incomingCard::after{content:'';position:absolute;inset:-8px;border-radius:34px;border:1px solid rgba(88,101,242,.22);box-shadow:0 0 0 8px rgba(88,101,242,.08);pointer-events:none;animation:callPulse 1.15s ease-out infinite}
.incomingHead{display:flex;gap:14px;align-items:center}
.incomingAvatar{width:68px;height:68px;border-radius:22px;overflow:hidden;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#232a3d,#151a26);font-weight:900;font-size:26px;flex:0 0 68px;border:1px solid rgba(255,255,255,.06);box-shadow:0 0 0 10px rgba(88,101,242,.10)}
.incomingAvatar img{width:100%;height:100%;object-fit:cover;display:block}
.incomingTitle{font-weight:950;font-size:22px;line-height:1.1;letter-spacing:.01em}
.incomingSub{margin-top:6px;color:#e6ecfb;font-size:15px;line-height:1.45}
.incomingActions{display:flex;gap:10px;margin-top:18px}
.incomingActions .pillBtn{flex:1;padding:14px 14px;font-weight:950;min-height:50px}
.incomingActions .pillBtn.primary{background:linear-gradient(135deg,#5865F2,#7b89ff);box-shadow:0 12px 30px rgba(88,101,242,.28)}
.incomingActions .pillBtn.ghost{background:rgba(255,255,255,.06)}
.incomingHint{margin-top:12px;color:var(--muted);font-size:12px;text-align:center;line-height:1.4}
@keyframes callBackdropIn{from{opacity:0}to{opacity:1}}
@keyframes callCardPop{from{transform:translateY(12px) scale(.96);opacity:.2}to{transform:translateY(0) scale(1);opacity:1}}
@keyframes callPulse{0%{transform:scale(1);opacity:.8}100%{transform:scale(1.08);opacity:0}}

.typingBar{min-height:20px;padding:0 4px;color:var(--muted);font-size:13px}

/* sheet / drawer */
.sheetOverlay{position:fixed;inset:0;background:rgba(0,0,0,.48);z-index:60;display:none}
.sheet{position:fixed;left:0;right:0;bottom:0;z-index:70;transform:translateY(110%);transition:transform .22s ease;background:linear-gradient(180deg, rgba(16,19,26,.98), rgba(11,14,20,.98));border-top-left-radius:22px;border-top-right-radius:22px;box-shadow:0 -18px 60px rgba(0,0,0,.52);max-height:84vh;display:flex;flex-direction:column}
.sheet.open{transform:translateY(0)}
.sheetOverlay.open{display:block}
.sheetHandle{width:44px;height:5px;border-radius:999px;background:rgba(255,255,255,.24);margin:10px auto 8px}
.sheetHead{padding:0 14px 10px;display:flex;align-items:center;justify-content:space-between;gap:10px}
.sheetTitle{font-weight:850;font-size:16px}
.tabs{display:flex;gap:8px;padding:0 14px 10px}
.tabBtn{flex:1;border:0;border-radius:14px;padding:11px 12px;background:rgba(255,255,255,.03);color:var(--text);font-weight:800;cursor:pointer}
.tabBtn.active{background:linear-gradient(135deg, rgba(88,101,242,.28), rgba(88,101,242,.16));box-shadow:0 0 0 1px rgba(88,101,242,.18) inset}
.sheetBody{padding:0 12px 12px;overflow:auto;min-height:0}
.profileCard{padding:12px;border-radius:18px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.04);margin-bottom:10px}
.profileHead{display:flex;gap:12px;align-items:center}
.profilePfp{width:64px;height:64px;border-radius:18px;overflow:hidden;background:#1f2533;display:flex;align-items:center;justify-content:center;font-weight:850;font-size:24px;flex:0 0 64px}
.profilePfp img{width:100%;height:100%;object-fit:cover;display:block}
.profileName{font-weight:900;font-size:18px;line-height:1.1}
.profileBio{margin-top:8px;color:#d8e0f1;font-size:14px;line-height:1.5;white-space:normal;word-break:break-word}
.sectionLabel{display:flex;align-items:center;justify-content:space-between;color:var(--muted);font-size:12px;letter-spacing:.08em;text-transform:uppercase;padding:10px 4px 8px}
.friendList{display:flex;flex-direction:column;gap:8px}
.friendCard{display:flex;gap:10px;align-items:center;padding:10px 12px;border-radius:16px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.03);cursor:pointer}
.friendCard .pfp{width:44px;height:44px;border-radius:14px;overflow:hidden;background:#1d2230;flex:0 0 44px;display:flex;align-items:center;justify-content:center;font-weight:800}
.friendCard .pfp img{width:100%;height:100%;object-fit:cover;display:block}
.friendCard .name{font-weight:850}
.friendCard .sub{color:var(--muted);font-size:12px;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.actionRow{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.actionRow .pillBtn{background:rgba(255,255,255,.04);padding:10px 12px;font-weight:800}
.actionRow .pillBtn.primary{background:linear-gradient(135deg,var(--accent),#7b89ff)}
.actionRow .pillBtn.warn{background:linear-gradient(135deg,#b54b4b,#d46a6a)}

.voiceModal{position:fixed;inset:0;background:rgba(4,6,10,.86);z-index:90;display:none;align-items:stretch;justify-content:stretch}
.voiceModal.open{display:flex}
.voiceShell{flex:1;display:flex;flex-direction:column;background:linear-gradient(180deg, rgba(16,19,26,.98), rgba(11,14,20,.98))}
.voiceTop{display:flex;align-items:center;gap:10px;padding:12px 12px calc(12px + env(safe-area-inset-top));border-bottom:1px solid rgba(255,255,255,.04)}
.voiceTop .info{min-width:0;flex:1}
.voiceTop .info strong{display:block;font-size:16px}
.voiceTop .info span{color:var(--muted);font-size:12px}
.voiceTop .voiceClose{width:42px;height:42px;border-radius:14px;border:0;background:rgba(255,255,255,.04);color:var(--text);font-size:18px;cursor:pointer}
.voiceFrame{flex:1;min-height:0;border:0;width:100%;background:#0b0f12}

.notifDrawer{position:fixed;top:62px;left:10px;right:10px;z-index:80;display:none;max-height:60vh;overflow:auto;background:#0d0d0e;border:1px solid rgba(255,255,255,.05);border-radius:16px;box-shadow:0 16px 44px rgba(0,0,0,.5)}
.notifRow{padding:12px;border-bottom:1px solid rgba(255,255,255,.03);cursor:pointer}
.notifRow:last-child{border-bottom:0}
.notifTitle{font-weight:800;margin-bottom:4px}
.notifMsg{color:var(--muted);font-size:13px}

@media(min-width:860px){
  .shell{padding-right:0}
  .main{padding:14px 14px 10px;max-width:1100px;margin:0 auto;width:100%}
  .pageTitle,.pageSub{max-width:unset}
  .sheet{left:auto;right:12px;top:72px;bottom:12px;width:380px;transform:translateX(110%);border-radius:22px;max-height:none}
  .sheet.open{transform:translateX(0)}
  .sheetHandle{display:none}
  .sheetOverlay{display:none !important}
  .voiceModal{inset:10px; border-radius:24px; overflow:hidden}
  .chatWindow{padding-right:6px}
}
</style>
</head>
<body>
<div class="shell" id="shell">
  <div class="topbar">
    <div class="topLeft">
      <button class="iconBtn" id="backBtn" title="Back">◀</button>
      <div class="titleWrap">
        <div class="pageTitle">Message · <?= e($target) ?></div>
        <div class="pageSub">Mobile direct messages</div>
      </div>
    </div>
    <div class="topActions">
      <button class="topBtn" id="friendsBtn">Friends</button>
      <button class="iconBtn" id="voiceBtn" title="Voice call">🎙</button>
      <div class="iconBtn notifWrap" id="notifBell" title="Notifications">🔔<span id="notifBadge" class="badge" style="display:none">0</span></div>
    </div>
  </div>

  <div class="main">
    <div class="chatHeader">
      <div class="who">
        <div class="avatar" id="headerAvatar"></div>
        <div style="min-width:0">
          <div class="name" id="headerName">Loading…</div>
          <div class="meta" id="headerMeta">Loading relationship…</div>
        </div>
      </div>
      <div class="headerBtns">
        <button class="pillBtn" id="sheetOpenBtn">Profile</button>
      </div>
    </div>

    <div class="typingBar" id="typingBar"></div>
    <div class="chatWindow" id="chat" aria-live="polite"><div class="emptyState">Loading messages…</div></div>

    <div class="composerWrap">
      <div class="replyPreview" id="replyPreview">
        <div style="font-weight:800">Replying to <span id="rpUser"></span></div>
        <div class="rpText" id="rpText"></div>
        <button class="rpCancel" id="rpCancel" title="Cancel reply">✖</button>
      </div>
      <div class="composer">
        <textarea id="msg" maxlength="750" placeholder="Send a message…" rows="1"></textarea>
        <input id="imageInput" type="file" accept="image/png,image/jpeg,image/webp,image/gif" style="display:none">
        <div class="charCount" id="charCount">0/750</div>
        <button class="smallBtn" id="imageBtn" title="Attach image">📷</button>
        <button class="sendBtn" id="sendBtn">Send</button>
      </div>
    </div>
  </div>
</div>

<div class="sheetOverlay" id="sheetOverlay"></div>
<aside class="sheet" id="sheet" aria-hidden="true">
  <div class="sheetHandle"></div>
  <div class="sheetHead">
    <div class="sheetTitle">Profile & friends</div>
    <button class="pillBtn" id="closeSheetBtn" style="padding:10px 12px;background:rgba(255,255,255,.04)">Close</button>
  </div>
  <div class="tabs">
    <button class="tabBtn active" data-tab="profile" id="tabProfile">Profile</button>
    <button class="tabBtn" data-tab="friends" id="tabFriends">Friends</button>
  </div>
  <div class="sheetBody">
    <div id="panelProfile">
      <div class="profileCard">
        <div class="profileHead">
          <div class="profilePfp" id="sidePfp"></div>
          <div style="min-width:0;flex:1">
            <div class="profileName" id="sideUsername">Loading…</div>
            <div style="color:var(--muted);font-size:12px;margin-top:3px" id="sideRole"></div>
          </div>
        </div>
        <div class="profileBio" id="sideBio">Loading bio…</div>
        <div class="actionRow" id="friendActions" style="margin-top:12px">
          <button class="pillBtn primary" id="friendBtn">Loading…</button>
          <button class="pillBtn" id="blockBtn">Block</button>
          <button class="pillBtn primary" id="voiceBtn2">Voice</button>
        </div>
      </div>

      <div class="sectionLabel"><span>Their visible friends</span><span id="theirFriendCount">0</span></div>
      <div class="friendList" id="theirFriends"><div style="color:var(--muted)">Loading…</div></div>
    </div>

    <div id="panelFriends" style="display:none">
      <div class="sectionLabel"><span>Your friends</span><span id="yourFriendCount">0</span></div>
      <div class="friendList" id="yourFriends"><div style="color:var(--muted)">Loading…</div></div>
    </div>
  </div>
</aside>

<div class="voiceModal" id="voiceModal" aria-hidden="true">
  <div class="voiceShell">
    <div class="voiceTop">
      <button class="voiceClose" id="voiceCloseBtn">✖</button>
      <div class="info">
        <strong id="voiceTitle">Voice with <?= e($target) ?></strong>
        <span id="voiceStatus">Connecting…</span>
      </div>
      <button class="pillBtn primary" id="voiceLeaveBtn" style="padding:10px 12px">Leave</button>
    </div>
    <iframe class="voiceFrame" id="voiceFrame" src="about:blank" allow="microphone; autoplay; clipboard-write"></iframe>
  </div>
</div>

<div class="incomingCall" id="incomingCall" aria-hidden="true">
  <div class="incomingCard">
    <div class="incomingHead">
      <div class="incomingAvatar" id="incomingAvatar"></div>
      <div style="min-width:0;flex:1">
        <div class="incomingTitle" id="incomingTitle">Incoming call</div>
        <div class="incomingSub" id="incomingSub">Someone is calling…</div>
      </div>
    </div>
    <div class="incomingActions">
      <button class="pillBtn primary" id="incomingAcceptBtn">Accept</button>
      <button class="pillBtn ghost" id="incomingDismissBtn">Dismiss</button>
    </div>
    <div class="incomingHint">This stays on screen until you choose. The call will ring again so it is hard to miss.</div>
  </div>
</div>

<div id="notifDropdown" class="notifDrawer"></div>

<audio id="bell" preload="auto"><source src="root/bell.mp3" type="audio/mpeg"></audio>
<audio id="bell2" preload="auto"><source src="root/bell_2.mp3" type="audio/mpeg"></audio>

<script>
const TARGET = <?= json_encode($target) ?>;
const MY_ID = <?= json_encode($me_id) ?>;
const MY_USERNAME = <?= json_encode($me_username) ?>;
const POLL_INTERVAL = 8000;
const MAX_MESSAGE_LENGTH = 750;
const NOTIF_API = 'notifications.php';
const UPLOAD_API = 'upload_image.php';

const SHEET = document.getElementById('sheet');
const SHEET_OVERLAY = document.getElementById('sheetOverlay');
const VOICE_MODAL = document.getElementById('voiceModal');
const VOICE_FRAME = document.getElementById('voiceFrame');
const VOICE_TITLE = document.getElementById('voiceTitle');
const IMAGE_BTN = document.getElementById('imageBtn');
const IMAGE_INPUT = document.getElementById('imageInput');
const INCOMING_CALL = document.getElementById('incomingCall');
const INCOMING_AVATAR = document.getElementById('incomingAvatar');
const INCOMING_TITLE = document.getElementById('incomingTitle');
const INCOMING_SUB = document.getElementById('incomingSub');
const INCOMING_ACCEPT = document.getElementById('incomingAcceptBtn');
const INCOMING_DISMISS = document.getElementById('incomingDismissBtn');

const chatEl = document.getElementById('chat');
const msgEl = document.getElementById('msg');
const sendBtn = document.getElementById('sendBtn');
const charCount = document.getElementById('charCount');
const rpPreview = document.getElementById('replyPreview');
const rpUser = document.getElementById('rpUser');
const rpText = document.getElementById('rpText');
const rpCancel = document.getElementById('rpCancel');
const typingBar = document.getElementById('typingBar');
const notifBell = document.getElementById('notifBell');
const notifBadge = document.getElementById('notifBadge');
const notifDropdown = document.getElementById('notifDropdown');
const friendsBtn = document.getElementById('friendsBtn');
const sheetOpenBtn = document.getElementById('sheetOpenBtn');
const closeSheetBtn = document.getElementById('closeSheetBtn');
const tabProfile = document.getElementById('tabProfile');
const tabFriends = document.getElementById('tabFriends');
const panelProfile = document.getElementById('panelProfile');
const panelFriends = document.getElementById('panelFriends');
const headerAvatar = document.getElementById('headerAvatar');
const headerName = document.getElementById('headerName');
const headerMeta = document.getElementById('headerMeta');
const sidePfp = document.getElementById('sidePfp');
const sideUsername = document.getElementById('sideUsername');
const sideRole = document.getElementById('sideRole');
const sideBio = document.getElementById('sideBio');
const friendBtn = document.getElementById('friendBtn');
const blockBtn = document.getElementById('blockBtn');
const voiceBtn = document.getElementById('voiceBtn');
const voiceBtn2 = document.getElementById('voiceBtn2');
const voiceCloseBtn = document.getElementById('voiceCloseBtn');
const voiceLeaveBtn = document.getElementById('voiceLeaveBtn');
const voiceStatus = document.getElementById('voiceStatus');
const theirFriendsEl = document.getElementById('theirFriends');
const yourFriendsEl = document.getElementById('yourFriends');
const theirFriendCount = document.getElementById('theirFriendCount');
const yourFriendCount = document.getElementById('yourFriendCount');

let audioUnlocked = false;
document.addEventListener('pointerdown', ()=> audioUnlocked = true, { once:true });

let running = true;
let lastId = 0;
let currentUser = null;
let relationship = { status:'none', allowed:false, blocked:false, mutual_friends:[], their_friends:[] };
let replyingTo = null;
let inFlight = false;
let lastUnread = 0;
let lastTypingAt = 0;
let messagesById = new Map();
let pendingVoiceCaller = '';
let lastIncomingVoiceNotificationId = null;
let incomingVoiceSeen = new Set();
let incomingVoiceRinger = null;
let activeIncomingVoice = null;

function escapeHtml(s){ if (s==null) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }
function escapeAttr(s){ return escapeHtml(s).replace(/`/g,'&#096;'); }
function normalizeText(s){
  return String(s || '')
    .replace(/\r\n?/g,'\n')
    .replace(/[\t ]+\n/g,'\n')
    .replace(/\n{3,}/g,'\n\n')
    .trim();
}
function parseTS(ts){ if (!ts) return null; const d = new Date(ts); return isNaN(d) ? null : d; }
function relativeTime(ts){ const d = parseTS(ts); if (!d) return ''; const diff = (Date.now() - d.getTime())/1000; if (diff < 5) return 'just now'; if (diff < 60) return Math.floor(diff) + 's'; if (diff < 3600) return Math.floor(diff/60) + 'm'; if (diff < 86400) return Math.floor(diff/3600) + 'h'; return d.toLocaleDateString(); }
function formatBioHtml(bio){
  if (!bio) return '<span style="color:var(--muted)">(no bio)</span>';
  const cleaned = normalizeText(bio);
  if (!cleaned) return '<span style="color:var(--muted)">(no bio)</span>';
  return escapeHtml(cleaned).replace(/\n/g,'<br>');
}
function hexFromRGB(r,g,b){
  return '#' + [r,g,b].map(v => Math.max(0, Math.min(255, Math.round(v))).toString(16).padStart(2,'0')).join('');
}
function applyThemeFromRGB(r,g,b){
  const root = document.documentElement.style;
  const rr = Math.max(0, Math.min(255, Math.round(r)));
  const gg = Math.max(0, Math.min(255, Math.round(g)));
  const bb = Math.max(0, Math.min(255, Math.round(b)));
  root.setProperty('--accent-rgb', `${rr},${gg},${bb}`);
  root.setProperty('--accent', hexFromRGB(rr, gg, bb));
}
function applyAvatarThemeFromUrl(url){
  try {
    if (!url) return;
    const img = new Image();
    img.crossOrigin = 'anonymous';
    img.onload = () => {
      try {
        const canvas = document.createElement('canvas');
        const size = 28;
        canvas.width = size;
        canvas.height = size;
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        if (!ctx) return;
        ctx.drawImage(img, 0, 0, size, size);
        const data = ctx.getImageData(0, 0, size, size).data;
        let r = 0, g = 0, b = 0, n = 0;
        for (let i = 0; i < data.length; i += 4) {
          const a = data[i + 3];
          if (a < 40) continue;
          r += data[i];
          g += data[i + 1];
          b += data[i + 2];
          n++;
        }
        if (n > 0) applyThemeFromRGB(r / n, g / n, b / n);
      } catch (e) {}
    };
    img.onerror = () => {};
    img.src = url;
  } catch (e) {}
}
function setAvatar(el, user, tintTheme = false){
  el.innerHTML = '';
  const url = user && user.avatar ? ((String(user.avatar).indexOf('/') === 0 || String(user.avatar).startsWith('http')) ? user.avatar : 'avatars/' + encodeURIComponent(user.avatar)) : '';
  if (url) {
    const img = document.createElement('img');
    img.src = url;
    img.alt = user && user.username ? user.username : '';
    el.appendChild(img);
    if (tintTheme) applyAvatarThemeFromUrl(url);
  } else {
    el.textContent = (user && user.username ? user.username[0] : '?').toUpperCase();
  }
}
function safeUrl(raw){
  try {
    const u = new URL(String(raw), window.location.href);
    if (!['http:','https:'].includes(u.protocol)) return '';
    return u.href;
  } catch(e){ return ''; }
}
function normalizeVoiceCallText(n){
  if (!n) return '';
  return [n.type, n.kind, n.category, n.action, n.ref_type, n.message, n.message_text, n.message_body, n.title, n.subject, n.source_username]
    .filter(v => v !== undefined && v !== null)
    .map(v => String(v).toLowerCase())
    .join(' | ');
}
function isVoiceCallNotification(n){
  if (!n) return false;
  const hay = normalizeVoiceCallText(n);
  if (!hay) return false;
  if (hay.includes('voice') && (hay.includes('call') || hay.includes('calling') || hay.includes('ring'))) return true;
  if (hay.includes('incoming call') || hay.includes('voice call') || hay.includes('call from') || hay.includes('call invited')) return true;
  if (hay.includes('dm voice') || hay.includes('private voice') || hay.includes('one-on-one voice')) return true;
  return false;
}
function stopIncomingCallRinger(){
  if (incomingVoiceRinger) {
    clearInterval(incomingVoiceRinger);
    incomingVoiceRinger = null;
  }
  try { const a = document.getElementById('bell2'); if (a) { a.loop = false; a.pause(); a.currentTime = 0; } } catch(e){}
}
function startIncomingCallRinger(){
  stopIncomingCallRinger();
  const beat = ()=> {
    try {
      if (!audioUnlocked) return;
      const a = document.getElementById('bell2');
      if (a) {
        a.currentTime = 0;
        a.play().catch(()=>{});
      }
      if (navigator.vibrate) navigator.vibrate([180, 80, 220]);
    } catch(e){}
  };
  beat();
  incomingVoiceRinger = setInterval(beat, 3200);
}
function showIncomingCall(userName, avatar, text, notificationId){
  activeIncomingVoice = notificationId || activeIncomingVoice || null;
  pendingVoiceCaller = (userName || TARGET || '').trim();
  ensureIncomingCallVisible(true);
  INCOMING_TITLE.textContent = pendingVoiceCaller ? `${pendingVoiceCaller} is calling you` : 'Incoming voice call';
  INCOMING_SUB.textContent = text || 'Tap accept to join the call without leaving this page.';
  setAvatar(INCOMING_AVATAR, { username: pendingVoiceCaller || 'Caller', avatar });
  INCOMING_CALL.style.display = 'flex';
  INCOMING_CALL.setAttribute('aria-hidden','false');
  startIncomingCallRinger();
}
function hideIncomingCall(){
  INCOMING_CALL.style.display = 'none';
  INCOMING_CALL.setAttribute('aria-hidden','true');
  activeIncomingVoice = null;
  stopIncomingCallRinger();
}
function ensureIncomingCallVisible(force = false){
  if (!INCOMING_CALL) return;
  if (force) {
    INCOMING_CALL.style.display = 'flex';
    INCOMING_CALL.setAttribute('aria-hidden','false');
  }
}
const EMOJI_RE = /([\u{1F1E6}-\u{1FAFF}\u2600-\u27BF])/gu;
function renderMessageText(raw){
  let s = escapeHtml(String(raw || ''));
  s = s.replace(/!\[image\]\((\/images\/[^\s)]+)\)/g, (_, path) => {
    const safe = '/images/' + encodeURIComponent(path.replace(/^\/images\//,''));
    return `<img src="${escapeAttr(safe)}" alt="image">`;
  });
  s = s.replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_top" rel="noopener noreferrer">$1</a>');
  s = s.replace(/\n/g, '<br>');
  s = s.replace(EMOJI_RE, '<span class="bigEmoji">$1</span>');
  return s;
}
function apiGet(params){
  const q = new URLSearchParams(params).toString();
  return fetch('message_interface.php?user=' + encodeURIComponent(TARGET) + (q ? '&' + q : ''), { credentials:'same-origin' }).then(r => r.json());
}
function normalizeRoomSlug(s){
  return String(s || '')
    .toLowerCase()
    .replace(/[^a-z0-9_-]+/g, '_')
    .replace(/_+/g, '_')
    .replace(/^_|_$/g, '');
}
function voiceRoomForPair(a, b){
  const parts = [normalizeRoomSlug(a), normalizeRoomSlug(b)].filter(Boolean).sort();
  return 'dmvoice_' + (parts.join('__') || 'room');
}
function setSheet(tab){
  const p = tab === 'profile';
  tabProfile.classList.toggle('active', p);
  tabFriends.classList.toggle('active', !p);
  panelProfile.style.display = p ? 'block' : 'none';
  panelFriends.style.display = p ? 'none' : 'block';
}
function openSheet(tab='profile'){
  SHEET.classList.add('open');
  SHEET_OVERLAY.classList.add('open');
  SHEET.setAttribute('aria-hidden','false');
  setSheet(tab);
}
function closeSheet(){
  SHEET.classList.remove('open');
  SHEET_OVERLAY.classList.remove('open');
  SHEET.setAttribute('aria-hidden','true');
}
function makeFriendCard(f){
  const card = document.createElement('div');
  card.className = 'friendCard';
  const p = document.createElement('div'); p.className = 'pfp';
  if (f && f.avatar) {
    const img = document.createElement('img');
    img.src = (f.avatar.indexOf('/') === 0 || String(f.avatar).startsWith('http')) ? f.avatar : 'avatars/' + encodeURIComponent(f.avatar);
    p.appendChild(img);
  } else {
    p.textContent = (f && f.username ? f.username[0] : '?').toUpperCase();
  }
  const meta = document.createElement('div'); meta.style.minWidth='0'; meta.style.flex='1';
  const name = document.createElement('div'); name.className='name'; name.textContent = (f && f.username) ? f.username : 'Unknown';
  const sub = document.createElement('div'); sub.className='sub'; sub.textContent = f && f.status ? f.status : 'Tap to open chat';
  meta.appendChild(name); meta.appendChild(sub);
  card.appendChild(p); card.appendChild(meta);
  card.addEventListener('click', ()=> { if (f && f.username) location.href = 'mobile_message.php?user=' + encodeURIComponent(f.username); });
  return card;
}
function updateHeader(target){
  if (!target) return;
  headerName.textContent = target.username || TARGET;
  headerMeta.textContent = target.bio ? 'Profile loaded' : 'No bio provided';
  sideUsername.textContent = target.username || TARGET;
  sideRole.textContent = target.role ? target.role : '';
  sideBio.innerHTML = formatBioHtml(target.bio || '');
  headerAvatar.innerHTML = '';
  sidePfp.innerHTML = '';

  const makeAvatar = (el, tint=false) => {
    if (target.avatar) {
      const img = document.createElement('img');
      const url = (String(target.avatar).indexOf('/') === 0 || String(target.avatar).startsWith('http')) ? target.avatar : 'avatars/' + encodeURIComponent(target.avatar);
      img.src = url;
      el.appendChild(img);
      if (tint) applyAvatarThemeFromUrl(url);
    } else {
      el.textContent = (target.username ? target.username[0] : '?').toUpperCase();
    }
  };
  makeAvatar(headerAvatar, true);
  makeAvatar(sidePfp, false);
}
function updateRelationshipUI(){
  const rel = relationship || {};
  if (rel.status === 'friends') {
    friendBtn.textContent = 'Remove friend';
    friendBtn.classList.remove('primary');
  } else if (rel.status === 'requested') {
    if (rel.initiator && Number(rel.initiator) === Number(MY_ID)) {
      friendBtn.textContent = 'Cancel request';
    } else {
      friendBtn.textContent = rel.requested_kind === 'acquaintance' ? 'Accept acquaintance' : 'Accept request';
    }
    friendBtn.classList.add('primary');
  } else if (rel.status === 'acquaintance') {
    friendBtn.textContent = 'Add friend';
    friendBtn.classList.add('primary');
  } else {
    friendBtn.textContent = 'Send friend request';
    friendBtn.classList.add('primary');
  }

  if (rel.blocked) { blockBtn.textContent = 'Unblock'; blockBtn.dataset.blocked = '1'; }
  else { blockBtn.textContent = 'Block'; blockBtn.dataset.blocked = '0'; }

  const canMessage = rel.allowed !== false && !rel.blocked;
  msgEl.disabled = !canMessage;
  sendBtn.disabled = !canMessage;
  msgEl.placeholder = canMessage ? 'Send a message…' : 'Messaging unavailable';

  if (rel.status === 'friends') {
    voiceBtn.classList.add('primary');
    voiceBtn2.classList.add('primary');
  }
}
function renderProfileSection(target, rel){
  updateHeader(target || { username: TARGET });
  if (target && target.bio) headerMeta.textContent = normalizeText(target.bio).split('\n')[0].slice(0, 80) || 'Profile loaded';

  const visibleTheirFriends = rel && rel.status === 'friends'
    ? (Array.isArray(rel.their_friends) ? rel.their_friends : Array.isArray(rel.mutual_friends) ? rel.mutual_friends : [])
    : [];
  theirFriendCount.textContent = String(visibleTheirFriends.length);
  theirFriendsEl.innerHTML = '';
  if (visibleTheirFriends.length === 0) {
    theirFriendsEl.innerHTML = '<div style="color:var(--muted);padding:6px 4px">' + (rel && rel.status === 'friends' ? 'No visible friends' : 'Be friends to see this list') + '</div>';
  } else {
    visibleTheirFriends.forEach(f => theirFriendsEl.appendChild(makeFriendCard(f)));
  }
}
function renderYourFriends(list){
  const rows = Array.isArray(list) ? list : [];
  yourFriendCount.textContent = String(rows.length);
  yourFriendsEl.innerHTML = '';
  if (rows.length === 0) {
    yourFriendsEl.innerHTML = '<div style="color:var(--muted);padding:6px 4px">No friends yet</div>';
    return;
  }
  rows.forEach(f => yourFriendsEl.appendChild(makeFriendCard(f)));
}
function cleanRoomAvatar(m){
  const wrap = document.createElement('div');
  wrap.className = 'msgAvatar';
  if (m.avatar) {
    const img = document.createElement('img');
    img.src = (String(m.avatar).indexOf('/') === 0 || String(m.avatar).startsWith('http')) ? m.avatar : 'avatars/' + encodeURIComponent(m.avatar);
    wrap.appendChild(img);
  } else {
    wrap.textContent = (m.username || '?')[0].toUpperCase();
  }
  return wrap;
}
function buildMessageDom(m, showAvatar){
  const row = document.createElement('div');
  row.className = 'msgRow' + ((m.user_id === MY_ID) ? ' mine' : '');
  row.dataset.id = m.id;
  row.dataset.userId = m.user_id || '';
  row.dataset.username = m.username || '';
  row.dataset.excerpt = (m.message || '').slice(0, 180);
  row.dataset.message = m.message || '';

  const bubble = document.createElement('div');
  bubble.className = 'msgBubble';

  if (m.reply_to_username || m.reply_to_excerpt) {
    const sn = document.createElement('div');
    sn.className = 'replySnippet';
    const ru = document.createElement('div'); ru.className = 'ruser'; ru.textContent = m.reply_to_username || '…';
    const rx = document.createElement('div'); rx.textContent = m.reply_to_excerpt || '';
    sn.appendChild(ru); sn.appendChild(rx);
    bubble.appendChild(sn);
  }

  if (m.deleted_at) {
    const del = document.createElement('div');
    del.textContent = 'Message removed';
    del.style.opacity = '.6';
    del.style.fontStyle = 'italic';
    bubble.appendChild(del);
  } else {
    if (showAvatar) {
      const mu = document.createElement('div');
      mu.className = 'msgUser';
      mu.textContent = m.username || '…';
      bubble.appendChild(mu);
    }
    const txt = document.createElement('div');
    txt.className = 'msgText';
    txt.innerHTML = renderMessageText(m.message || '');
    bubble.appendChild(txt);
  }

  const meta = document.createElement('div');
  meta.className = 'msgMeta';
  meta.textContent = (m.edited_at ? 'edited • ' : '') + (relativeTime(m.created_at) || '');
  bubble.appendChild(meta);

  row.appendChild(bubble);
  if (showAvatar) {
    const av = cleanRoomAvatar(m);
    if (m.user_id === MY_ID) row.insertBefore(av, bubble);
    else row.appendChild(av);
  } else {
    const spacer = document.createElement('div');
    spacer.className = 'msgAvatar hidden';
    if (m.user_id === MY_ID) row.insertBefore(spacer, bubble); else row.appendChild(spacer);
  }

  attachGestures(row, bubble, m);
  return row;
}
function attachGestures(row, bubble, m){
  let startX = 0, startY = 0, lastX = 0, lastY = 0, active = false, longPressTimer = null, moved = false;
  const isMine = (m.user_id === MY_ID);
  const cleanup = ()=> {
    bubble.style.transform = '';
    bubble.style.background = '';
    if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }
    active = false;
    moved = false;
  };
  row.addEventListener('pointerdown', (ev)=> {
    if (ev.pointerType === 'mouse' && ev.button !== 0) return;
    startX = ev.clientX; startY = ev.clientY; lastX = startX; lastY = startY; active = true; moved = false;
    try { row.setPointerCapture(ev.pointerId); } catch(e){}
    longPressTimer = setTimeout(()=> {
      if (isMine) editMessageById(row.dataset.id);
      cleanup();
    }, 550);
  });
  row.addEventListener('pointermove', (ev)=> {
    if (!active) return;
    lastX = ev.clientX; lastY = ev.clientY;
    const dx = lastX - startX;
    const dy = lastY - startY;
    if (Math.abs(dx) > 8 || Math.abs(dy) > 8) moved = true;
    if (Math.abs(dx) > Math.abs(dy)) {
      const limited = Math.max(-8, Math.min(40, dx * 0.20));
      bubble.style.transform = `translateX(${limited}px)`;
      if (dx > 30) bubble.style.background = 'rgba(var(--accent-rgb), .14)';
    }
    if (Math.abs(dx) > 14 || Math.abs(dy) > 14) {
      if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }
    }
  });
  row.addEventListener('pointerup', (ev)=> {
    if (!active) return;
    const dx = ev.clientX - startX;
    const dy = ev.clientY - startY;
    cleanup();
    if (Math.abs(dx) > 68 && Math.abs(dx) > Math.abs(dy) * 1.1) {
      setReplyFromRow(row);
    }
  });
  row.addEventListener('pointercancel', cleanup);
  row.addEventListener('pointerleave', ()=> { if (!active) cleanup(); });
}
function setReplyFromRow(row){
  replyingTo = { id: row.dataset.id, username: row.dataset.username || '', excerpt: row.dataset.excerpt || '' };
  rpUser.textContent = replyingTo.username || '…';
  rpText.textContent = replyingTo.excerpt || '';
  rpPreview.style.display = 'flex';
  msgEl.focus();
}
function clearReply(){
  replyingTo = null;
  rpPreview.style.display = 'none';
  rpUser.textContent = '';
  rpText.textContent = '';
}
rpCancel.addEventListener('click', clearReply);

async function editMessageById(id){
  const current = messagesById.get(String(id));
  const orig = current ? (current.message || '') : '';
  const next = prompt('Edit message', orig);
  if (next === null) return;
  if (Array.from(next).length > MAX_MESSAGE_LENGTH) return alert('Message too long');
  const fd = new FormData(); fd.append('id', id); fd.append('message', next);
  const res = await fetch('message_interface.php?user=' + encodeURIComponent(TARGET) + '&mode=edit', { method:'POST', body: fd, credentials:'same-origin' });
  const j = await res.json().catch(()=>null);
  if (j && j.ok) await pollOnce(); else alert(j && j.error ? j.error : 'Edit failed');
}
async function send(){
  const text = msgEl.value.trim();
  if (!text) return;
  if (Array.from(text).length > MAX_MESSAGE_LENGTH) return alert('Message too long');
  if (relationship && relationship.allowed === false) return alert("You can't message this user yet.");
  const fd = new FormData(); fd.append('message', text);
  if (replyingTo && replyingTo.id) fd.append('reply_to', replyingTo.id);
  await fetch('message_interface.php?user=' + encodeURIComponent(TARGET) + '&mode=send', { method:'POST', body: fd, credentials:'same-origin' });
  msgEl.value = ''; charCount.textContent = '0/750'; clearReply();
  await pollOnce();
}
async function uploadAndSendImage(file){
  try {
    if (!file) return;
    if (file.size > 6 * 1024 * 1024) return alert('Image too large');
    const allowed = ['image/png','image/jpeg','image/webp','image/gif'];
    if (!allowed.includes(file.type)) return alert('Unsupported image type');
    if (relationship && relationship.allowed === false) return alert("You can't message this user yet.");
    const fd = new FormData();
    fd.append('image', file);
    const resp = await fetch(UPLOAD_API, { method:'POST', body: fd, credentials:'same-origin' });
    const j = await resp.json().catch(()=>null);
    if (!j || !j.ok || !j.url) return alert('Upload failed');
    const body = new FormData();
    body.append('message', `![image](${j.url})`);
    if (replyingTo && replyingTo.id) body.append('reply_to', replyingTo.id);
    await fetch('message_interface.php?user=' + encodeURIComponent(TARGET) + '&mode=send', { method:'POST', body, credentials:'same-origin' });
    clearReply();
    await pollOnce();
  } catch (e) {
    console.error('uploadAndSendImage', e);
    alert('Upload failed');
  }
}

sendBtn.addEventListener('click', send);
msgEl.addEventListener('keydown', (e)=> { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } });
msgEl.addEventListener('input', ()=> {
  charCount.textContent = Array.from(msgEl.value).length + '/750';
  const now = Date.now();
  if (now - lastTypingAt > 850) {
    lastTypingAt = now;
    navigator.sendBeacon ? navigator.sendBeacon('message_interface.php?user=' + encodeURIComponent(TARGET) + '&mode=typing') : fetch('message_interface.php?user=' + encodeURIComponent(TARGET) + '&mode=typing', { method:'POST', keepalive:true, credentials:'same-origin' }).catch(()=>{});
  }
});
IMAGE_BTN.addEventListener('click', ()=> IMAGE_INPUT.click());
IMAGE_INPUT.addEventListener('change', async (e)=> {
  const file = e.target.files && e.target.files[0] ? e.target.files[0] : null;
  if (!file) return;
  await uploadAndSendImage(file);
  IMAGE_INPUT.value = '';
});

function appendMessages(messages){
  if (!Array.isArray(messages) || messages.length === 0) return;
  const nearBottom = (chatEl.scrollHeight - (chatEl.scrollTop + chatEl.clientHeight)) < 180;
  let appended = false;
  let lastUser = null;
  let lastTs = 0;
  for (const m of messages) {
    if (!m || !m.id || Number(m.id) <= Number(lastId)) continue;
    messagesById.set(String(m.id), m);
    const ts = parseTS(m.created_at);
    const tsMs = ts ? ts.getTime() : Date.now();
    const gap = tsMs - lastTs;
    const showAvatar = (m.user_id !== lastUser) || (gap > 2 * 60 * 1000) || !!m.deleted_at;
    const row = buildMessageDom(m, showAvatar);
    chatEl.appendChild(row);
    appended = true;
    lastId = Math.max(lastId, Number(m.id) || 0);
    lastUser = m.user_id;
    lastTs = tsMs;
  }
  if (appended && nearBottom) chatEl.scrollTop = chatEl.scrollHeight;
}
function updateTyping(list){
  const names = Array.isArray(list) ? list.filter(Boolean) : [];
  if (names.length === 0) { typingBar.textContent = ''; return; }
  typingBar.textContent = names.length === 1 ? `${names[0]} is typing…` : names.slice(0,3).join(', ') + ' are typing…';
}

function makeRoom(userName){
  return voiceRoomForPair(MY_USERNAME, (userName || TARGET || '').trim());
}
async function openVoice(userName){
  const who = (userName || TARGET || '').trim();
  const room = makeRoom(who);
  VOICE_TITLE.textContent = 'Voice with ' + who;
  VOICE_FRAME.src = 'message_voice.php?room=' + encodeURIComponent(room) + '&embed=1&user=' + encodeURIComponent(who);
  VOICE_MODAL.classList.add('open');
  VOICE_MODAL.setAttribute('aria-hidden', 'false');
  voiceStatus.textContent = 'Connecting to call…';
}
async function closeVoice(){
  VOICE_MODAL.classList.remove('open');
  VOICE_MODAL.setAttribute('aria-hidden', 'true');
  VOICE_FRAME.src = 'about:blank';
  try {
    await fetch('message_interface.php?user=' + encodeURIComponent(TARGET) + '&mode=voice_call_end', {
      method: 'POST',
      credentials: 'same-origin'
    });
  } catch (e) {}
}
async function startVoiceCall(){
  await openVoice(TARGET);
  try {
    const r = await fetch('message_interface.php?user=' + encodeURIComponent(TARGET) + '&mode=voice_call_invite', {
      method:'POST',
      credentials:'same-origin'
    });
    const j = await r.json().catch(()=>null);
    if (j && j.ok) {
      lastCallId = Number(j.call_id || lastCallId || 0);
      lastCallStatus = 'ringing';
    }
  } catch (e) {}
}

let lastCallId = 0;
let lastCallStatus = '';

voiceBtn.addEventListener('click', startVoiceCall);
voiceBtn2.addEventListener('click', startVoiceCall);
voiceCloseBtn.addEventListener('click', closeVoice);
voiceLeaveBtn.addEventListener('click', closeVoice);
window.addEventListener('message', (ev)=> {
  const d = ev.data || {};
  if (d && d.type === 'voice-status') voiceStatus.textContent = d.text || 'In call';
  if (d && d.type === 'close-message-voice') closeVoice();
});

INCOMING_ACCEPT.addEventListener('click', async ()=> {
  const caller = pendingVoiceCaller || TARGET;
  try {
    await fetch('message_interface.php?user=' + encodeURIComponent(caller) + '&mode=voice_call_accept', {
      method: 'POST',
      credentials: 'same-origin'
    });
  } catch (e) {}
  hideIncomingCall();
  await openVoice(caller);
  lastCallStatus = 'accepted';
});
INCOMING_DISMISS.addEventListener('click', async ()=> {
  const caller = pendingVoiceCaller || TARGET;
  try {
    await fetch('message_interface.php?user=' + encodeURIComponent(caller) + '&mode=voice_call_dismiss', {
      method: 'POST',
      credentials: 'same-origin'
    });
  } catch (e) {}
  hideIncomingCall();
  lastCallStatus = 'declined';
});
INCOMING_CALL.addEventListener('click', (e)=> { if (e.target === INCOMING_CALL) INCOMING_DISMISS.click(); });

function renderCallState(call){
  if (!call) return;
  lastCallId = Math.max(lastCallId, Number(call.id) || 0);
  lastCallStatus = String(call.status || lastCallStatus || '');
  if (String(call.status) === 'ringing' && Number(call.callee_id) === Number(MY_ID)) {
    if (String(activeIncomingVoice || '') !== String(call.id)) {
      showIncomingCall(call.caller_username || TARGET, call.caller_avatar || null, 'Tap accept to join this voice call.', call.id);
    }
  } else if (activeIncomingVoice && String(activeIncomingVoice) === String(call.id) && String(call.status) !== 'ringing') {
    hideIncomingCall();
  }
}

async function loadOnce(){
  const r = await apiGet({});
  if (r.error) {
    chatEl.innerHTML = '<div class="emptyState">' + escapeHtml(r.error) + '</div>';
    return;
  }
  if (r.target) currentUser = r.target;
  if (r.relationship) relationship = r.relationship;
  updateRelationshipUI();
  renderProfileSection(r.target || { username: TARGET }, relationship);
  renderYourFriends(r.friends || []);
  if (r.typing) updateTyping(r.typing);
  chatEl.innerHTML = '';
  messagesById.clear();
  lastId = 0;
  appendMessages(r.messages || []);
  if (r.messages && r.messages.length === 0) chatEl.innerHTML = '<div class="emptyState">Say hello 👋</div>';
  if (r.call) renderCallState(r.call);
}

async function pollOnce(){
  if (inFlight) return;
  inFlight = true;
  try {
    const r = await fetch('message_interface.php?user=' + encodeURIComponent(TARGET) + '&since=' + encodeURIComponent(lastId) + '&call_since=' + encodeURIComponent(lastCallId) + '&call_status=' + encodeURIComponent(lastCallStatus), { credentials:'same-origin' });
    if (!r.ok) return;
    const j = await r.json();
    if (j.target) { currentUser = j.target; renderProfileSection(j.target, relationship); }
    if (j.relationship) { relationship = j.relationship; updateRelationshipUI(); renderProfileSection(j.target || currentUser || { username: TARGET }, relationship); }
    if (Array.isArray(j.messages) && j.messages.length) {
      const other = j.messages.some(m => Number(m.user_id) !== Number(MY_ID));
      appendMessages(j.messages);
      if (other && audioUnlocked) { try { const a = document.getElementById('bell'); a.currentTime = 0; a.play().catch(()=>{}); } catch(e){} }
    }
    if (j.typing) updateTyping(j.typing);
    if (Array.isArray(j.friends)) renderYourFriends(j.friends);
    if (j.call) renderCallState(j.call);
  } catch(e) {
    console.error('pollOnce', e);
  }
  inFlight = false;
}
async function longPollLoop(){
  while (running) {
    try { await pollOnce(); await new Promise(r => setTimeout(r, 750)); }
    catch(e){ await new Promise(r => setTimeout(r, 2000)); }
  }
}

friendBtn.addEventListener('click', async ()=> {
  let action = 'request_friend';
  const txt = (friendBtn.textContent || '').toLowerCase();
  if (txt.includes('remove')) action = 'remove_friend';
  else if (txt.includes('cancel')) action = 'cancel_request';
  else if (txt.includes('accept')) action = (relationship.requested_kind === 'acquaintance') ? 'accept_acquaintance' : 'accept_friend';
  const fd = new FormData(); fd.append('action', action);
  const r = await fetch('message_interface.php?user=' + encodeURIComponent(TARGET) + '&mode=friend_action', { method:'POST', body: fd, credentials:'same-origin' });
  const j = await r.json().catch(()=>null);
  if (j && j.ok) await loadOnce(); else { alert(j && j.error ? j.error : 'Action failed'); await loadOnce(); }
});
blockBtn.addEventListener('click', async ()=> {
  const action = blockBtn.dataset.blocked === '1' ? 'unblock' : 'block';
  if (action === 'block' && !confirm('Block this user?')) return;
  const fd = new FormData(); fd.append('action', action);
  const r = await fetch('message_interface.php?user=' + encodeURIComponent(TARGET) + '&mode=block_action', { method:'POST', body: fd, credentials:'same-origin' });
  const j = await r.json().catch(()=>null);
  if (j && j.ok) await loadOnce(); else alert(j && j.error ? j.error : 'Failed');
});

function fetchNotifications(limit=50){
  return fetch(NOTIF_API + '?limit=' + encodeURIComponent(limit), { credentials:'same-origin' })
    .then(r => r.ok ? r.json() : ({ notifications:[], unread_count:0 }))
    .catch(()=>({ notifications:[], unread_count:0 }));
}
async function refreshNotifBadge(){
  const j = await fetchNotifications(5);
  const unread = j.unread_count || 0;
  notifBadge.style.display = unread > 0 ? 'inline-block' : 'none';
  notifBadge.textContent = unread > 99 ? '99+' : String(unread);
  lastUnread = unread;
}
function renderNotifRow(n){
  const row = document.createElement('div');
  row.className = 'notifRow';
  const extra = isVoiceCallNotification(n) ? ' · voice call' : '';
  row.innerHTML = `<div class="notifTitle">${escapeHtml(n.source_username||'System')}${extra}</div><div class="notifMsg">${escapeHtml((n.message||'').slice(0,140))}</div>`;
  row.addEventListener('click', async ()=> {
    try {
      await fetch(NOTIF_API, {
        method:'POST',
        credentials:'same-origin',
        body: new URLSearchParams({ action:'mark_read', id: n.id })
      });
    } catch(e){}
    notifDropdown.style.display = 'none';
    const refCode = n.ref_code || n.ref || n.code || null;
    if (refCode) { location.href = 'mobile_private.php?code=' + encodeURIComponent(refCode); return; }
    if (n.type && String(n.type).indexOf('dm') !== -1 && n.source_username) { location.href = 'mobile_message.php?user=' + encodeURIComponent(n.source_username); return; }
    if (n.ref_id) { location.href = 'mobile_message.php?user=' + encodeURIComponent(n.source_username || TARGET); return; }
  });
  return row;
}
async function loadNotifs(){
  const j = await fetchNotifications(200);
  const unread = j.unread_count || 0;
  if (unread > lastUnread && lastUnread !== 0 && audioUnlocked) {
    try { const a = document.getElementById('bell'); a.currentTime = 0; a.play().catch(()=>{}); } catch(e){}
  }
  lastUnread = unread;
  notifBadge.style.display = unread > 0 ? 'inline-block' : 'none';
  notifBadge.textContent = unread > 99 ? '99+' : String(unread);
  notifDropdown.innerHTML = '';
  const rows = Array.isArray(j.notifications) ? j.notifications : [];
  if (rows.length === 0) { notifDropdown.innerHTML = '<div class="emptyState">No notifications</div>'; return; }
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

friendsBtn.addEventListener('click', ()=> openSheet('friends'));
sheetOpenBtn.addEventListener('click', ()=> openSheet('profile'));
closeSheetBtn.addEventListener('click', closeSheet);
SHEET_OVERLAY.addEventListener('click', closeSheet);
tabProfile.addEventListener('click', ()=> setSheet('profile'));
tabFriends.addEventListener('click', ()=> setSheet('friends'));

document.getElementById('backBtn').addEventListener('click', ()=> { location.href = 'mobile_room.php'; });
window.addEventListener('keydown', (e)=> { if (e.key === 'Escape') { closeSheet(); closeVoice(); hideIncomingCall(); } });
window.addEventListener('beforeunload', ()=> running = false);

loadOnce().then(()=> {
  refreshNotifBadge();
  longPollLoop();
}).catch((e)=> {
  console.error('startup error', e);
  chatEl.innerHTML = '<div class="emptyState">Load error</div>';
});

setInterval(()=> { if (!document.hidden) loadNotifs(); }, POLL_INTERVAL);
</script>
</body>
</html>
