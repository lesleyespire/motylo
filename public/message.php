<?php
// message.php - Direct message UI (desktop) with incoming call support
require "config.php";

if (empty($_COOKIE['auth_token'])) { header("Location:index.php"); exit; }
$stmt = $pdo->prepare("SELECT id, username, avatar FROM users WHERE auth_token = ?");
$stmt->execute([$_COOKIE['auth_token']]);
$me = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$me) { header("Location:index.php"); exit; }

$me_username = $me['username'];
$me_avatar = $me['avatar'] ?? null;
$me_id = (int)$me['id'];

$target = trim((string)($_GET['user'] ?? ''));
if ($target === '') { die("Missing target user. Use message.php?user=theirusername"); }

function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Message — <?= e($target) ?></title>
<link rel="icon" href="root/favicon.ico">
<style>
:root{
  --bg:#090b10;
  --panel:#10131a;
  --panel2:#0d1118;
  --muted:#aab4c5;
  --text:#eef4ff;
  --accent:#5865F2;
  --accent-rgb:88,101,242;
  --accent-soft:rgba(88,101,242,.14);
  --mine:#234f85;
  --card:rgba(255,255,255,.03);
  --card2:rgba(255,255,255,.05);
  --border:rgba(255,255,255,.05);
}
*{box-sizing:border-box}
html,body{height:100%;margin:0}
body{
  color:var(--text);
  font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,Arial;
  -webkit-font-smoothing:antialiased;
  background:
    radial-gradient(circle at 20% 0%, rgba(var(--accent-rgb), .16), transparent 34%),
    radial-gradient(circle at 80% 10%, rgba(255,255,255,.05), transparent 28%),
    linear-gradient(180deg, #07090d, #090b10 34%, #07090d 100%);
  overflow:hidden;
}
button,input,textarea{font:inherit}
a{color:inherit}
.topbar{
  position:sticky;top:0;z-index:40;
  display:flex;justify-content:space-between;align-items:center;gap:12px;
  padding:12px 16px;
  background:linear-gradient(180deg, rgba(16,19,26,.98), rgba(16,19,26,.88));
  backdrop-filter:blur(18px);
  border-bottom:1px solid var(--border);
}
.topbar .btn{
  background:linear-gradient(135deg, rgba(var(--accent-rgb), .95), rgba(var(--accent-rgb), .72));
  border:0;color:#fff;padding:9px 12px;border-radius:12px;cursor:pointer;font-weight:800;
  box-shadow:0 10px 24px rgba(var(--accent-rgb), .18);
}
.topbar .ghost{
  background:rgba(255,255,255,.04);
  color:#fff;
  border:1px solid rgba(255,255,255,.04);
  box-shadow:none;
}
.topbar .topLeft{display:flex;gap:10px;align-items:center;min-width:0}
.topbar .titleWrap{min-width:0;display:flex;flex-direction:column;gap:2px}
.topbar .pageTitle{font-weight:900;font-size:16px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:50vw}
.topbar .pageSub{color:var(--muted);font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:50vw}
.topbar .right{display:flex;gap:10px;align-items:center;position:relative}

.layout{
  height:calc(100vh - 58px);
  display:grid;
  grid-template-columns: 340px minmax(0,1fr);
  gap:14px;
  padding:14px;
}

.sidebar{
  display:flex;flex-direction:column;gap:14px;
  min-height:0;
  background:linear-gradient(180deg, rgba(16,19,26,.92), rgba(12,14,20,.92));
  border:1px solid var(--border);
  border-radius:22px;
  padding:14px;
  box-shadow:0 24px 64px rgba(0,0,0,.32);
  overflow:hidden;
}
.sidebar .section{
  background:rgba(255,255,255,.02);
  border:1px solid rgba(255,255,255,.04);
  border-radius:18px;
  padding:12px;
}
.sidebar .sectionTitle{
  font-size:12px;
  letter-spacing:.08em;
  text-transform:uppercase;
  color:var(--muted);
  margin-bottom:10px;
}
.headerRow{display:flex;gap:12px;align-items:center}
.pfp{
  width:76px;height:76px;border-radius:18px;overflow:hidden;
  background:linear-gradient(135deg, rgba(var(--accent-rgb), .22), rgba(255,255,255,.03));
  display:flex;align-items:center;justify-content:center;
  border:1px solid rgba(255,255,255,.06);
  color:#fff;font-weight:800;font-size:26px;flex:0 0 76px;
}
.pfp img{width:100%;height:100%;object-fit:cover;display:block}
.usernameTitle{font-weight:900;font-size:18px;line-height:1.1}
.bio{color:var(--muted);font-size:13px;margin-top:6px;line-height:1.45;white-space:normal;word-break:break-word}
.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.smallBtn{
  background:rgba(255,255,255,.04);
  border:1px solid rgba(255,255,255,.05);
  color:#fff;padding:9px 11px;border-radius:12px;cursor:pointer;
  transition:transform .12s ease, background .12s ease, border-color .12s ease;
}
.smallBtn:hover{transform:translateY(-1px);background:rgba(255,255,255,.06)}
.smallBtn.primary{
  background:linear-gradient(135deg, rgba(var(--accent-rgb), .95), rgba(var(--accent-rgb), .72));
  border:none;
}
.smallBtn.warn{background:linear-gradient(135deg,#b54b4b,#d46a6a);border:none}
.divider{height:1px;background:rgba(255,255,255,.06);margin:2px 0}
.listBox{
  display:flex;flex-direction:column;gap:8px;
  overflow:auto;
}
.listBox.small{max-height:180px}
.listBox.large{max-height:260px}
.friendCard{
  display:flex;gap:10px;align-items:center;
  padding:9px 10px;border-radius:14px;
  background:rgba(255,255,255,.03);
  border:1px solid rgba(255,255,255,.04);
  cursor:pointer;
}
.friendCard .avatar{
  width:44px;height:44px;border-radius:14px;overflow:hidden;
  background:#1d2230;flex:0 0 44px;display:flex;align-items:center;justify-content:center;
  font-weight:800;border:1px solid rgba(255,255,255,.04)
}
.friendCard .avatar img{width:100%;height:100%;object-fit:cover;display:block}
.friendCard .name{font-weight:850}
.friendCard .sub{color:var(--muted);font-size:12px;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

.main{
  min-height:0;
  display:flex;flex-direction:column;
  background:linear-gradient(180deg, rgba(16,19,26,.70), rgba(10,12,18,.88));
  border:1px solid var(--border);
  border-radius:22px;
  box-shadow:0 24px 64px rgba(0,0,0,.30);
  overflow:hidden;
}
.chatHeader{
  padding:14px 16px;
  display:flex;justify-content:space-between;align-items:center;gap:10px;
  background:linear-gradient(135deg, rgba(var(--accent-rgb), .12), rgba(255,255,255,.03));
  border-bottom:1px solid rgba(255,255,255,.05);
}
.chatHeader .who{min-width:0;display:flex;gap:12px;align-items:center}
.chatHeader .avatar{
  width:54px;height:54px;border-radius:16px;overflow:hidden;
  background:linear-gradient(135deg, rgba(var(--accent-rgb), .24), rgba(255,255,255,.03));
  display:flex;align-items:center;justify-content:center;
  font-weight:800;border:1px solid rgba(255,255,255,.06);flex:0 0 54px
}
.chatHeader .avatar img{width:100%;height:100%;object-fit:cover;display:block}
.chatHeader .name{font-weight:900;font-size:18px;line-height:1.1}
.chatHeader .meta{color:var(--muted);font-size:12px;margin-top:4px}
.headerBtns{display:flex;gap:8px;align-items:center}
.headerBtns .pill{
  background:rgba(255,255,255,.04);
  border:1px solid rgba(255,255,255,.05);
  color:#fff;padding:10px 12px;border-radius:12px;cursor:pointer;font-weight:800
}
.headerBtns .pill.primary{
  background:linear-gradient(135deg, rgba(var(--accent-rgb), .95), rgba(var(--accent-rgb), .72));
  border:none;
}
.typingBar{padding:8px 16px;color:var(--muted);font-size:13px;min-height:24px}

.chatWindow{
  flex:1;min-height:0;overflow:auto;
  padding:14px 16px 18px;
  scroll-behavior:smooth;
  background:
    radial-gradient(circle at top, rgba(var(--accent-rgb), .06), transparent 50%),
    linear-gradient(180deg, rgba(255,255,255,.01), transparent);
}
.emptyState{color:var(--muted);text-align:center;padding:24px 12px}
.msgRow{display:flex;align-items:flex-end;gap:10px;margin-bottom:12px;position:relative}
.msgRow.mine{justify-content:flex-end}
.msgAvatar{
  width:42px;height:42px;border-radius:14px;overflow:hidden;flex:0 0 42px;
  background:#1d2230;display:flex;align-items:center;justify-content:center;
  border:1px solid rgba(255,255,255,.05)
}
.msgAvatar img{width:100%;height:100%;object-fit:cover;display:block}
.msgAvatar.hidden{visibility:hidden}
.msgBubble{
  background:linear-gradient(180deg, rgba(19,22,30,.98), rgba(14,17,24,.98));
  padding:12px 13px 11px;border-radius:18px;max-width:min(72%, 780px);
  box-sizing:border-box;word-break:break-word;position:relative;
  border:1px solid rgba(255,255,255,.05);
  box-shadow:0 10px 24px rgba(0,0,0,.20);
  transition:transform .12s ease, background .12s ease, box-shadow .12s ease;
}
.msgRow.mine .msgBubble{
  background:linear-gradient(180deg, rgba(var(--accent-rgb), .96), rgba(var(--accent-rgb), .72));
  color:#fff;
  border:none;
  box-shadow:0 14px 28px rgba(var(--accent-rgb), .22);
}
.msgBubble.replying{box-shadow:0 0 0 2px rgba(var(--accent-rgb), .30), 0 10px 28px rgba(0,0,0,.22)}
.msgUser{font-weight:850;font-size:13px;color:#f2f6ff;margin-bottom:6px}
.msgText{font-size:15px;line-height:1.5;white-space:pre-wrap;word-break:break-word}
.msgText a{color:#9ec4ff;text-decoration:underline}
.msgRow.mine .msgText a{color:#fff;text-decoration:underline}
.msgText img{max-width:100%;height:auto;border-radius:12px;display:block;margin-top:6px}
.msgMeta{margin-top:6px;font-size:11px;color:var(--muted);text-align:right}
.msgRow.mine .msgMeta{color:rgba(255,255,255,.80)}
.replySnippet{
  border-left:3px solid rgba(var(--accent-rgb), .40);
  padding:7px 8px;margin-bottom:8px;color:#d4dcf1;font-size:13px;border-radius:10px;
  background:rgba(255,255,255,.03)
}
.replySnippet .ruser{font-weight:800;margin-bottom:3px}
.bigEmoji{font-size:1.95em;line-height:1;vertical-align:-0.12em;display:inline-block}

.composerWrap{
  border-top:1px solid rgba(255,255,255,.05);
  background:linear-gradient(180deg, rgba(255,255,255,.01), rgba(255,255,255,.02));
  padding:10px 14px 14px;
}
.replyPreview{
  display:none;align-items:center;gap:8px;
  margin:0 0 10px;padding:10px 12px;border-radius:16px;
  background:rgba(var(--accent-rgb), .10);
  border:1px solid rgba(var(--accent-rgb), .16);
  color:var(--text)
}
.replyPreview .rpText{opacity:.92;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.replyPreview .rpCancel{margin-left:auto;background:transparent;border:0;color:#ff8f8f;font-size:18px;cursor:pointer}
.composer{
  display:flex;align-items:flex-end;gap:8px;
  padding:10px;border-radius:20px;
  background:linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,.02));
  border:1px solid rgba(255,255,255,.05);
  box-shadow:0 16px 36px rgba(0,0,0,.20)
}
#msg{
  flex:1;min-height:46px;max-height:140px;resize:none;
  padding:12px 12px;border-radius:14px;border:0;
  background:#0d1118;color:#fff;font-size:16px;outline:none;
}
#msg::placeholder{color:#77839a}
.sendBtn{
  background:linear-gradient(135deg, rgba(var(--accent-rgb), .98), rgba(var(--accent-rgb), .72));
  padding:12px 14px;font-weight:900;min-height:46px;border:0;border-radius:14px;color:white;cursor:pointer;
  box-shadow:0 12px 28px rgba(var(--accent-rgb), .20)
}
.smallBtn{
  background:rgba(255,255,255,.04);padding:12px 12px;min-height:46px;min-width:46px;
  font-size:18px;border:1px solid rgba(255,255,255,.05);border-radius:14px;color:#fff;cursor:pointer
}
.charCount{font-size:12px;color:var(--muted);min-width:46px;text-align:right;padding-bottom:6px}

/* bell + dropdown */
.bell{
  position:relative;cursor:pointer;padding:8px 10px;border-radius:12px;
  background:rgba(255,255,255,0.03);display:inline-flex;align-items:center;gap:6px;
  border:1px solid rgba(255,255,255,.05);
}
.badge{
  position:absolute;top:-6px;right:-6px;background:#ff4d4f;color:white;border-radius:999px;
  padding:2px 7px;font-size:12px;min-width:24px;text-align:center
}
.notifBox{
  position:absolute; right:0; top:52px;
  background:#0b1114; border-radius:14px; padding:12px;
  min-width:320px; max-width:420px; box-shadow:0 18px 50px rgba(0,0,0,.6);
  display:none; z-index:1000; border:1px solid rgba(255,255,255,.05);
}
.notifGroup{display:flex;gap:10px;align-items:center;padding:8px;border-radius:10px;cursor:pointer;position:relative}
.notifGroup:hover{background:rgba(255,255,255,.03)}
.notifGroup .avatar{
  width:44px;height:44px;border-radius:12px;background:#222;display:flex;align-items:center;justify-content:center;color:#fff;flex:0 0 44px;overflow:hidden
}
.notifGroup .avatar img{width:100%;height:100%;object-fit:cover;display:block}
.notifGroup .meta{flex:1;min-width:0}
.notifGroup .meta .title{font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.notifGroup .meta .msg{color:var(--muted);font-size:13px;margin-top:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.unreadDot{width:10px;height:10px;border-radius:999px;background:#ff4d4f;flex:0 0 10px}
.notifCount{color:var(--muted);font-size:12px}
.notifTime{color:var(--muted);font-size:11px}

/* voice modal */
.voiceModal{
  position:fixed;inset:0;background:rgba(4,6,10,.86);z-index:90;display:none;align-items:stretch;justify-content:stretch
}
.voiceModal.open{display:flex}
.voiceShell{flex:1;display:flex;flex-direction:column;background:linear-gradient(180deg, rgba(16,19,26,.98), rgba(11,14,20,.98))}
.voiceTop{
  display:flex;align-items:center;gap:10px;
  padding:12px 12px calc(12px + env(safe-area-inset-top));
  border-bottom:1px solid rgba(255,255,255,.04)
}
.voiceTop .info{min-width:0;flex:1}
.voiceTop .info strong{display:block;font-size:16px}
.voiceTop .info span{color:var(--muted);font-size:12px}
.voiceTop .voiceClose{
  width:42px;height:42px;border-radius:14px;border:0;
  background:rgba(255,255,255,.04);color:var(--text);font-size:18px;cursor:pointer
}
.voiceFrame{flex:1;min-height:0;border:0;width:100%;background:#0b0f12}

/* incoming call */
.incomingCall{
  position:fixed;inset:0;z-index:9999;display:none;align-items:center;justify-content:center;
  background:radial-gradient(circle at center, rgba(var(--accent-rgb), .18), rgba(4,6,10,.88) 55%, rgba(0,0,0,.96) 100%);
  backdrop-filter:blur(12px);
  animation:callBackdropIn .18s ease-out
}
.incomingCall::before{
  content:'';position:absolute;inset:0;
  background:linear-gradient(180deg, rgba(var(--accent-rgb), .12), transparent 35%, rgba(255,77,79,.08));
  pointer-events:none
}
.incomingCard{
  position:relative;width:min(94vw, 460px);
  border-radius:28px;padding:20px 18px 18px;
  background:linear-gradient(180deg, rgba(20,24,34,.99), rgba(10,13,20,.99));
  border:1px solid rgba(255,255,255,.08);
  box-shadow:0 28px 100px rgba(0,0,0,.68);
  transform:translateY(0) scale(1);
  animation:callCardPop .18s ease-out
}
.incomingCard::after{
  content:'';position:absolute;inset:-8px;border-radius:34px;
  border:1px solid rgba(var(--accent-rgb), .22);
  box-shadow:0 0 0 8px rgba(var(--accent-rgb), .08);
  pointer-events:none;animation:callPulse 1.15s ease-out infinite
}
.incomingHead{display:flex;gap:14px;align-items:center}
.incomingAvatar{
  width:68px;height:68px;border-radius:22px;overflow:hidden;display:flex;align-items:center;justify-content:center;
  background:linear-gradient(135deg,#232a3d,#151a26);
  font-weight:900;font-size:26px;flex:0 0 68px;border:1px solid rgba(255,255,255,.06);
  box-shadow:0 0 0 10px rgba(var(--accent-rgb), .10)
}
.incomingAvatar img{width:100%;height:100%;object-fit:cover;display:block}
.incomingTitle{font-weight:950;font-size:22px;line-height:1.1;letter-spacing:.01em}
.incomingSub{margin-top:6px;color:#e6ecfb;font-size:15px;line-height:1.45}
.incomingActions{display:flex;gap:10px;margin-top:18px}
.incomingActions .pillBtn{
  flex:1;padding:14px 14px;font-weight:950;min-height:50px;border:0;border-radius:14px;color:var(--text);cursor:pointer
}
.incomingActions .pillBtn.primary{
  background:linear-gradient(135deg, rgba(var(--accent-rgb), .98), rgba(var(--accent-rgb), .72));
  box-shadow:0 12px 30px rgba(var(--accent-rgb), .28)
}
.incomingActions .pillBtn.ghost{background:rgba(255,255,255,.06)}
.incomingHint{margin-top:12px;color:var(--muted);font-size:12px;text-align:center;line-height:1.4}
@keyframes callBackdropIn{from{opacity:0}to{opacity:1}}
@keyframes callCardPop{from{transform:translateY(12px) scale(.96);opacity:.2}to{transform:translateY(0) scale(1);opacity:1}}
@keyframes callPulse{0%{transform:scale(1);opacity:.8}100%{transform:scale(1.08);opacity:0}}

@media(max-width: 980px){
  body{overflow:auto}
  .layout{grid-template-columns:1fr; height:auto; min-height:calc(100vh - 58px);}
  .sidebar{order:2}
  .main{order:1; min-height:72vh}
  .chatWindow{min-height:52vh}
}
</style>
</head>
<body>

<div class="topbar">
  <div class="topLeft">
    <button class="btn ghost" onclick="location.href='room.php'">← Back</button>
    <div class="titleWrap">
      <div class="pageTitle">Message · <?= e($target) ?></div>
      <div class="pageSub">Desktop direct messages</div>
    </div>
  </div>

  <div class="right">
    <audio id="dm_bell" preload="auto"><source src="root/bell.mp3" type="audio/mpeg"></audio>
    <audio id="notif_bell" preload="auto"><source src="root/bell_2.mp3" type="audio/mpeg"></audio>

    <button id="notifBtn" class="bell" title="Notifications">
      🔔
      <span id="notifBadge" class="badge" style="display:none">0</span>
    </button>

    <div id="notifDropdown" class="notifBox" aria-hidden="true">
      <div style="display:flex;justify-content:flex-end;margin-bottom:8px">
        <button id="markAllBtn" class="btn ghost" style="padding:8px 10px">Mark all read</button>
      </div>
      <div id="notifList" style="max-height:320px;overflow:auto">
        <div style="padding:12px;color:var(--muted)">Loading…</div>
      </div>
    </div>
  </div>
</div>

<div class="layout">
  <aside class="sidebar" id="sidebar">
    <div class="section">
      <div class="headerRow">
        <div class="pfp" id="sidePfp"></div>
        <div style="flex:1;min-width:0">
          <div class="usernameTitle" id="sideUsername"><?= e($target) ?></div>
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

    <div class="section">
      <div class="sectionTitle">Their friends</div>
      <div id="theirFriends" class="listBox small">
        <div style="color:var(--muted)">Loading…</div>
      </div>
    </div>

    <div class="section">
      <div class="sectionTitle">Your friends</div>
      <div id="yourFriends" class="listBox large">
        <div style="color:var(--muted)">Loading…</div>
      </div>
    </div>

    <div class="section">
      <button class="smallBtn warn" id="blockBtn">Block</button>
    </div>
  </aside>

  <main class="main">
    <div class="chatHeader">
      <div class="who">
        <div class="avatar" id="headerAvatar"></div>
        <div style="min-width:0">
          <div class="name" id="headerName">Loading…</div>
          <div class="meta" id="headerMeta">Loading relationship…</div>
        </div>
      </div>
      <div class="headerBtns">
        <button class="pill primary" id="voiceBtn">🎙 Voice</button>
      </div>
    </div>

    <div class="typingBar" id="typingBar"></div>
    <div class="chatWindow" id="chat" aria-live="polite">
      <div class="emptyState">Loading messages…</div>
    </div>

    <div class="composerWrap">
      <div class="replyPreview" id="replyPreview">
        <div style="font-weight:800">Replying to <span id="rpUser"></span></div>
        <div class="rpText" id="rpText"></div>
        <button class="rpCancel" id="rpCancel" title="Cancel reply">✖</button>
      </div>

      <div class="composer" id="inputArea">
        <textarea id="msg" maxlength="750" placeholder="Send a message…" rows="1"></textarea>
        <input id="imageInput" type="file" accept="image/png,image/jpeg,image/webp,image/gif" style="display:none">
        <div class="charCount" id="charCount">0/750</div>
        <button class="smallBtn" id="imageBtn" title="Attach image">📷</button>
        <button class="sendBtn" id="sendBtn">Send</button>
      </div>
    </div>
  </main>
</div>

<div class="voiceModal" id="voiceModal" aria-hidden="true">
  <div class="voiceShell">
    <div class="voiceTop">
      <button class="voiceClose" id="voiceCloseBtn">✖</button>
      <div class="info">
        <strong id="voiceTitle">Voice with <?= e($target) ?></strong>
        <span id="voiceStatus">Connecting…</span>
      </div>
      <button class="pill primary" id="voiceLeaveBtn">Leave</button>
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

<script>
const TARGET = <?= json_encode($target) ?>;
const MY_ID = <?= json_encode($me_id) ?>;
const MY_USERNAME = <?= json_encode($me_username) ?>;
const MAX_MESSAGE_LENGTH = 750;
const DM_API = 'message_interface.php?user=' + encodeURIComponent(TARGET);
const NOTIF_API = 'notifications.php';

const chatEl = document.getElementById('chat');
const msgEl = document.getElementById('msg');
const sendBtn = document.getElementById('sendBtn');
const charCount = document.getElementById('charCount');
const rpPreview = document.getElementById('replyPreview');
const rpUser = document.getElementById('rpUser');
const rpText = document.getElementById('rpText');
const rpCancel = document.getElementById('rpCancel');
const typingBar = document.getElementById('typingBar');
const inputArea = document.getElementById('inputArea');

const SHEET_OPEN = document.getElementById('voiceBtn');
const SIDEBAR = document.getElementById('sidebar');

const VOICE_MODAL = document.getElementById('voiceModal');
const VOICE_FRAME = document.getElementById('voiceFrame');
const VOICE_TITLE = document.getElementById('voiceTitle');
const VOICE_STATUS = document.getElementById('voiceStatus');

const INCOMING_CALL = document.getElementById('incomingCall');
const INCOMING_AVATAR = document.getElementById('incomingAvatar');
const INCOMING_TITLE = document.getElementById('incomingTitle');
const INCOMING_SUB = document.getElementById('incomingSub');
const INCOMING_ACCEPT = document.getElementById('incomingAcceptBtn');
const INCOMING_DISMISS = document.getElementById('incomingDismissBtn');

const notifBtn = document.getElementById('notifBtn');
const notifBadge = document.getElementById('notifBadge');
const notifDropdown = document.getElementById('notifDropdown');
const notifList = document.getElementById('notifList');
const markAllBtn = document.getElementById('markAllBtn');

const headerAvatar = document.getElementById('headerAvatar');
const headerName = document.getElementById('headerName');
const headerMeta = document.getElementById('headerMeta');
const sidePfp = document.getElementById('sidePfp');
const sideUsername = document.getElementById('sideUsername');
const sideRole = document.getElementById('sideRole');
const sideBio = document.getElementById('sideBio');

const friendBtn = document.getElementById('friendBtn');
const acquaintBtn = document.getElementById('acquaintBtn');
const blockBtn = document.getElementById('blockBtn');
const voiceBtn = document.getElementById('voiceBtn');
const voiceCloseBtn = document.getElementById('voiceCloseBtn');
const voiceLeaveBtn = document.getElementById('voiceLeaveBtn');

const theirFriendsEl = document.getElementById('theirFriends');
const yourFriendsEl = document.getElementById('yourFriends');
const IMAGE_BTN = document.getElementById('imageBtn');
const IMAGE_INPUT = document.getElementById('imageInput');

let running = true;
let inFlight = false;
let lastId = 0;
let lastCallId = 0;
let lastCallStatus = '';
let currentTarget = null;
let relationship = { status:'none', allowed:false, blocked:false, blocked_by_them:false, mutual_friends:[], mutual_friends_count:0, their_friends:[] };
let replyingTo = null;
let messagesById = new Map();
let currentVoiceCaller = '';
let activeIncomingVoice = null;
let incomingVoiceRinger = null;
let voiceOpen = false;
let audioUnlocked = false;
let lastUnread = 0;
let lastTypingAt = 0;
let markedNotifIds = new Set();

document.addEventListener('pointerdown', ()=> audioUnlocked = true, { once:true });

function esc(s){ return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }
function parseTS(ts){ const d = ts ? new Date(ts) : null; return d && !isNaN(d) ? d : null; }
function relTime(ts){ const d = parseTS(ts); if (!d) return ''; const diff = (Date.now() - d.getTime())/1000; if (diff < 5) return 'just now'; if (diff < 60) return Math.floor(diff)+'s'; if (diff < 3600) return Math.floor(diff/60)+'m'; if (diff < 86400) return Math.floor(diff/3600)+'h'; return d.toLocaleDateString(); }
function cleanText(s){ return String(s || '').replace(/\r\n?/g,'\n').replace(/\n{3,}/g,'\n\n').trim(); }
function formatBioHtml(bio){ if (!bio) return '<span style="color:var(--muted)">(no bio)</span>'; return esc(cleanText(bio)).replace(/\n/g,'<br>'); }

function hexFromRGB(r,g,b){
  return '#' + [r,g,b].map(v => Math.max(0, Math.min(255, Math.round(v))).toString(16).padStart(2,'0')).join('');
}
function applyThemeFromRGB(r,g,b){
  const rr = Math.max(0, Math.min(255, Math.round(r)));
  const gg = Math.max(0, Math.min(255, Math.round(g)));
  const bb = Math.max(0, Math.min(255, Math.round(b)));
  const root = document.documentElement.style;
  root.setProperty('--accent-rgb', `${rr},${gg},${bb}`);
  root.setProperty('--accent', hexFromRGB(rr, gg, bb));
  root.setProperty('--accent-soft', `rgba(${rr},${gg},${bb},.14)`);
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
    img.onerror = ()=>{};
    img.src = url;
  } catch (e) {}
}
function setAvatar(el, user){
  if (!el) return;
  el.innerHTML = '';
  const url = user && user.avatar ? ((String(user.avatar).indexOf('/') === 0 || String(user.avatar).startsWith('http')) ? user.avatar : 'avatars/' + encodeURIComponent(user.avatar)) : '';
  if (url) {
    const img = document.createElement('img');
    img.src = url;
    img.alt = user && user.username ? user.username : '';
    el.appendChild(img);
  } else {
    el.textContent = (user && user.username ? user.username[0] : '?').toUpperCase();
  }
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
function apiGet(params = {}){
  const q = new URLSearchParams(params).toString();
  return fetch(DM_API + (q ? '&' + q : ''), { credentials:'same-origin' }).then(r => r.json());
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
  try {
    const a = document.getElementById('bell2');
    if (a) { a.loop = false; a.pause(); a.currentTime = 0; }
  } catch(e){}
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
function showIncomingCall(userName, avatar, text, callId){
  currentVoiceCaller = (userName || TARGET || '').trim();
  activeIncomingVoice = callId || activeIncomingVoice || null;
  INCOMING_TITLE.textContent = currentVoiceCaller ? `${currentVoiceCaller} is calling you` : 'Incoming voice call';
  INCOMING_SUB.textContent = text || 'Tap accept to join this voice call.';
  setAvatar(INCOMING_AVATAR, { username: currentVoiceCaller || 'Caller', avatar });
  INCOMING_CALL.style.display = 'flex';
  INCOMING_CALL.setAttribute('aria-hidden', 'false');
  startIncomingCallRinger();
}
function hideIncomingCall(){
  INCOMING_CALL.style.display = 'none';
  INCOMING_CALL.setAttribute('aria-hidden', 'true');
  activeIncomingVoice = null;
  stopIncomingCallRinger();
}

function updateHeaderAndTheme(target){
  if (!target) return;
  headerName.textContent = target.username || TARGET;
  headerMeta.textContent = target.bio ? 'Profile loaded' : 'No bio provided';
  sideUsername.textContent = target.username || TARGET;
  sideRole.textContent = target.role ? target.role : '';
  sideBio.innerHTML = formatBioHtml(target.bio || '');
  const avatarUrl = target.avatar ? ((String(target.avatar).indexOf('/') === 0 || String(target.avatar).startsWith('http')) ? target.avatar : 'avatars/' + encodeURIComponent(target.avatar)) : '';
  if (avatarUrl) applyAvatarThemeFromUrl(avatarUrl);
  setAvatar(headerAvatar, target);
  setAvatar(sidePfp, target);
}

function makeFriendNode(f) {
  const node = document.createElement('div');
  node.className = 'friendCard';
  const avatar = document.createElement('div');
  avatar.className = 'avatar';
  if (f.avatar) {
    const img = document.createElement('img');
    img.src = (String(f.avatar).indexOf('/') === 0 || String(f.avatar).startsWith('http')) ? f.avatar : 'avatars/' + encodeURIComponent(f.avatar);
    avatar.appendChild(img);
  } else {
    avatar.textContent = (f.username || '?')[0].toUpperCase();
  }
  const wrap = document.createElement('div');
  wrap.style.minWidth = '0';
  wrap.style.flex = '1';
  const name = document.createElement('div');
  name.className = 'name';
  name.textContent = f.username || '';
  const sub = document.createElement('div');
  sub.className = 'sub';
  sub.textContent = f.status || 'Tap to open chat';
  wrap.appendChild(name);
  wrap.appendChild(sub);
  node.appendChild(avatar);
  node.appendChild(wrap);
  node.addEventListener('click', ()=> { window.location.href = 'message.php?user=' + encodeURIComponent(f.username || ''); });
  return node;
}

function updateRelationshipButtons(){
  const rel = relationship || {};
  if (friendBtn) {
    if (rel.status === 'friends') {
      friendBtn.textContent='Remove friend';
      friendBtn.classList.remove('primary');
    } else if (rel.status === 'acquaintance') {
      friendBtn.textContent='Request friend';
      friendBtn.classList.add('primary');
    } else if (rel.status === 'requested') {
      if (rel.initiator && Number(rel.initiator) === Number(MY_ID)) {
        friendBtn.textContent='Cancel request';
        friendBtn.classList.remove('primary');
      } else {
        friendBtn.textContent = rel.requested_kind === 'acquaintance' ? 'Accept acquaintance' : 'Accept request';
        friendBtn.classList.add('primary');
      }
    } else {
      friendBtn.textContent='Send friend request';
      friendBtn.classList.add('primary');
    }
  }

  if (acquaintBtn) {
    if (rel.status === 'acquaintance') {
      acquaintBtn.textContent='Remove acquaintance';
      acquaintBtn.classList.remove('primary');
    } else if (rel.status === 'requested' && rel.requested_kind === 'acquaintance' && rel.initiator && Number(rel.initiator) !== Number(MY_ID)) {
      acquaintBtn.textContent='Decline acquaintance';
      acquaintBtn.classList.remove('primary');
    } else {
      acquaintBtn.textContent='Request acquaintance';
      acquaintBtn.classList.add('primary');
    }
  }

  if (blockBtn) {
    if (rel.blocked) { blockBtn.textContent='Unblock'; blockBtn.dataset.blocked='1'; }
    else { blockBtn.textContent='Block'; blockBtn.dataset.blocked='0'; }
  }

  if (inputArea) {
    inputArea.style.display = rel.allowed === false ? 'none' : 'flex';
  }

  if (voiceBtn) voiceBtn.classList.toggle('primary', rel.status === 'friends');
}

function renderTheirFriends(){
  if (!theirFriendsEl) return;
  theirFriendsEl.innerHTML = '';
  const rel = relationship || {};
  if (rel.status === 'friends') {
    const list = Array.isArray(rel.their_friends) ? rel.their_friends : (Array.isArray(rel.mutual_friends) ? rel.mutual_friends : []);
    if (list.length === 0) {
      theirFriendsEl.innerHTML = '<div style="color:var(--muted)">No visible friends</div>';
      return;
    }
    list.forEach(f => theirFriendsEl.appendChild(makeFriendNode(f)));
  } else {
    theirFriendsEl.innerHTML = '<div style="color:var(--muted)">You need to be friends to view this list</div>';
  }
}
function renderYourFriends(list){
  if (!yourFriendsEl) return;
  yourFriendsEl.innerHTML = '';
  const rows = Array.isArray(list) ? list : [];
  if (rows.length === 0) {
    yourFriendsEl.innerHTML = '<div style="color:var(--muted)">No friends yet</div>';
    return;
  }
  rows.forEach(f => yourFriendsEl.appendChild(makeFriendNode(f)));
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

function escapeForRegex(s){ return s.replace(/[.*+?^${}()|[\]\\]/g,'\\$&'); }
const EMOJI_RE = /([\u{1F1E6}-\u{1FAFF}\u2600-\u27BF])/gu;
function renderMessageText(raw){
  let s = esc(String(raw || ''));
  s = s.replace(/!\[image\]\((\/images\/[^\s)]+)\)/g, (_, path) => {
    const safe = '/images/' + encodeURIComponent(path.replace(/^\/images\//,''));
    return `<img src="${safe}" alt="image">`;
  });
  s = s.replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>');
  s = s.replace(/\n/g, '<br>');
  s = s.replace(EMOJI_RE, '<span class="bigEmoji">$1</span>');
  return s;
}

function buildMessageDom(m){
  const isMine = Number(m.user_id) === Number(MY_ID) || String(m.username || '') === String(MY_USERNAME);

  const row = document.createElement('div');
  row.className = 'msgRow' + (isMine ? ' mine' : '');
  row.dataset.id = m.id;
  row.dataset.userId = m.user_id || '';
  row.dataset.username = m.username || '';
  row.dataset.excerpt = (m.message || '').slice(0, 180);

  const avatarSpacer = document.createElement('div');
  avatarSpacer.className = 'msgAvatar hidden';

  const bubble = document.createElement('div');
  bubble.className = 'msgBubble' + (isMine ? ' mine' : '');

  if (m.reply_to_username || m.reply_to_excerpt) {
    const sn = document.createElement('div');
    sn.className = 'replySnippet';
    const ru = document.createElement('div');
    ru.className = 'ruser';
    ru.textContent = m.reply_to_username || '…';
    const rx = document.createElement('div');
    rx.textContent = m.reply_to_excerpt || '';
    sn.appendChild(ru);
    sn.appendChild(rx);
    bubble.appendChild(sn);
  }

  const content = document.createElement('div');
  content.className='msgText';
  content.innerHTML = renderMessageText(m.message || '');
  bubble.appendChild(content);

  const meta = document.createElement('div');
  meta.className='msgMeta';
  meta.textContent = (m.edited_at ? 'edited • ' : '') + (relTime(m.created_at) || '');
  bubble.appendChild(meta);

  row.appendChild(isMine ? avatarSpacer : cleanRoomAvatar(m));
  row.appendChild(bubble);
  if (isMine) row.appendChild(cleanRoomAvatar(m));

  row.addEventListener('pointerdown', (ev)=> {
    if (ev.pointerType === 'mouse' && ev.button !== 0) return;
  });

  return row;
}

function appendMessages(messages){
  let appended = false;
  let playSound = false;
  for (const m of (messages || [])) {
    if (!m || !m.id || Number(m.id) <= Number(lastId)) continue;
    appended = true;
    messagesById.set(String(m.id), m);
    const isMine = Number(m.user_id) === Number(MY_ID) || String(m.username || '') === String(MY_USERNAME);
    if (!isMine) playSound = true;
    chatEl.appendChild(buildMessageDom(m));
    lastId = Math.max(lastId, Number(m.id) || 0);
  }
  if (appended) {
    chatEl.scrollTop = chatEl.scrollHeight;
    if (playSound && audioUnlocked) {
      try {
        const dmAudio = document.getElementById('dm_bell');
        if (dmAudio) { dmAudio.currentTime = 0; dmAudio.play().catch(()=>{}); }
      } catch(e){}
    }
  }
}

function updateTyping(list){
  const names = (list || []).filter(Boolean);
  if (typingBar) {
    if (names.length === 0) typingBar.textContent = '';
    else if (names.length === 1) typingBar.textContent = names[0] + ' is typing…';
    else typingBar.textContent = names.slice(0,3).join(', ') + ' are typing…';
  }
}

function showReplyPreview(obj){
  if (!obj) return clearReply();
  replyingTo = {
    id: obj.id || obj.id,
    username: obj.username || obj.user || '',
    excerpt: obj.excerpt || obj.text || ''
  };
  rpUser.textContent = replyingTo.username || '…';
  rpText.textContent = (replyingTo.excerpt || '').slice(0,240);
  rpPreview.style.display = 'flex';
  msgEl.focus();
}
function clearReply(){
  replyingTo = null;
  rpPreview.style.display = 'none';
  rpUser.textContent = '';
  rpText.textContent = '';
}
rpCancel.addEventListener('click', ()=> clearReply());

async function editMessageById(id){
  const current = messagesById.get(String(id));
  const orig = current ? (current.message || '') : '';
  const next = prompt('Edit message', orig);
  if (next === null) return;
  if (Array.from(next).length > MAX_MESSAGE_LENGTH) return alert('Message too long');
  const fd = new FormData();
  fd.append('id', id);
  fd.append('message', next);
  const res = await fetch(DM_API + '&mode=edit', { method:'POST', body: fd, credentials:'same-origin' });
  const j = await res.json().catch(()=>null);
  if (j && j.ok) await pollOnce();
  else alert(j && j.error ? j.error : 'Edit failed');
}

async function send(){
  const text = msgEl.value.trim();
  if (!text) return;
  if (Array.from(text).length > MAX_MESSAGE_LENGTH) return alert('Message too long');
  if (relationship && relationship.allowed === false) return alert("You can't message this user yet.");
  const fd = new FormData();
  fd.append('message', text);
  if (replyingTo && replyingTo.id) fd.append('reply_to', replyingTo.id);
  await fetch(DM_API + '&mode=send', { method:'POST', body: fd, credentials:'same-origin' });
  msgEl.value = '';
  charCount.textContent = '0/750';
  clearReply();
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
    const resp = await fetch('upload_image.php', { method:'POST', body: fd, credentials:'same-origin' });
    const j = await resp.json().catch(()=>null);
    if (!j || !j.ok || !j.url) return alert('Upload failed');
    const body = new FormData();
    body.append('message', `![image](${j.url})`);
    if (replyingTo && replyingTo.id) body.append('reply_to', replyingTo.id);
    await fetch(DM_API + '&mode=send', { method:'POST', body, credentials:'same-origin' });
    clearReply();
    await pollOnce();
  } catch (e) {
    console.error('uploadAndSendImage', e);
    alert('Upload failed');
  }
}
IMAGE_BTN.addEventListener('click', ()=> IMAGE_INPUT.click());
IMAGE_INPUT.addEventListener('change', async (e)=> {
  const file = e.target.files && e.target.files[0] ? e.target.files[0] : null;
  if (!file) return;
  await uploadAndSendImage(file);
  IMAGE_INPUT.value = '';
});

friendBtn.addEventListener('click', async ()=>{
  let action = 'request_friend';
  const txt = (friendBtn.textContent || '').toLowerCase();
  if (txt.includes('remove')) action = 'remove_friend';
  else if (txt.includes('cancel')) action = 'cancel_request';
  else if (txt.includes('accept')) action = (relationship.requested_kind === 'acquaintance') ? 'accept_acquaintance' : 'accept_friend';
  const fd = new FormData();
  fd.append('action', action);
  const r = await fetch(DM_API + '&mode=friend_action', { method:'POST', body: fd, credentials:'same-origin' });
  const j = await r.json().catch(()=>null);
  if (j && j.ok) await loadOnce(); else { alert(j && j.error ? j.error : 'Action failed'); await loadOnce(); }
});

acquaintBtn.addEventListener('click', async ()=>{
  if (relationship.status === 'requested' && relationship.requested_kind === 'acquaintance' && relationship.initiator && Number(relationship.initiator) !== Number(MY_ID)) {
    if (!confirm('Decline acquaintance request?')) return;
    const fd = new FormData(); fd.append('action','decline');
    const r = await fetch(DM_API + '&mode=friend_action', { method:'POST', body: fd, credentials:'same-origin' });
    const j = await r.json().catch(()=>null);
    if (j && j.ok) await loadOnce(); else alert('Failed');
    return;
  }
  if (relationship.status === 'acquaintance') {
    if (!confirm('Remove acquaintance?')) return;
    const fd = new FormData(); fd.append('action','remove_acquaintance');
    const r = await fetch(DM_API + '&mode=friend_action', { method:'POST', body: fd, credentials:'same-origin' });
    const j = await r.json().catch(()=>null);
    if (j && j.ok) await loadOnce(); else alert('Failed');
  } else {
    const fd = new FormData(); fd.append('action','request_acquaintance');
    const r = await fetch(DM_API + '&mode=friend_action', { method:'POST', body: fd, credentials:'same-origin' });
    const j = await r.json().catch(()=>null);
    if (j && j.ok) await loadOnce(); else alert('Failed');
  }
});

blockBtn.addEventListener('click', async ()=>{
  if (blockBtn.dataset.blocked === '1') {
    const fd = new FormData(); fd.append('action','unblock');
    const r = await fetch(DM_API + '&mode=block_action', { method:'POST', body: fd, credentials:'same-origin' });
    const j = await r.json().catch(()=>null);
    if (j && j.ok) { loadOnce(); } else alert('Failed');
  } else {
    if (!confirm('Block this user?')) return;
    const fd = new FormData(); fd.append('action','block');
    const r = await fetch(DM_API + '&mode=block_action', { method:'POST', body: fd, credentials:'same-origin' });
    const j = await r.json().catch(()=>null);
    if (j && j.ok) { loadOnce(); } else alert('Failed');
  }
});

let lastTyping = 0;
msgEl.addEventListener('input', ()=> {
  const len = Array.from(msgEl.value).length;
  charCount.textContent = len + '/' + MAX_MESSAGE_LENGTH;
  const now = Date.now();
  if (now - lastTyping > 850) {
    lastTyping = now;
    navigator.sendBeacon
      ? navigator.sendBeacon(DM_API + '&mode=typing')
      : fetch(DM_API + '&mode=typing', { method:'POST', keepalive:true, credentials:'same-origin' }).catch(()=>{});
  }
});
sendBtn.addEventListener('click', send);
msgEl.addEventListener('keydown', (e)=> { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } });

function openVoice(userName){
  const who = (userName || TARGET || '').trim();
  const room = voiceRoomForPair(MY_USERNAME, who);
  VOICE_TITLE.textContent = 'Voice with ' + who;
  VOICE_FRAME.src = 'message_voice.php?room=' + encodeURIComponent(room) + '&embed=1&user=' + encodeURIComponent(who);
  VOICE_MODAL.classList.add('open');
  VOICE_MODAL.setAttribute('aria-hidden', 'false');
  VOICE_STATUS.textContent = 'Connecting to call…';
  voiceOpen = true;
}
async function closeVoice(){
  VOICE_MODAL.classList.remove('open');
  VOICE_MODAL.setAttribute('aria-hidden', 'true');
  VOICE_FRAME.src = 'about:blank';
  voiceOpen = false;
  try {
    await fetch(DM_API + '&mode=voice_call_end', { method:'POST', credentials:'same-origin' });
  } catch (e) {}
}
async function startVoiceCall(){
  await openVoice(TARGET);
  try {
    const r = await fetch(DM_API + '&mode=voice_call_invite', { method:'POST', credentials:'same-origin' });
    const j = await r.json().catch(()=>null);
    if (j && j.ok) {
      lastCallId = Number(j.call_id || lastCallId || 0);
      lastCallStatus = 'ringing';
    }
  } catch (e) {}
}
voiceBtn.addEventListener('click', startVoiceCall);
voiceCloseBtn.addEventListener('click', closeVoice);
voiceLeaveBtn.addEventListener('click', closeVoice);

window.addEventListener('message', (ev)=> {
  const d = ev.data || {};
  if (d && d.type === 'voice-status') VOICE_STATUS.textContent = d.text || 'In call';
  if (d && d.type === 'close-message-voice') closeVoice();
});

INCOMING_ACCEPT.addEventListener('click', async ()=>{
  const caller = currentVoiceCaller || TARGET;
  try {
    await fetch(DM_API.replace(/user=[^&]+/, 'user=' + encodeURIComponent(caller)) + '&mode=voice_call_accept', {
      method: 'POST',
      credentials: 'same-origin'
    });
  } catch (e) {}
  hideIncomingCall();
  await openVoice(caller);
  lastCallStatus = 'accepted';
});
INCOMING_DISMISS.addEventListener('click', async ()=>{
  const caller = currentVoiceCaller || TARGET;
  try {
    await fetch(DM_API.replace(/user=[^&]+/, 'user=' + encodeURIComponent(caller)) + '&mode=voice_call_dismiss', {
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

function renderNotifRow(n){
  const row = document.createElement('div');
  row.className = 'notifGroup';
  const av = document.createElement('div');
  av.className = 'avatar';
  if (n.source_avatar) {
    const img = document.createElement('img');
    img.src = (String(n.source_avatar).indexOf('/') === 0 || String(n.source_avatar).startsWith('http')) ? n.source_avatar : 'avatars/' + encodeURIComponent(n.source_avatar);
    av.appendChild(img);
  } else {
    av.textContent = (n.source_username ? n.source_username[0].toUpperCase() : '?');
  }
  const meta = document.createElement('div');
  meta.className = 'meta';
  const title = document.createElement('div');
  title.className = 'title';
  title.textContent = (n.source_username || 'System') + (isVoiceCallNotification(n) ? ' · voice call' : '');
  const msg = document.createElement('div');
  msg.className = 'msg';
  msg.textContent = (n.message || '').slice(0,140);
  meta.appendChild(title);
  meta.appendChild(msg);
  const dot = document.createElement('div');
  dot.className = 'unreadDot';
  row.appendChild(av);
  row.appendChild(meta);
  row.appendChild(dot);

  row.addEventListener('click', async ()=>{
    try {
      await fetch(NOTIF_API, { method:'POST', credentials:'same-origin', body: new URLSearchParams({ action:'mark_read', id: n.id }) });
    } catch(e){}
    notifDropdown.style.display = 'none';

    if (isVoiceCallNotification(n)) {
      currentVoiceCaller = n.source_username || TARGET;
      showIncomingCall(n.source_username || TARGET, n.source_avatar || null, n.message || 'Tap accept to join this voice call.', n.id);
      return;
    }

    if (n.ref_code) { location.href = 'mobile_private.php?code=' + encodeURIComponent(n.ref_code); return; }
    if (n.type && String(n.type).indexOf('dm') !== -1 && n.source_username) {
      location.href = 'message.php?user=' + encodeURIComponent(n.source_username);
      return;
    }
    if (n.source_username) location.href = 'message.php?user=' + encodeURIComponent(n.source_username);
  });

  return row;
}

async function loadNotifs(){
  const j = await fetchNotifications(100);
  const unread = j && j.unread_count ? j.unread_count : 0;
  if (unread > lastUnread && lastUnread !== 0 && audioUnlocked) {
    try {
      const a = document.getElementById('notif_bell');
      if (a) { a.currentTime = 0; a.play().catch(()=>{}); }
    } catch(e){}
  }
  lastUnread = unread;
  notifBadge.style.display = unread > 0 ? 'inline-block' : 'none';
  notifBadge.textContent = unread > 99 ? '99+' : String(unread);
  notifList.innerHTML = '';
  const rows = Array.isArray(j.notifications) ? j.notifications : [];

  if (rows.length === 0) {
    notifList.innerHTML = '<div style="padding:12px;color:var(--muted)">No notifications</div>';
    return;
  }
  rows.forEach(n => notifList.appendChild(renderNotifRow(n)));
}

function toggleDropdown(force){
  const open = notifDropdown.style.display === 'block';
  const next = (typeof force === 'boolean') ? force : !open;
  notifDropdown.style.display = next ? 'block' : 'none';
  if (next) loadNotifs();
}
notifBtn.addEventListener('click', (e)=> { e.stopPropagation(); toggleDropdown(); });
document.addEventListener('click', (e)=> {
  if (!e.target.closest || (!e.target.closest('#notifBtn') && !e.target.closest('#notifDropdown'))) toggleDropdown(false);
});
markAllBtn.addEventListener('click', async (e)=> {
  e.stopPropagation();
  try {
    await fetch(NOTIF_API, { method:'POST', credentials:'same-origin', body: new URLSearchParams({ action:'mark_all_read' }) });
    document.querySelectorAll('.notifGroup').forEach(it => { it.classList.remove('unread'); const d=it.querySelector('.unreadDot'); if (d) d.remove(); });
    lastUnread = 0;
    notifBadge.style.display='none';
    const j = await fetchNotifications(200);
    if (Array.isArray(j.notifications)) for (const n of j.notifications) markedNotifIds.add(n.id);
  } catch (e) { console.error(e); }
});

async function refreshNotifBadge(){
  const j = await fetchNotifications(5);
  const unread = j && j.unread_count ? j.unread_count : 0;
  lastUnread = unread;
  if (unread > 0) {
    notifBadge.style.display='inline-block';
    notifBadge.textContent = unread > 99 ? '99+' : String(unread);
  } else {
    notifBadge.style.display='none';
  }
}

async function loadOnce(){
  try {
    const r = await apiGet({});
    if (r.error) {
      chatEl.innerHTML = '<div class="emptyState">' + esc(r.error) + '</div>';
      return;
    }
    if (r.target) {
      currentTarget = r.target;
      updateHeaderAndTheme(r.target);
    }
    if (r.relationship) {
      relationship = r.relationship;
      updateRelationshipButtons();
      renderTheirFriends();
    }
    if (Array.isArray(r.friends)) renderYourFriends(r.friends);
    if (r.typing) updateTyping(r.typing);

    chatEl.innerHTML = '';
    messagesById.clear();
    lastId = 0;
    appendMessages(r.messages || []);
    if (!r.messages || !r.messages.length) chatEl.innerHTML = '<div class="emptyState">Say hello 👋</div>';

    if (r.call) renderCallState(r.call);
  } catch (e) {
    console.error('loadOnce', e);
    chatEl.innerHTML = '<div class="emptyState">Load error</div>';
  }
}

async function pollOnce(){
  if (inFlight) return;
  inFlight = true;
  try {
    const r = await fetch(
      DM_API +
      '&since=' + encodeURIComponent(lastId) +
      '&call_since=' + encodeURIComponent(lastCallId) +
      '&call_status=' + encodeURIComponent(lastCallStatus),
      { credentials:'same-origin' }
    );
    if (!r.ok) return;
    const j = await r.json();
    if (j.target) {
      currentTarget = j.target;
      updateHeaderAndTheme(j.target);
    }
    if (j.relationship) {
      relationship = j.relationship;
      updateRelationshipButtons();
      renderTheirFriends();
    }
    if (Array.isArray(j.messages) && j.messages.length) {
      const other = j.messages.some(m => Number(m.user_id) !== Number(MY_ID));
      appendMessages(j.messages);
      if (other && audioUnlocked) {
        try {
          const a = document.getElementById('dm_bell');
          if (a) { a.currentTime = 0; a.play().catch(()=>{}); }
        } catch(e){}
      }
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
    try {
      await pollOnce();
      await new Promise(r => setTimeout(r, 700));
    } catch (e) {
      await new Promise(r => setTimeout(r, 2000));
    }
  }
}

loadOnce().then(()=>{
  refreshNotifBadge();
  longPollLoop();
  setInterval(()=> { if (!document.hidden) loadNotifs(); }, 30000);
}).catch((e)=>{
  console.error('startup error', e);
  chatEl.innerHTML = '<div class="emptyState">Load error</div>';
});

window.addEventListener('beforeunload', ()=> running = false);
window.addEventListener('keydown', (e)=> {
  if (e.key === 'Escape') {
    if (notifDropdown.style.display === 'block') toggleDropdown(false);
    else if (VOICE_MODAL.classList.contains('open')) closeVoice();
    else if (INCOMING_CALL.style.display === 'flex') hideIncomingCall();
  }
});
</script>
</body>
</html>
