<?php
require "config.php";

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = "Enter username and password.";
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id, password FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                $error = "Unknown account. Register first or use guest entry.";
            } elseif (empty($user['password'])) {
                $error = "This account has no password set. Register or use guest entry.";
            } elseif (!password_verify($password, $user['password'])) {
                $error = "Invalid credentials.";
            } else {
                $token = bin2hex(random_bytes(32));
                $stmt = $pdo->prepare("UPDATE users SET auth_token = ? WHERE id = ?");
                $stmt->execute([$token, $user['id']]);

                $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                          || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

                setcookie("auth_token", $token, [
                    'expires' => time() + 86400 * 365,
                    'path' => '/',
                    'domain' => '',
                    'secure' => $secure,
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]);

                header("Location: room.php");
                exit;
            }
        } catch (PDOException $e) {
            $error = "Database error.";
        }
    }
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login — Motylo</title>
<link rel="icon" href="root/favicon.ico">
<style>
body{background:#0b0b0d;color:#fff;font-family:Inter,Arial,sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0}
.card{width:360px;background:#151518;padding:24px;border-radius:12px}
.input{width:100%;padding:10px;margin:8px 0;border-radius:8px;border:1px solid #222;background:#0f1012;color:#fff}
.btn{width:100%;padding:10px;border-radius:8px;border:0;background:#5865F2;color:#fff;cursor:pointer}
.small{color:#9aa3b2;font-size:13px;margin-top:10px}
.error{color:#ff7373}
.link{background:#222;padding:8px;border-radius:8px;color:#ddd;text-decoration:none;display:inline-block;margin-top:10px}
</style>
</head>
<body>
<div class="card">
  <h2>Login</h2>
  <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <form method="post">
    <input class="input" name="username" placeholder="Username" required>
    <input class="input" name="password" type="password" placeholder="Password" required>
    <button class="btn" type="submit">Login</button>
  </form>
  <div class="small">No account? <a href="register.php" class="link">Register</a> or <a href="index.php" class="link">enter as guest</a>.</div>
</div>
</body>
</html>
