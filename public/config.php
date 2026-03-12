<?php
// ==========================
// DATABASE CONFIG
// ==========================

$DB_HOST = "INSERT";
$DB_NAME = "INSERT";
$DB_USER = "INSERT";
$DB_PASS = "INSERT";

$PUSHER_APP_KEY = 'INSERT';
$PUSHER_APP_SECRET = 'INSERT';
$PUSHER_APP_ID = 'INSERT';
$PUSHER_APP_CLUSTER = 'INSERT';

$pusher_app_key = 'INSERT';
$pusher_app_secret = 'INSERT';
$pusher_app_id = 'INSERT';
$pusher_app_cluster = 'INSERT';
$ONESIGNAL_APP_ID = 'INSERT';
$ONESIGNAL_REST_KEY = 'INSERT';

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch(PDOException $e) {
    die("DB ERROR: ".$e->getMessage());
}

// ==========================
// AUTH HELPER
// ==========================

function current_user($pdo) {
    if (!isset($_COOKIE['auth_token'])) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT id, username, full_name FROM users WHERE auth_token = ?");
    $stmt->execute([$_COOKIE['auth_token']]);
    return $stmt->fetch();
}

$pdo->exec("SET time_zone = '+00:00'");

// (your existing config.php must already create $pdo)
// Add / ensure these two variables exist:

// 1) ADMIN password hash (generate once and paste here).
$ADMIN_PASSWORD_HASH = 'INSERT'; // <-- replace

// 2) token signing secret (random string). Generate and keep secret.
//    e.g. php -r "echo bin2hex(random_bytes(16)).PHP_EOL;"
$ADMIN_TOKEN_SECRET = 'INSERT';

// set how long admin tokens live (seconds)
$ADMIN_TOKEN_TTL = 3600; // 1 hour

?>
