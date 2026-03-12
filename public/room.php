<?php
// room.php - main channel skeleton + community nodes (blue circles) + preserved sidebar
require "config.php";

// --- auth ---
if (empty($_COOKIE['auth_token'])) { header("Location:index.php"); exit; }
$stmt = $pdo->prepare("SELECT id, username, avatar FROM users WHERE auth_token = ?");
$stmt->execute([$_COOKIE['auth_token']]);
$me = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$me) { header("Location:index.php"); exit; }
$me_id = (int)$me['id'];
$me_username = $me['username'];

// --- endpoints for saved rooms (same as before) ---
$action = $_GET['action'] ?? '';
if ($action === 'add_room' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_rooms (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        room_code VARCHAR(128) NOT NULL,
        name VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY ux_user_code (user_id, room_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $name = trim((string)($_POST['name'] ?? ''));
    $code = trim((string)($_POST['code'] ?? ''));
    header('Content-Type: application/json');
    if ($name === '' || $code === '') { echo json_encode(['error'=>'missing']); exit; }
    $stmt = $pdo->prepare("INSERT IGNORE INTO user_rooms (user_id, room_code, name) VALUES (?, ?, ?)");
    $stmt->execute([$me_id, $code, $name]);
    echo json_encode(['ok'=>true]); exit;
}
if ($action === 'list_rooms') {
    $stmt = $pdo->prepare("SELECT id, name, room_code FROM user_rooms WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$me_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: application/json'); echo json_encode($rows); exit;
}

// keep backward-compat mark endpoints (notifications)
if ($action === 'mark_read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id <= 0) { echo json_encode(['error'=>'invalid']); exit; }
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $me_id]);
    echo json_encode(['ok'=>true]); exit;
}
if ($action === 'mark_all' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$me_id]);
    echo json_encode(['ok'=>true]); exit;
}

