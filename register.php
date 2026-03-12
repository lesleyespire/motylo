<?php
require "config.php";

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';

    if ($username === '' || $password === '' || $password2 === '') {
        $error = "Complete all fields.";
    } elseif ($password !== $password2) {
        $error = "Passwords do not match.";
    } elseif (mb_strlen($username) < 2 || mb_strlen($username) > 32) {
        $error = "Username must be 2-32 characters.";
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id, password FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            $hash = password_hash($password, PASSWORD_DEFAULT);

            $token = bin2hex(random_bytes(32));
            if ($user) {
                if (!empty($user['password'])) {
                    $error = "Username taken. Choose another or login.";
                } else {
                    // existing account without password: set password + auth_token
                    $stmt = $pdo->prepare("UPDATE users SET password = ?, auth_token = ? WHERE id = ?");
                    $stmt->execute([$hash, $token, $user['id']]);
                    $success = true;
                }
            } else {
                $stmt = $pdo->prepare("INSERT INTO users (username, password, auth_token) VALUES (?, ?, ?)");
                $stmt->execute([$username, $hash, $token]);
            }

            if (empty($error)) {
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
<title>Register — Motylo</title>
<link rel="icon" href="root/favicon.ico">
<style>
body{background:#0b0b0d;color:#fff;font-family:Inter,Arial,sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0}
.card{width:380px;background:#151518;padding:24px;border-radius:12px}
.input{width:100%;padding:10px;margin:8px 0;border-radius:8px;border:1px solid #222;background:#0f1012;color:#fff}
.btn{width:100%;padding:10px;border-radius:8px;border:0;background:#5865F2;color:#fff;cursor:pointer}
.small{color:#9aa3b2;font-size:13px;margin-top:10px}
.error{color:#ff7373}
</style>
</head>
<body>
<div class="card">
  <h2>Create account</h2>
  <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <form method="post">
    <input class="input" name="username" placeholder="Username" required>
    <input class="input" name="password" type="password" placeholder="Password" required>
    <input class="input" name="password2" type="password" placeholder="Confirm password" required>
    <button class="btn" type="submit">Register</button>
  </form>
  <div class="small">Already have an account? <a href="login.php" style="color:#cbd5ff">Login</a></div>
</div>
</body>
</html>
