<?php
require "config.php";

// redirect if already logged in
if (!empty($_COOKIE["auth_token"])) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE auth_token = ?");
    $stmt->execute([$_COOKIE["auth_token"]]);
    if ($stmt->fetch()) {
        header("Location: mobile_room.php");
        exit;
    }
}

$error = "";

// quick guest flow (preserves previous behaviour) - fixed cookie logic
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guest_username'])) {
    $username = trim((string)($_POST['guest_username'] ?? ''));
    if ($username === '') {
        $error = "Please enter a username.";
    } else {
        // basic sanitisation: limit length and allowed chars (you can relax if needed)
        if (mb_strlen($username) > 32) $username = mb_substr($username, 0, 32);
        // compute secure flag before setting cookies
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                  || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        $token = bin2hex(random_bytes(32));
        try {
            // check existing user (if registered with password we block guest reuse)
            $stmt = $pdo->prepare("SELECT id, password, password_hash FROM users WHERE username = ? LIMIT 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && !empty($user['password'] || $user['password_hash'])) {
                // username taken by registered account
                $error = "That username is registered. Please log in.";
            } else {
                if ($user) {
                    // existing user row without password: update auth_token
                    $stmt = $pdo->prepare("UPDATE users SET auth_token = ? WHERE id = ?");
                    $stmt->execute([$token, $user['id']]);
                    $userId = (int)$user['id'];
                } else {
                    // create new user row
                    $stmt = $pdo->prepare("INSERT INTO users (username, auth_token) VALUES (?, ?)");
                    $stmt->execute([$username, $token]);
                    $userId = (int)$pdo->lastInsertId();
                }

                // set cookie once (uses $secure computed earlier)
                setcookie("auth_token", $token, [
                    'expires' => time() + 86400 * 365,
                    'path' => '/',
                    'secure' => $secure,
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]);

                header("Location: mobile_room.php");
                exit;
            }
        } catch (PDOException $e) {
            // Do not leak DB internals in production — but keep message useful for dev
            $error = "Database error: " . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Motylo — Enter</title>
<link rel="icon" href="root/favicon.ico">
<style>
:root{
  --bg:#0b0b0d;
  --panel:#151518;
  --accent:#5865F2;
  --muted:#9aa3b2;
  --card-max:900px;
}
html,body{height:100%;margin:0;background:var(--bg);font-family:Inter,Arial,Helvetica,sans-serif;color:#fff}
.center{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:28px}
.card{width:100%;max-width:var(--card-max);background:var(--panel);border-radius:12px;padding:26px;box-shadow:0 6px 20px rgba(0,0,0,.6);text-align:center;box-sizing:border-box}
.logo{display:flex;align-items:center;justify-content:center;gap:30px;margin-bottom:12px;margin:10px}
.logo img{width:200px;height:200px;flex:0 0 auto}
.logo-text{ text-align:left }
.h1{font-size:48px;font-weight:700;margin:0;line-height:1}
.h1.small{font-size:28px}
.lead{color:var(--muted);margin-bottom:8px;font-size:20px;}
.after{color:var(--muted);margin-bottom:18px;font-size:16px;max-width:520px}
.input{width:100%;padding:10px;border-radius:8px;border:1px solid #222;background:#0f1012;color:#fff;margin:8px 0;box-sizing:border-box}
.btn{width:100%;padding:10px;border-radius:8px;border:0;background:var(--accent);color:#fff;cursor:pointer;margin-top:8px}
.smallbtn{display:inline-block;padding:8px 12px;border-radius:8px;border:0;background:#222;color:#ddd;cursor:pointer;margin:6px 4px;text-decoration:none}
.error{color:#ff7b7b;margin:8px 0}
.links{display:flex;gap:8px;justify-content:center;margin-top:10px;flex-wrap:wrap}
.note{font-size:13px;color:var(--muted);margin-top:10px}

/* ========== MOBILE ADJUSTMENTS ========== */
/* Keep CSS conservative — only hide the long text next to favicon and make card responsive */
@media (max-width:720px) {
  .card{padding:18px;border-radius:10px}
  .logo{flex-direction:column;gap:8px}
  /* shrink the favicon */
  .logo img{width:72px;height:72px}
  /* hide the long text block next to favicon on phones */
  .logo-text{display:none}
  /* slightly reduce heading size so layout fits */
  .h1{font-size:28px}
  .lead, .after{font-size:14px}
  .btn{padding:12px}
  .input{font-size:16px}
}
</style>
</head>
<body>
<div class="center">
  <div class="card" role="main" aria-labelledby="welcome-title">
    <div class="logo" aria-hidden="false">
      <img src="root/favicon.ico" alt="Motylo logo">
      <div class="logo-text" aria-hidden="true">
        <div id="welcome-title" class="h1">Hello there!</div>
        <div class="h1 small">Welcome!</div>
        <div class="lead"></div>
        <div class="after">Motylo is a small chat room service with loads of features. Come and say hi! Made and maintained by Solos.</div>
      </div>
    </div>

    <?php if ($error): ?>
      <div class="error" role="status"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" style="margin-bottom:6px" aria-label="Enter as guest">
      <input class="input" name="guest_username" placeholder="Pick a username (quick entry)" required aria-required="true" aria-label="Guest username">
      <button class="btn" type="submit">Enter as guest</button>
    </form>

    <div class="links" role="navigation" aria-label="Account links">
      <a href="login.php" class="smallbtn">Login</a>
      <a href="register.php" class="smallbtn">Register</a>
      <a href="blog.php" class="smallbtn">I'm just here for the blog</a>
    </div>

    <div class="note" role="note">Register gives password protection. Guest accounts don't have passwords so anyone can use them!</div>
  </div>
</div>
</body>
</html>