/* ---------------- Communities pagination API ----------------
   Allows client to fetch communities in pages (10 per page).
   GET: room.php?action=communities_page&page=1
   Returns: json array of {id, slug, name, description, public_id, visual_x, visual_y, logo, member_count}
*/
if ($action === 'communities_page') {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 10;
    $offset = ($page - 1) * $limit;
    $stmt = $pdo->prepare("SELECT c.id, c.slug, c.name, c.description, c.public_id, c.visual_x, c.visual_y, c.logo,
                                 (SELECT COUNT(*) FROM community_members cm WHERE cm.community_id = c.id) AS member_count
                          FROM communities c
                          ORDER BY c.id ASC
                          LIMIT ? OFFSET ?");
    // need to bind as ints
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo json_encode(['ok'=>true,'page'=>$page,'rows'=>$rows]);
    exit;
}

// --- fetch friends (preserve sidebar) ---
$friends = [];
try {
    $sql = "
      SELECT u.id,u.username,u.avatar
      FROM users u
      WHERE u.id IN (
        SELECT CASE WHEN f.user_a = :me THEN f.user_b ELSE f.user_a END
        FROM friendships f
        WHERE (f.user_a = :me OR f.user_b = :me) AND f.status = 'friends'
      )
      ORDER BY u.username
      LIMIT 200
    ";
    $st = $pdo->prepare($sql);
    $st->execute([':me'=>$me_id]);
    $friends = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $friends = [];
}

// saved rooms
$saved_rooms = [];
try {
    $stmt = $pdo->prepare("SELECT id, name, room_code FROM user_rooms WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$me_id]);
    $saved_rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $saved_rooms = [];
}

// ---------------------- ensure schema columns exist (public_id, visual_x, visual_y, logo) --------------
try {
    $t = $pdo->query("SHOW TABLES LIKE 'communities'")->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($t)) {
        $cols = $pdo->query("SHOW COLUMNS FROM communities")->fetchAll(PDO::FETCH_COLUMN);
        $cols_l = array_map('strtolower',$cols);
        if (!in_array('public_id',$cols_l)) $pdo->exec("ALTER TABLE communities ADD COLUMN public_id VARCHAR(20) DEFAULT NULL UNIQUE");
        if (!in_array('visual_x',$cols_l)) $pdo->exec("ALTER TABLE communities ADD COLUMN visual_x INT DEFAULT NULL");
        if (!in_array('visual_y',$cols_l)) $pdo->exec("ALTER TABLE communities ADD COLUMN visual_y INT DEFAULT NULL");
        if (!in_array('logo',$cols_l)) $pdo->exec("ALTER TABLE communities ADD COLUMN logo VARCHAR(255) DEFAULT NULL");
        // backfill missing public_id
        $sr = $pdo->prepare("SELECT id FROM communities WHERE public_id IS NULL OR public_id = ''");
        $sr->execute();
        $miss = $sr->fetchAll(PDO::FETCH_COLUMN);
        foreach ($miss as $cid) {
            $tries = 0;
            do {
                $pub = strval(mt_rand(1000000000, 2147483646));
                $q = $pdo->prepare("SELECT COUNT(*) FROM communities WHERE public_id = ?");
                $q->execute([$pub]);
                $count = (int)$q->fetchColumn();
                $tries++;
            } while ($count > 0 && $tries < 20);
            if ($tries < 20) {
                $u = $pdo->prepare("UPDATE communities SET public_id = ? WHERE id = ?");
                $u->execute([$pub, $cid]);
            }
        }
        // backfill missing positions randomly if missing
        $sr2 = $pdo->prepare("SELECT id FROM communities WHERE visual_x IS NULL OR visual_y IS NULL");
        $sr2->execute();
        $miss2 = $sr2->fetchAll(PDO::FETCH_COLUMN);
        foreach ($miss2 as $cid) {
            $vx = random_int(80,1100);
            $vy = random_int(80,620);
            $u2 = $pdo->prepare("UPDATE communities SET visual_x = ?, visual_y = ? WHERE id = ?");
            $u2->execute([$vx, $vy, $cid]);
        }
    }
} catch (Exception $e) {
    // ignore schema errors but continue
}

// ---------------------- fetch initial communities (only first page, 10) --------------------
$communities = [];
try {
    $sql = "SELECT c.id, c.slug, c.name, c.description, c.public_id, c.visual_x, c.visual_y, c.logo,
                   (SELECT COUNT(*) FROM community_members cm WHERE cm.community_id = c.id) AS member_count
            FROM communities c
            ORDER BY c.id ASC
            LIMIT 10";
    $st = $pdo->prepare($sql);
    $st->execute();
    $communities = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $communities = [];
}

// helper: make initials from name
function initials($s) {
    $s = trim($s);
    if ($s === '') return '?';
    $parts = preg_split('/\s+/', $s);
    if (count($parts) === 1) return strtoupper(mb_substr($parts[0],0,1));
    return strtoupper(mb_substr($parts[0],0,1) . mb_substr(end($parts),0,1));
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Rooms — <?= htmlspecialchars($me_username) ?></title>
<link rel="icon" href="root/favicon.ico">
<style>
:root{--bg:#0d1114;--panel:#0f1518;--accent:#3f7bff;--muted:#bfc9d9}
html,body{height:100%;margin:0;background:var(--bg);color:#eef3ff;font-family:Inter,Arial,Helvetica,sans-serif}
.container{display:flex;height:100vh;gap:18px;padding:18px;box-sizing:border-box}
.sidebar{width:280px;background:var(--panel);border-radius:10px;padding:12px;display:flex;flex-direction:column;gap:12px}
.main{flex:1;display:flex;align-items:center;justify-content:center}
.friendsList{display:flex;flex-direction:column;gap:8px;overflow:auto;padding-right:6px}
.friendItem{display:flex;gap:10px;align-items:center;padding:8px;border-radius:8px;background:rgba(255,255,255,0.02);cursor:pointer}
.friendItem img{width:48px;height:48px;border-radius:8px;object-fit:cover;border:2px solid #0b0f12}
.header{display:flex;justify-content:space-between;align-items:center;padding:8px 12px;position:relative} /* position relative so dropdown is absolute and won't push layout */
.bell{position:relative;cursor:pointer;padding:8px;border-radius:8px;background:rgba(0,0,0,0.10)}
.badge{position:absolute;top:-6px;right:-6px;background:#ff4d4f;color:white;border-radius:12px;padding:2px 6px;font-size:12px}

/* Notification dropdown style */
.notifBox{position:absolute;right:12px;top:48px;z-index:9999;width:360px;background:#0b0f12;border-radius:8px;box-shadow:0 10px 40px rgba(0,0,0,0.6);overflow:hidden}

/* Graph / node styles */
.graphCanvas{width:100%;height:72vh;border-radius:12px;background:linear-gradient(180deg, rgba(255,255,255,0.01), transparent);position:relative;overflow:auto;padding:12px;box-sizing:border-box} /* overflow:auto to allow scroll-based lazy loading */
.node-circle{position:absolute;width:80px;height:80px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;cursor:pointer;box-shadow:0 10px 40px rgba(0,0,0,0.45);transition:transform .12s}
.node-circle .logo{width:76px;height:76px;border-radius:50%;display:flex;align-items:center;justify-content:center;overflow:hidden;font-size:28px}
.node-circle .logo img{width:100%;height:100%;object-fit:cover;display:block}
.node-circle:hover{transform:translateY(-6px) scale(1.03)}
.nodeLabel{position:absolute;left:0;right:0;text-align:center;font-size:13px;margin-top:88px;color:var(--muted)}

/* ensure blue default */
.node-blue{background:linear-gradient(180deg,#3f7bff,#2a59d9)}

/* channel hint */
.centerHint{color:var(--muted);font-size:13px}
.topBtn{background:var(--accent);color:#fff;border:0;border-radius:6px;padding:7px 10px;cursor:pointer}
.topBtn:hover{filter:brightness(.95)}
.savedRooms{display:flex;flex-direction:column;gap:8px;max-height:200px;overflow:auto;padding:6px}
.roomItem{padding:8px;border-radius:8px;background:rgba(255,255,255,0.02);cursor:pointer}
.small{color:var(--muted);font-size:13px}
.empty{color:var(--muted);padding:8px}
.legend{display:flex;gap:8px;align-items:center}
.legend .dot{width:12px;height:12px;border-radius:4px;background:var(--accent)}
@media (max-width:880px){ .container{flex-direction:column;padding:12px} .sidebar{width:100%} .graphCanvas{height:60vh} }
</style>
</head>
<body>

<div class="header" style="padding:12px;">
  <div style="font-weight:800;font-size:18px">Rooms</div>
  <div style="display:flex;gap:12px;align-items:center">

    <!-- quick actions -->
    <button class="topBtn" onclick="location.href='mobile_room.php?code=general'">📱 Mobile UI</button>
    <button class="topBtn" onclick="location.href='settings.php?code=general'">⚙️</button>

    <!-- Create community button -->
    <button id="createCommunityBtn" class="topBtn" title="Create community">➕ Create Community</button>

    <!-- Notification bell + badge (desktop) -->
    <div id="bell" class="bell" title="Notifications">🔔<span id="notifBadge" class="badge" style="display:none">0</span></div>

    <!-- Notification dropdown (initially hidden) -->
    <div id="notifDropdown" class="notifBox" aria-hidden="true" style="display:none;">
      <div id="notifList" style="max-height:320px;overflow:auto;padding:8px">
        <div style="padding:12px;color:var(--muted)">Loading…</div>
      </div>
      <div style="padding:8px;border-top:1px solid rgba(255,255,255,0.03);display:flex;gap:8px;align-items:center;justify-content:space-between">
        <div style="font-size:13px;color:var(--muted)">Notifications</div>
        <div>
          <button id="markAllBtn" class="topBtn" style="padding:6px 8px;font-size:13px">Mark all read</button>
        </div>
      </div>
    </div>

    <!-- sounds (keep IDs consistent with message.php) -->
    <audio id="dm_bell" preload="auto"><source src="root/bell.mp3" type="audio/mpeg"></audio>
    <audio id="notif_bell" preload="auto"><source src="root/bell_2.mp3" type="audio/mpeg"></audio>

  </div>
</div>

<!-- Create community modal (hidden). Simple, small form overlay. -->
<div id="createCommunityModal" style="display:none;position:fixed;left:0;right:0;top:0;bottom:0;background:rgba(0,0,0,0.4);align-items:center;justify-content:center;z-index:9999">
  <div style="width:420px;max-width:92%;background:#0f1214;border-radius:10px;padding:14px">
    <div style="font-weight:800;margin-bottom:8px">Create community</div>
    <div style="color:var(--muted);font-size:13px;margin-bottom:10px">Create a new community. You'll be the owner by default.</div>
    <form id="createCommunityForm">
      <input name="name" placeholder="Community name" required style="width:100%;padding:8px;border-radius:8px;border:0;background:#090b0d;color:#fff;margin-bottom:8px">
      <input name="slug" placeholder="Optional slug (alphanum-and-dashes)" style="width:100%;padding:8px;border-radius:8px;border:0;background:#090b0d;color:#fff;margin-bottom:8px">
      <textarea name="description" placeholder="Optional description" style="width:100%;padding:8px;border-radius:8px;border:0;background:#090b0d;color:#fff;margin-bottom:8px;height:80px"></textarea>
      <div style="display:flex;gap:8px">
        <button type="submit" class="topBtn" style="flex:1">Create</button>
        <button type="button" id="createCommunityCancel" class="topBtn" style="background:transparent;border:1px solid rgba(255,255,255,.04);color:var(--muted)">Cancel</button>
      </div>
    </form>
  </div>
</div>


<div class="container">
  <aside class="sidebar">
    <div style="font-weight:800">Friends</div>
    <div class="friendsList" id="friendsList">
      <?php foreach($friends as $f): ?>
        <div class="friendItem" onclick="location.href='message.php?user=<?= rawurlencode($f['username']) ?>'">
          <?php if (!empty($f['avatar'])): ?>
            <?php if (stripos($f['avatar'],'http') === 0): ?>
              <img src="<?= htmlspecialchars($f['avatar'],ENT_QUOTES) ?>" alt="">
            <?php else: ?>
              <img src="avatars/<?= rawurlencode($f['avatar']) ?>" alt="">
            <?php endif; ?>
          <?php else: ?>
            <img src="root/default_avatar.png" alt="">
          <?php endif; ?>
          <div class="name"><?= htmlspecialchars($f['username']) ?></div>
        </div>
      <?php endforeach; ?>
      <?php if (empty($friends)): ?>
        <div style="color:var(--muted)">No friends yet</div>
      <?php endif; ?>
    </div>

    <div style="margin-top:8px;font-weight:700">Saved Private Rooms</div>
    <div class="savedRooms" id="savedRooms">
      <?php if (!empty($saved_rooms)): foreach($saved_rooms as $r): ?>
        <div class="roomItem" onclick="location.href='private.php?code=<?= rawurlencode($r['room_code'] ?? $r['id'] . '_' . $me_id) ?>'">
          <?= htmlspecialchars($r['name']) ?>
        </div>
      <?php endforeach; else: ?>
        <div style="color:var(--muted)">No saved rooms</div>
      <?php endif; ?>
    </div>

    <div style="margin-top:auto">
      <div style="font-size:13px;color:var(--muted);margin-bottom:8px">Add a room</div>
      <form id="addRoomForm">
        <input name="name" placeholder="Room display name" style="width:100%;padding:8px;border-radius:8px;border:0;margin-bottom:8px;background:#0b0f12;color:#fff" required>
        <input name="code" placeholder="Room code" style="width:100%;padding:8px;border-radius:8px;border:0;margin-bottom:8px;background:#0b0f12;color:#fff" required>
        <button style="width:100%;padding:8px;border-radius:8px;background:var(--accent);border:0;margin-bottom:80px;color:#fff" type="submit">Save</button>
      </form>
    </div>
  </aside>

  <main class="main">
    <div style="width:100%;max-width:1200px">
      <div class="graphCanvas" id="graphCanvas">
        <!-- connections will be drawn with <svg> overlay -->
        <svg id="graphSvg" style="position:absolute;left:12px;top:12px;width:calc(100% - 24px);height:calc(100% - 24px);pointer-events:none"></svg>
        <!-- nodes (initial page rendered server-side) -->
        <?php
          foreach ($communities as $c):
            $pub = htmlspecialchars($c['public_id'] ?? '');
            $logo = htmlspecialchars($c['logo'] ?? '');
            $name = htmlspecialchars($c['name'] ?? 'Community');
            $initials = initials($c['name'] ?? '');
        ?>
          <div class="node-circle node-blue" data-id="<?= (int)$c['id'] ?>" data-public="<?= $pub ?>" data-logo="<?= $logo ?>" data-name="<?= htmlspecialchars($c['name'] ?? '') ?>" style="left:10px;top:10px">
            <div class="logo">
              <?php if (!empty($c['logo'])): ?>
                <?php if (stripos($c['logo'],'http') === 0): ?>
                  <img src="<?= htmlspecialchars($c['logo'],ENT_QUOTES) ?>" alt="">
                <?php else: ?>
                  <img src="uploads/community_logos/<?= rawurlencode($c['logo']) ?>" alt="">
                <?php endif; ?>
              <?php else: ?>
                <div style="width:76px;height:76px;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,0.06);border-radius:50%"><?= htmlspecialchars($initials) ?></div>
              <?php endif; ?>
            </div>
            <div class="nodeLabel"><?= $name ?></div>
          </div>
        <?php endforeach; ?>
      </div>

      <div style="margin-top:12px;display:flex;justify-content:center">
        <div class="centerHint">Click any blue node to open the community (it will take you to <code>community.php?public_id=...</code>)</div>
      </div>
    </div>
  </main>
</div>

<script>
/* ---------- Node layout + lazy load + bell dropdown (improved spacing + deep-linking) ---------- */
(function(){
  const canvas = document.getElementById('graphCanvas');
  const svg = document.getElementById('graphSvg');
  const notifBtn = document.getElementById('bell');
  const notifDropdown = document.getElementById('notifDropdown');
  const notifList = document.getElementById('notifList');
  const notifBadge = document.getElementById('notifBadge');
  const MARK_ALL = document.getElementById('markAllBtn');

  // Keep a live array of node elements
  let nodes = Array.from(document.querySelectorAll('.node-circle'));
  let page = 1; // initial page already rendered is page 1
  let loadingPage = false;
  let lastFetchedEmpty = false;

  // ---- Positioning: sunflower + collision resolver for more spacing ----
  function computeSpiralPosition(i, centerX, centerY, scale) {
    const goldenAngle = 2.399963229728653; // ~137.5deg
    const angle = i * goldenAngle;
    const r = scale * Math.sqrt(i + 1);
    return {
      x: centerX + r * Math.cos(angle),
      y: centerY + r * Math.sin(angle)
    };
  }

  function buildList() {
    nodes = Array.from(document.querySelectorAll('.node-circle'));
    nodes.forEach((n, idx) => {
      if (!n.dataset.bound) {
        n.addEventListener('click', ()=> {
          const pub = n.dataset.public || '';
          if (!pub) {
            const id = n.dataset.id || '';
            location.href = 'community.php?id=' + encodeURIComponent(id);
          } else {
            location.href = 'community.php?public_id=' + encodeURIComponent(pub);
          }
        });
        n.dataset.bound = '1';
      }
    });
  }

  function resolveCollisions(nodeEls, bounds) {
    const minDist = 160; // larger spacing to avoid clustering
    let moved = true;
    let iter = 0;
    const maxIter = 150;

    function clampPos(x, y) {
      const left = 12, top = 12;
      const right = bounds.width - 12;
      const bottom = bounds.height - 12;
      return {
        x: Math.max(left, Math.min(right, x)),
        y: Math.max(top, Math.min(bottom, y))
      };
    }

    while (moved && iter < maxIter) {
      moved = false;
      iter++;
      for (let i = 0; i < nodeEls.length; i++) {
        for (let j = i + 1; j < nodeEls.length; j++) {
          const a = nodeEls[i], b = nodeEls[j];
          const ax = parseFloat(a.style.left || a.offsetLeft) + 40;
          const ay = parseFloat(a.style.top || a.offsetTop) + 40;
          const bx = parseFloat(b.style.left || b.offsetLeft) + 40;
          const by = parseFloat(b.style.top || b.offsetTop) + 40;
          let dx = bx - ax;
          let dy = by - ay;
          let dist = Math.sqrt(dx * dx + dy * dy) || 0.001;
          const overlap = minDist - dist;
          if (overlap > 0) {
            const ux = dx / dist;
            const uy = dy / dist;
            const shift = overlap * 0.55;
            let nax = (ax - 40) - ux * shift;
            let nay = (ay - 40) - uy * shift;
            let nbx = (bx - 40) + ux * shift;
            let nby = (by - 40) + uy * shift;

            const na = clampPos(nax + 40, nay + 40);
            const nb = clampPos(nbx + 40, nby + 40);
            nax = na.x - 40; nay = na.y - 40;
            nbx = nb.x - 40; nby = nb.y - 40;

            a.style.left = Math.round(nax) + 'px';
            a.style.top = Math.round(nay) + 'px';
            b.style.left = Math.round(nbx) + 'px';
            b.style.top = Math.round(nby) + 'px';
            moved = true;
          }
        }
      }
    }
    return iter;
  }

  function draw() {
    buildList();
    while (svg.firstChild) svg.removeChild(svg.firstChild);

    const rect = canvas.getBoundingClientRect();
    const width = Math.max(400, rect.width - 24);
    const height = Math.max(300, rect.height - 24);
    const centerX = width / 2 + 12;
    const centerY = height / 2 + 12;

    // bigger scale divisor => more spread
    const scale = Math.min(width, height) / 8;

    nodes.forEach((n, idx) => {
      const pos = computeSpiralPosition(idx, centerX, centerY, scale);
      n.style.left = (pos.x - 40) + 'px';
      n.style.top = (pos.y - 40) + 'px';
    });

    // expand spacing with collision resolver
    resolveCollisions(nodes, { width, height });

    // light neighbor lines
    nodes.forEach((a, ia) => {
      const rectA = a.getBoundingClientRect();
      const ax = rectA.left + rectA.width/2 - rect.left;
      const ay = rectA.top + rectA.height/2 - rect.top;
      for (let j = ia+1; j < Math.min(nodes.length, ia + 4); j++) {
        const b = nodes[j];
        const rectB = b.getBoundingClientRect();
        const bx = rectB.left + rectB.width/2 - rect.left;
        const by = rectB.top + rectB.height/2 - rect.top;
        const line = document.createElementNS('http://www.w3.org/2000/svg','line');
        line.setAttribute('x1', ax);
        line.setAttribute('y1', ay);
        line.setAttribute('x2', bx);
        line.setAttribute('y2', by);
        line.setAttribute('stroke', 'rgba(255,255,255,0.03)');
        line.setAttribute('stroke-width', '1.0');
        svg.appendChild(line);
      }
    });
  }

  setTimeout(draw, 80);
  setTimeout(draw, 350);
  window.addEventListener('resize', ()=> { setTimeout(draw, 80); });

  // ---- Lazy load more communities on scroll ----
  async function fetchPage(p) {
    if (loadingPage || lastFetchedEmpty) return;
    loadingPage = true;
    try {
      const r = await fetch('room.php?action=communities_page&page=' + encodeURIComponent(p), { credentials: 'same-origin' });
      const j = await r.json();
      if (!j || !j.ok || !Array.isArray(j.rows) || j.rows.length === 0) {
        lastFetchedEmpty = true;
        loadingPage = false;
        return;
      }
      for (const c of j.rows) {
        if (document.querySelector('.node-circle[data-id="' + (c.id) + '"]')) continue;
        const node = document.createElement('div');
        node.className = 'node-circle node-blue';
        node.setAttribute('data-id', c.id);
        node.setAttribute('data-public', c.public_id || '');
        node.setAttribute('data-name', c.name || '');
        node.setAttribute('data-logo', c.logo || '');
        node.style.left = '10px';
        node.style.top = '10px';
        const logoWrap = document.createElement('div'); logoWrap.className='logo';
        if (c.logo) {
          const img = document.createElement('img');
          img.src = /^https?:\/\//i.test(c.logo) ? c.logo : 'uploads/community_logos/' + encodeURIComponent(c.logo);
          logoWrap.appendChild(img);
        } else {
          const initials = document.createElement('div');
          initials.style.width='76px'; initials.style.height='76px'; initials.style.display='flex';
          initials.style.alignItems='center'; initials.style.justifyContent='center';
          initials.style.borderRadius='50%'; initials.style.background='rgba(255,255,255,0.06)';
          initials.textContent = (c.name||'').split(/\s+/).map(p=>p[0]).slice(0,2).join('') || '?';
          logoWrap.appendChild(initials);
        }
        node.appendChild(logoWrap);
        const label = document.createElement('div'); label.className='nodeLabel'; label.textContent = c.name || 'Community';
        node.appendChild(label);
        node.addEventListener('click', ()=> {
          if (c.public_id) location.href = 'community.php?public_id=' + encodeURIComponent(c.public_id);
          else location.href = 'community.php?id=' + encodeURIComponent(c.id);
        });
        canvas.appendChild(node);
      }
      nodes = Array.from(document.querySelectorAll('.node-circle'));
      draw();
      loadingPage = false;
    } catch (e) {
      console.error('failed to fetch page', e);
      loadingPage = false;
    }
  }

  canvas.addEventListener('scroll', ()=> {
    const s = canvas.scrollTop;
    const h = canvas.scrollHeight - canvas.clientHeight;
    if (h <= 0) return;
    if ((s / h) > 0.75) {
      page++;
      fetchPage(page);
    }
  });

  // ---- Notifications (bell) ----
  const API = 'notifications.php';
  const POLL_MS = 30000;
  let pollTimer = null;

  async function fetchNotifications(limit=5) {
    try {
      const r = await fetch(API + '?limit=' + encodeURIComponent(limit), { credentials: 'same-origin' });
      if (!r.ok) return null;
      return await r.json();
    } catch (e) { return null; }
  }

  async function refreshBadge(){
    const j = await fetchNotifications(5);
    if (!j || !Array.isArray(j.notifications)) return;
    const unread = j.notifications.filter(n=>!n.is_read).length;
    if (unread > 0) { notifBadge.style.display='inline-block'; notifBadge.textContent = unread > 99 ? '99+' : String(unread); }
    else { notifBadge.style.display='none'; }
  }

  async function loadNotifList(){
    notifList.innerHTML = '<div style="padding:12px;color:var(--muted)">Loading…</div>';
    const j = await fetchNotifications(200);
    if (!j || !Array.isArray(j.notifications) || j.notifications.length === 0) {
      notifList.innerHTML = '<div style="padding:12px;color:var(--muted)">No notifications</div>';
      return;
    }
    notifList.innerHTML = '';
    for (const n of j.notifications) {
      const g = document.createElement('div');
      g.className = 'notifGroup';
      g.style.display = 'flex'; g.style.gap = '10px'; g.style.padding = '8px'; g.style.alignItems = 'center'; g.style.borderRadius = '8px';
      g.style.cursor = 'pointer';
      const avatar = document.createElement('div'); avatar.className='avatar'; avatar.style.flex='0 0 44px'; avatar.style.width='44px'; avatar.style.height='44px'; avatar.style.borderRadius='8px'; avatar.style.display='flex'; avatar.style.alignItems='center'; avatar.style.justifyContent='center'; avatar.style.background='linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.00))'; avatar.style.color='#fff';
      avatar.textContent = (n.source_username || (n.type||'')[0] || '•').slice(0,1).toUpperCase();
      const meta = document.createElement('div'); meta.style.flex='1'; meta.style.minWidth='0';
      const t = document.createElement('div'); t.className='title'; t.textContent = (n.source_username ? n.source_username + ' — ' : '') + (n.type || 'Notification');
      t.style.fontWeight='700'; t.style.overflow='hidden'; t.style.textOverflow='ellipsis'; t.style.whiteSpace='nowrap';
      const m = document.createElement('div'); m.className='msg'; m.textContent = n.message || ''; m.style.color='var(--muted)'; m.style.fontSize='13px'; m.style.overflow='hidden'; m.style.textOverflow='ellipsis'; m.style.whiteSpace='nowrap';
      meta.appendChild(t); meta.appendChild(m);
      g.appendChild(avatar); g.appendChild(meta);

      g.addEventListener('click', async (ev)=>{
        ev.preventDefault();
        try {
          await fetch(API, { method:'POST', credentials:'same-origin', body: new URLSearchParams({ action: 'mark_read', id: n.id }) });
        } catch(e){}
        // Deep-linking: prefer ref_code, then DM -> message.php, then ref_id fallback
        const type = (n.type || '').toString().toLowerCase();
        const srcUser = n.source_username || n.actor_username || n.ref_user || null;
        const refCode = n.ref_code || n.ref || n.code || null;
        const refId = n.ref_id || n.target_id || null;

        if (refCode) {
          location.href = 'private.php?code=' + encodeURIComponent(refCode);
          return;
        }

        if (type.includes('dm') || type.includes('direct') || (srcUser && type === '')) {
          if (srcUser) {
            location.href = 'message.php?user=' + encodeURIComponent(srcUser);
            return;
          }
        }

        if (refId) {
          location.href = 'private.php?code=' + encodeURIComponent(refId);
          return;
        }

        if (srcUser) {
          location.href = 'message.php?user=' + encodeURIComponent(srcUser);
          return;
        }

        location.reload();
      });

      notifList.appendChild(g);
    }
  }

  function toggleDropdown(force){
    const cur = notifDropdown.style.display === 'block';
    const want = (typeof force === 'boolean') ? force : !cur;
    notifDropdown.style.display = want ? 'block' : 'none';
    if (want) loadNotifList();
  }

  notifBtn && notifBtn.addEventListener('click', (e)=>{
    e.stopPropagation();
    toggleDropdown();
  });
  document.addEventListener('click', (e)=>{
    if (!e.target.closest('#notifDropdown') && !e.target.closest('#bell')) toggleDropdown(false);
  });
  MARK_ALL && MARK_ALL.addEventListener('click', async ()=>{
    try {
      await fetch('notifications.php?action=mark_all', { method:'POST', credentials:'same-origin' });
      notifBadge.style.display='none';
      loadNotifList();
    } catch (e) {}
  });

  // Kickstart
  refreshBadge();
  pollTimer = setInterval(refreshBadge, POLL_MS);

  // --- Saved rooms form wiring (AJAX) ---
  const addRoomForm = document.getElementById('addRoomForm');
  if (addRoomForm) {
    addRoomForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const f = new FormData(addRoomForm);
      try {
        const r = await fetch('?action=add_room', { method: 'POST', credentials:'same-origin', body: f });
        const j = await r.json();
        if (j && j.ok) {
          // refresh list server-side
          const listRes = await fetch('?action=list_rooms', { credentials:'same-origin' });
          const listJson = await listRes.json();
          const list = document.getElementById('savedRooms');
          list.innerHTML = '';
          if (Array.isArray(listJson) && listJson.length) {
            listJson.forEach(item => {
              const it = document.createElement('div');
              it.className = 'roomItem';
              it.textContent = item.name;
              it.style.cursor = 'pointer';
              it.addEventListener('click', ()=> location.href = 'private.php?code=' + encodeURIComponent(item.room_code));
              list.appendChild(it);
            });
          } else {
            list.innerHTML = '<div style="color:var(--muted)">No saved rooms</div>';
          }
          addRoomForm.reset();
        } else {
          alert('Failed to save room');
        }
      } catch (err) {
        alert('Failed to save room');
      }
    });
  }

  // --- Create community UI wiring ---
  const createBtn = document.getElementById('createCommunityBtn');
  const modal = document.getElementById('createCommunityModal');
  const createForm = document.getElementById('createCommunityForm');
  const cancelBtn = document.getElementById('createCommunityCancel');

  createBtn && createBtn.addEventListener('click', ()=> { modal.style.display = 'flex'; });
  cancelBtn && cancelBtn.addEventListener('click', ()=> { modal.style.display = 'none'; });

  if (createForm) {
    createForm.addEventListener('submit', async (ev)=>{
      ev.preventDefault();
      const fd = new FormData(createForm);
      try {
        const r = await fetch('community_interface.php?action=create_community', { method:'POST', credentials:'same-origin', body: fd });
        const j = await r.json();
        if (j && j.ok && j.id) {
          modal.style.display = 'none';
          location.reload();
        } else {
          alert('Failed to create community: ' + (j && j.error ? j.error : 'unknown'));
        }
      } catch (e) {
        alert('Failed to create community');
      }
    });
  }

})();
</script>
</body>
</html>
