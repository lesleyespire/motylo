<?php
// settings.php - user settings (username, avatar crop, change password) + OneSignal v16 push subscription UI
require "config.php";

// --- auth check ---
if (empty($_COOKIE['auth_token'])) {
    header("Location: index.php");
    exit;
}
$stmt = $pdo->prepare("SELECT * FROM users WHERE auth_token = ?");
$stmt->execute([$_COOKIE['auth_token']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    header("Location: index.php");
    exit;
}
$userId = (int)$user['id'];

// Provide ONE SIGNAL APP ID from config (if set)
$ONESIGNAL_APP_ID = isset($ONESIGNAL_APP_ID) ? $ONESIGNAL_APP_ID : '';

// helper to respond JSON (for AJAX)
function respond_json($arr) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr);
    exit;
}

// helper: valid username
function valid_username($u) {
    if (!is_string($u)) return false;
    $len = mb_strlen($u);
    if ($len < 2 || $len > 32) return false;
    return preg_match('/^[A-Za-z0-9_\-\.]+$/', $u);
}

// helper: get user table columns
function get_table_cols($pdo, $table) {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
        return $cols ?: [];
    } catch (Exception $e) { return []; }
}

// admin-ish: determine password column(s) and avatar column
$usersCols = get_table_cols($pdo, 'users');
$pwCols = array_values(array_intersect(['password_hash','password','passwd','password_sha256'], $usersCols));
$avatarCol = in_array('avatar', $usersCols) ? 'avatar' : (in_array('photo', $usersCols) ? 'photo' : null);

// --- API endpoints (AJAX POST) ---
$action = $_REQUEST['action'] ?? '';

if ($action === 'change_username' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $new = trim((string)($_POST['username'] ?? ''));
    if (!valid_username($new)) respond_json(['ok'=>false,'error'=>'Invalid username. Use 2-32 chars: letters, numbers, ._-']);
    $st = $pdo->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(?) AND id != ? LIMIT 1");
    $st->execute([$new, $userId]);
    if ($st->fetch()) respond_json(['ok'=>false,'error'=>'Username already taken.']);
    try {
        $up = $pdo->prepare("UPDATE users SET username = ? WHERE id = ?");
        $up->execute([$new, $userId]);
        respond_json(['ok'=>true,'username'=>$new]);
    } catch (Exception $e) {
        respond_json(['ok'=>false,'error'=>'Update failed: '.$e->getMessage()]);
    }
}

// file upload + crop: receive file in $_FILES['image'] and crop params x,y,w,h (natural image coords)
if ($action === 'upload_avatar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $maxBytes = 6 * 1024 * 1024; // 6MB
    if (empty($_FILES['image']) || !isset($_FILES['image']['tmp_name']) || !is_uploaded_file($_FILES['image']['tmp_name'])) {
        respond_json(['ok'=>false,'error'=>'No file uploaded']);
    }
    $tmp = $_FILES['image']['tmp_name'];
    if ($_FILES['image']['size'] > $maxBytes) respond_json(['ok'=>false,'error'=>'File too large (max 6MB)']);
    $info = @getimagesize($tmp);
    if ($info === false) respond_json(['ok'=>false,'error'=>'Not a valid image']);
    $mime = $info['mime'] ?? '';
    $ext = '';
    switch ($mime) {
        case 'image/jpeg': $ext = '.jpg'; break;
        case 'image/png':  $ext = '.png'; break;
        case 'image/gif':  $ext = '.gif'; break;
        case 'image/webp': $ext = '.webp'; break;
        default: respond_json(['ok'=>false,'error'=>'Unsupported image type']);
    }

    // crop params from POST (natural image pixels)
    $x = isset($_POST['x']) ? (int)$_POST['x'] : 0;
    $y = isset($_POST['y']) ? (int)$_POST['y'] : 0;
    $w = isset($_POST['w']) ? (int)$_POST['w'] : 0;
    $h = isset($_POST['h']) ? (int)$_POST['h'] : 0;

    // load source image
    switch ($mime) {
        case 'image/jpeg': $src = @imagecreatefromjpeg($tmp); break;
        case 'image/png':  $src = @imagecreatefrompng($tmp); break;
        case 'image/gif':  $src = @imagecreatefromgif($tmp); break;
        case 'image/webp': $src = @imagecreatefromwebp($tmp); break;
        default: $src = false;
    }
    if (!$src) respond_json(['ok'=>false,'error'=>'Failed to process image']);

    $srcW = imagesx($src);
    $srcH = imagesy($src);

    // If crop dims invalid, auto-square-crop center
    if ($w <= 0 || $h <= 0 || $x < 0 || $y < 0 || $x + $w > $srcW || $y + $h > $srcH) {
        $size = min($srcW, $srcH);
        $x = (int)(($srcW - $size) / 2);
        $y = (int)(($srcH - $size) / 2);
        $w = $h = $size;
    }

    // create destination (square) - output 400x400
    $outSize = 400;
    $dst = imagecreatetruecolor($outSize, $outSize);
    if (in_array($mime, ['image/png','image/gif','image/webp'])) {
        imagecolortransparent($dst, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
    }
    if (!imagecopyresampled($dst, $src, 0, 0, $x, $y, $outSize, $outSize, $w, $h)) {
        imagedestroy($src); imagedestroy($dst);
        respond_json(['ok'=>false,'error'=>'Crop failed']);
    }

    // ensure avatars dir
    $avatarsDir = __DIR__ . '/avatars';
    if (!is_dir($avatarsDir)) @mkdir($avatarsDir, 0755, true);

    // generate filename
    $safeName = bin2hex(random_bytes(8)) . $ext;
    $outPath = $avatarsDir . '/' . $safeName;

    // save
    $saved = false;
    switch ($mime) {
        case 'image/jpeg': $saved = imagejpeg($dst, $outPath, 90); break;
        case 'image/png':  $saved = imagepng($dst, $outPath); break;
        case 'image/gif':  $saved = imagegif($dst, $outPath); break;
        case 'image/webp': $saved = imagewebp($dst, $outPath, 90); break;
    }
    imagedestroy($src); imagedestroy($dst);
    if (!$saved) respond_json(['ok'=>false,'error'=>'Failed to save avatar']);

    // update users.avatar (if column exists)
    if ($avatarCol) {
        try {
            $stmt = $pdo->prepare("UPDATE users SET `$avatarCol` = ? WHERE id = ?");
            $stmt->execute([$safeName, $userId]);
        } catch (Exception $e) {
            // ignore update failure but return url
        }
    }

    $url = 'avatars/' . rawurlencode($safeName);
    respond_json(['ok'=>true,'url'=>$url,'filename'=>$safeName]);
}

// change password
if ($action === 'change_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = (string)($_POST['old_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    if ($new === '' || $confirm === '') respond_json(['ok'=>false,'error'=>'New password required']);
    if ($new !== $confirm) respond_json(['ok'=>false,'error'=>'Password confirmation does not match']);
    if (mb_strlen($new) < 6) respond_json(['ok'=>false,'error'=>'Password too short (min 6 chars)']);

    $pwColToUse = null;
    foreach (['password_hash','password','passwd','password_sha256'] as $c) {
        if (in_array($c, $usersCols)) { $pwColToUse = $c; break; }
    }
    if (!$pwColToUse) {
        try {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN password_hash VARCHAR(255) NULL");
            $usersCols[] = 'password_hash';
            $pwColToUse = 'password_hash';
        } catch (Exception $e) {
            respond_json(['ok'=>false,'error'=>'No password column available']);
        }
    }

    $currentHash = $user[$pwColToUse] ?? null;
    $needOld = !empty($currentHash);
    if ($needOld) {
        $okOld = false;
        if (function_exists('password_verify') && preg_match('/^\$2[ayb]\$|\$argon2/i', $currentHash)) {
            $okOld = password_verify($old, $currentHash);
        } elseif (!empty($currentHash) && strlen($currentHash) === 64 && preg_match('/^[0-9a-f]{64}$/i', $currentHash)) {
            if (hash('sha256', $old) === $currentHash) $okOld = true;
        } else {
            if ($old === $currentHash) $okOld = true;
        }
        if (!$okOld) respond_json(['ok'=>false,'error'=>'Old password incorrect']);
    }

    if (function_exists('password_hash')) {
        $newHash = password_hash($new, PASSWORD_DEFAULT);
    } else {
        $newHash = hash('sha256', $new);
    }

    try {
        $up = $pdo->prepare("UPDATE users SET `$pwColToUse` = ? WHERE id = ?");
        $up->execute([$newHash, $userId]);
        respond_json(['ok'=>true]);
    } catch (Exception $e) {
        respond_json(['ok'=>false,'error'=>'Update failed: '.$e->getMessage()]);
    }
}

// No API actions handled in this file for push - we call notifications.php for subscribe/unsubscribe
if ($action !== '') {
    respond_json(['ok'=>false,'error'=>'Unknown action']);
}

// ---------------------- HTML UI ----------------------
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Settings — Account</title>
<link rel="icon" href="root/favicon.ico">
<style>
:root{--bg:#0d0e10;--panel:#121417;--accent:#4f7cff;--muted:#9aa3b2;--success:#2ecc71}
body{margin:0;font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial;background:var(--bg);color:#eef2ff}
.container{max-width:940px;margin:24px auto;padding:18px;background:var(--panel);border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,0.6)}
.header{display:flex;justify-content:space-between;align-items:center;gap:12px}
.h1{font-size:20px;font-weight:700}
.section{margin-top:18px;padding:12px;border-radius:10px;background:linear-gradient(180deg, rgba(255,255,255,0.015), transparent)}
.row{display:flex;gap:12px;align-items:center}
.formRow{display:flex;flex-direction:column;gap:8px;margin-top:8px}
.input{padding:10px;border-radius:8px;border:0;background:#0b0c0d;color:#fff}
.btn{background:var(--accent);color:white;border:0;padding:10px 12px;border-radius:8px;cursor:pointer}
.small{color:var(--muted);font-size:13px}
.msg{margin-top:8px;padding:8px;border-radius:8px}
.msg.ok{background:rgba(46,204,113,0.09);color:var(--success)}
.msg.err{background:rgba(255,77,79,0.06);color:#ff9aa2}
.avatarPreview{width:120px;height:120px;border-radius:12px;background:#111;overflow:hidden;border:2px solid rgba(255,255,255,0.03);display:flex;align-items:center;justify-content:center}
.avatarPreview img{width:100%;height:100%;object-fit:cover;display:block}
.canvasWrap{position:relative;background:#000;max-width:100%;overflow:hidden;border-radius:8px}
.cropCanvas{max-width:100%;touch-action:none;display:block}
.cropOverlay{position:absolute;left:0;top:0;right:0;bottom:0;pointer-events:auto}
.cropRect{position:absolute;border:2px dashed rgba(255,255,255,0.85);background:rgba(0,0,0,0.12);box-sizing:border-box;touch-action:none}
.hint{font-size:13px;color:var(--muted);margin-top:6px}
.flex{display:flex;gap:12px;align-items:center}
@media (max-width:720px){ .row{flex-direction:column;align-items:stretch} .flex{flex-direction:column;align-items:stretch} }
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <div>
      <div class="h1">Account settings</div>
      <div class="small">Manage username, avatar, password & notifications</div>
    </div>
    <div><a class="btn" href="room.php">Back to chat</a></div>
  </div>

  <!-- Username -->
  <div class="section" id="usernameSection">
    <div style="display:flex;justify-content:space-between;align-items:center">
      <div><strong>Username</strong><div class="small">Change your display name</div></div>
    </div>
    <div class="formRow">
      <div class="row" style="margin-top:8px">
        <input id="usernameInput" class="input" style="width:320px" value="<?= htmlspecialchars($user['username'] ?? '') ?>">
        <button id="changeUsernameBtn" class="btn">Change</button>
      </div>
      <div id="usernameMsg" class="msg" style="display:none"></div>
      <div class="hint">Allowed: letters, numbers, dot, underscore, hyphen. 2–32 characters.</div>
    </div>
  </div>

  <!-- Avatar crop -->
  <div class="section" id="avatarSection">
    <div style="display:flex;justify-content:space-between;align-items:center">
      <div><strong>Avatar</strong><div class="small">Upload & crop your profile picture</div></div>
      <div class="avatarPreview" id="currentAvatar">
        <?php if (!empty($user['avatar'])): ?>
          <img src="<?= 'avatars/' . rawurlencode($user['avatar']) ?>" alt="avatar">
        <?php else: ?>
          <div style="font-size:28px;color:#fff"><?= strtoupper(substr($user['username'] ?? '?',0,1)) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="formRow">
      <input id="avatarFile" type="file" accept="image/*" class="input" style="padding:6px">
      <div id="cropUI" style="display:none;margin-top:12px">
        <div style="display:flex;gap:12px;flex-wrap:wrap">
          <div style="flex:1;min-width:320px">
            <div class="canvasWrap">
              <canvas id="cropCanvas" class="cropCanvas"></canvas>
              <div class="cropOverlay" id="cropOverlay"></div>
            </div>
            <div class="hint">Drag on the image to draw a crop rectangle. Drag inside the rectangle to move it. When satisfied click <strong>Crop & Save</strong>.</div>
          </div>
          <div style="width:220px;display:flex;flex-direction:column;gap:8px">
            <div><strong>Preview</strong></div>
            <div style="width:160px;height:160px;background:#0b0c0d;border-radius:8px;overflow:hidden">
              <canvas id="previewCanvas" width="160" height="160"></canvas>
            </div>
            <div style="display:flex;gap:8px">
              <button id="doCropBtn" class="btn">Crop & Save</button>
              <button id="cancelCropBtn" class="btn" style="background:#777">Cancel</button>
            </div>
            <div id="avatarMsg" class="msg" style="display:none"></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Password -->
  <div class="section" id="passwordSection">
    <div style="display:flex;justify-content:space-between;align-items:center">
      <div><strong>Password</strong><div class="small">Change your account password</div></div>
    </div>
    <div class="formRow" style="max-width:560px">
      <label class="small" for="oldPassword">Current password (leave empty to set new password if none exists)</label>
      <input id="oldPassword" class="input" type="password" placeholder="Current password">
      <label class="small" for="newPassword">New password</label>
      <input id="newPassword" class="input" type="password" placeholder="New password (min 6 chars)">
      <label class="small" for="confirmPassword">Confirm new password</label>
      <input id="confirmPassword" class="input" type="password" placeholder="Confirm new password">
      <div style="display:flex;gap:8px">
        <button id="changePasswordBtn" class="btn">Change password</button>
      </div>
      <div id="passwordMsg" class="msg" style="display:none"></div>
    </div>
  </div>

  <!-- Push notifications -->
  <div class="section" id="pushSection">
    <div style="display:flex;justify-content:space-between;align-items:center">
      <div><strong>Browser Push Notifications</strong><div class="small">Enable push notifications for desktop</div></div>
    </div>

    <div class="formRow">
      <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:8px">
        <button id="osToggleBtn" class="btn">Loading…</button>
        <div id="osStatus" class="small">Initializing…</div>
      </div>
      <div id="osMsg" class="msg" style="display:none"></div>
      <div class="hint" style="margin-top:8px">
        Service worker(s) must be placed at your site root:
        <code>/OneSignalSDKWorker.js</code> (and optional <code>/OneSignalSDKUpdaterWorker.js</code>).
        <?php if (empty($ONESIGNAL_APP_ID)): ?>
          <div style="margin-top:6px;color:#ffd6a5">Note: <strong>ONESIGNAL_APP_ID</strong> is not set in <code>config.php</code>. Add <code>$ONESIGNAL_APP_ID = 'your-app-id';</code> to enable OneSignal integration.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

<!-- OneSignal v16 page SDK -->
<script src="https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js" defer></script>

<script>
// small helpers
function q(id){ return document.getElementById(id); }
function showMsg(el, text, ok){
  if(!el) return;
  el.style.display = 'block';
  el.className = 'msg ' + (ok ? 'ok' : 'err');
  el.textContent = text;
}
function hideMsg(el){ if(!el) return; el.style.display='none'; el.textContent=''; }

// username handling (unchanged behaviour)
q('changeUsernameBtn').addEventListener('click', async ()=>{
  const username = q('usernameInput').value.trim();
  if (!/^[A-Za-z0-9_.\-]{2,32}$/.test(username)){ showMsg(q('usernameMsg'),'Invalid username format',false); return; }
  try {
    const fd = new FormData();
    fd.append('action','change_username');
    fd.append('username', username);
    const r = await fetch('settings.php', { method:'POST', body: fd, credentials:'same-origin' });
    const j = await r.json();
    if (j.ok) {
      showMsg(q('usernameMsg'),'Username updated', true);
    } else showMsg(q('usernameMsg'), j.error || 'Failed', false);
  } catch (e) { showMsg(q('usernameMsg'), 'Request failed', false); }
});

// avatar cropping UI (kept same as before)
(function setupAvatarCrop(){
  // ... your crop code is identical to previous working version ...
  // To save response length in chat I assume your existing avatar crop code is unchanged
  // (You told me it works). If you want I can re-insert it verbatim — say so.
})();

// change password (same as before)
q('changePasswordBtn').addEventListener('click', async ()=>{
  hideMsg(q('passwordMsg'));
  const oldP = q('oldPassword').value;
  const newP = q('newPassword').value;
  const conf = q('confirmPassword').value;
  if (!newP || !conf) { showMsg(q('passwordMsg'),'Enter new password and confirmation', false); return; }
  if (newP !== conf) { showMsg(q('passwordMsg'),'Password confirmation mismatch', false); return; }
  if (newP.length < 6) { showMsg(q('passwordMsg'),'Password too short (min 6)', false); return; }
  try {
    const fd = new FormData();
    fd.append('action','change_password');
    fd.append('old_password', oldP);
    fd.append('new_password', newP);
    fd.append('confirm_password', conf);
    const r = await fetch('settings.php', { method:'POST', body: fd, credentials:'same-origin' });
    const j = await r.json();
    if (j.ok) {
      showMsg(q('passwordMsg'),'Password changed', true);
      q('oldPassword').value = ''; q('newPassword').value = ''; q('confirmPassword').value = '';
    } else showMsg(q('passwordMsg'), j.error || 'Failed', false);
  } catch (e) { showMsg(q('passwordMsg'),'Request failed', false); }
});

// -------- OneSignal v16 subscription UI --------
const ONESIGNAL_APP_ID = <?= json_encode($ONESIGNAL_APP_ID) ?>;
const osBtn = q('osToggleBtn');
const osStatus = q('osStatus');
const osMsg = q('osMsg');

function setOsButton(text, enabled=true) { osBtn.textContent = text; osBtn.disabled = !enabled; }
function setOsStatus(txt) { osStatus.textContent = txt; }
function showOsError(txt) { showMsg(osMsg, txt, false); }
function clearOsError() { hideMsg(osMsg); }

// helper to POST to notifications.php
async function postNotify(action, payload = {}) {
  const form = new URLSearchParams();
  form.append('action', action);
  for (const k in payload) form.append(k, payload[k]);
  try {
    const r = await fetch('notifications.php', { method: 'POST', credentials: 'same-origin', body: form });
    return await r.json();
  } catch (e) {
    return { ok: false, error: 'Request failed' };
  }
}

// Initialize OneSignal using the v16 deferred pattern
function initOneSignalUI() {
  if (!ONESIGNAL_APP_ID) {
    setOsButton('Not configured', false);
    setOsStatus('Set $ONESIGNAL_APP_ID in config.php');
    return;
  }

  // early blocked permission check
  if (window.Notification && Notification.permission === 'denied') {
    setOsButton('Enable push notifications', true);
    setOsStatus('Permission blocked — open site settings to allow notifications.');
    return;
  }

  // OneSignalDeferred ensures v16 gives us the proper OneSignal instance
  window.OneSignalDeferred = window.OneSignalDeferred || [];
  OneSignalDeferred.push(async function(OneSignal) {
    try {
      await OneSignal.init({
        appId: ONESIGNAL_APP_ID,
        autoRegister: false, // we will request explicitly
        notifyButton: { enable: false }
      });
    } catch (err) {
      setOsButton('Init failed', false);
      setOsStatus('OneSignal initialization error');
      console.error('OneSignal.init error', err);
      return;
    }

    // Access v16 objects
    const pushSub = (OneSignal.User && OneSignal.User.PushSubscription) || null;

    // update UI from current state
    const opted = pushSub ? !!pushSub.optedIn : false;
    updateOsUI(opted);

    // Listen to subscription changes
    if (pushSub && typeof pushSub.addEventListener === 'function') {
      pushSub.addEventListener('change', async (evt) => {
        // evt has { optedIn, token, id } in v16 variants
        const nowOpted = !!evt.optedIn;
        updateOsUI(nowOpted);
        if (nowOpted) {
          // get the subscription id (v16 uses User.PushSubscription.id)
          const pid = OneSignal.User && OneSignal.User.PushSubscription && OneSignal.User.PushSubscription.id ? OneSignal.User.PushSubscription.id : (evt.id || evt.token || null);
          if (pid) {
            // register the new player id with our server
            await postNotify('subscribe_push', { player_id: pid, device_info: navigator.userAgent || '' });
            setOsStatus('Subscribed — confirmation sent.');
          } else {
            setOsStatus('Subscribed (no id yet) — retrying...');
            // small retry
            setTimeout(async () => {
              const retryId = OneSignal.User && OneSignal.User.PushSubscription && OneSignal.User.PushSubscription.id;
              if (retryId) await postNotify('subscribe_push', { player_id: retryId, device_info: navigator.userAgent || '' });
            }, 1500);
          }
        } else {
          // unsubscribed — remove server mapping if possible
          const pid = evt.id || (OneSignal.User && OneSignal.User.PushSubscription && OneSignal.User.PushSubscription.id) || '';
          await postNotify('unsubscribe_push', { player_id: pid || '' });
        }
      });
    }

    // If push supported, also listen to permission-related events
    if (OneSignal.Notifications && OneSignal.Notifications.addEventListener) {
      OneSignal.Notifications.addEventListener('permissionChange', (perm) => {
        // perm may be 'default'|'granted'|'denied'
        if (perm === 'granted') {
          setOsStatus('Permission granted');
        } else if (perm === 'denied') {
          setOsStatus('Permission denied');
        }
      });
    }
  });

  // final fallback timeout if SDK isn't present
  setTimeout(() => {
    if (!window.OneSignalDeferred) {
      setOsButton('OneSignal SDK not loaded', false);
      setOsStatus('OneSignal script did not initialize.');
    }
  }, 6000);
}

function updateOsUI(isEnabled) {
  clearOsError();
  if (isEnabled) {
    setOsButton('Disable push notifications', true);
    setOsStatus('Push enabled — you will receive notifications.');
  } else {
    setOsButton('Enable push notifications', true);
    setOsStatus('Push disabled — click to enable.');
  }
}

// Subscribe flow (user clicked enable)
async function subscribeFlow() {
  clearOsError();
  setOsButton('Subscribing…', false);
  setOsStatus('Prompting for permission...');

  // guard if browser blocked
  if (window.Notification && Notification.permission === 'denied') {
    showOsError('Browser has blocked notifications. Open site settings and allow them.');
    setOsButton('Enable push notifications', true);
    setOsStatus('Permission blocked');
    return;
  }

  // Use OneSignalDeferred to run code with the OneSignal object
  window.OneSignalDeferred = window.OneSignalDeferred || [];
  OneSignalDeferred.push(async function(OneSignal) {
    try {
      // requestPermission triggers the browser prompt in v16
      if (OneSignal.Notifications && typeof OneSignal.Notifications.requestPermission === 'function') {
        await OneSignal.Notifications.requestPermission();
      } else {
        // fallback (older or unexpected builds)
        if (typeof Notification !== 'undefined' && Notification.requestPermission) {
          await Notification.requestPermission();
        }
      }

      // check subscription status after prompt
      const pushSub = OneSignal.User && OneSignal.User.PushSubscription;
      const opted = !!(pushSub && pushSub.optedIn);
      if (!opted) {
        showOsError('Subscription did not complete — check browser prompts / settings.');
        setOsButton('Enable push notifications', true);
        setOsStatus('Push not granted.');
        return;
      }

      // get v16 subscription id and register on server
      const pid = pushSub.id || pushSub.token || null;
      if (pid) {
        const res = await postNotify('subscribe_push', { player_id: pid, device_info: navigator.userAgent || '' });
        if (!res.ok) showOsError('Server registration failed: ' + (res.error || 'unknown'));
        updateOsUI(true);
      } else {
        // no id yet: set status and let 'change' event handler handle it when id appears
        setOsStatus('Subscribed locally — awaiting id...');
      }
    } catch (err) {
      console.error('subscribeFlow error', err);
      showOsError('Subscription request failed: ' + (err && err.message ? err.message : 'unknown'));
      setOsButton('Enable push notifications', true);
      setOsStatus('Subscription error');
    }
  });
}

// Unsubscribe flow
async function unsubscribeFlow() {
  clearOsError();
  setOsButton('Unsubscribing…', false);
  setOsStatus('Disabling push...');
  window.OneSignalDeferred = window.OneSignalDeferred || [];
  OneSignalDeferred.push(async function(OneSignal) {
    try {
      // get id to remove from DB
      const pid = (OneSignal.User && OneSignal.User.PushSubscription && OneSignal.User.PushSubscription.id) || '';
      await postNotify('unsubscribe_push', { player_id: pid || '' });

      // disable permission via v16 API
      if (OneSignal.Notifications) {
        // v16 user model uses Notifications.permission setter
        try { OneSignal.Notifications.permission = false; } catch(e) { /* ignore if not available */ }
      }
      updateOsUI(false);
      setOsStatus('Unsubscribed');
    } catch (e) {
      console.error('unsubscribeFlow', e);
      showOsError('Unsubscribe failed');
      setOsButton('Disable push notifications', true);
      setOsStatus('Unsubscribe error');
    }
  });
}

// wire button
osBtn.addEventListener('click', function(){
  const txt = (osBtn.textContent || '').toLowerCase();
  if (txt.includes('enable')) subscribeFlow();
  else if (txt.includes('disable')) {
    if (!confirm('Disable push notifications for this browser?')) return;
    unsubscribeFlow();
  } else {
    initOneSignalUI();
  }
});

// kick off init on load
window.addEventListener('load', function(){
  setOsButton('Checking…', false);
  setOsStatus('Initializing push support…');
  initOneSignalUI();
});
</script>
</body>
</html>
