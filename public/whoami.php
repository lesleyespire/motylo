<?php
// whoami.php
require "config.php";
header("Content-Type: application/json");

if (empty($_COOKIE["auth_token"])) {
    echo json_encode(["ok" => false, "error" => "not logged in"]);
    exit;
}

// try to join roles table if it exists (roles.id -> users.role_id or roles.name -> users.role)
$roles_table = false;
try {
    $roles_table = (bool)$pdo->query("SHOW TABLES LIKE 'roles'")->fetchColumn();
} catch (Exception $e) {
    $roles_table = false;
}

$users_cols = [];
try {
    $uc = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    $users_cols = array_map('strtolower', $uc ?: []);
} catch (Exception $e) {
    $users_cols = [];
}

$join_roles = "";
$select_roles = ", NULL AS role, NULL AS role_color, NULL AS role_badge";
if ($roles_table) {
    if (in_array('role_id', $users_cols)) {
        $join_roles = " LEFT JOIN roles r ON r.id = u.role_id ";
        $select_roles = ", r.name AS role, r.color AS role_color, r.badge AS role_badge";
    } elseif (in_array('role', $users_cols)) {
        $join_roles = " LEFT JOIN roles r ON r.name = u.role ";
        $select_roles = ", r.name AS role, r.color AS role_color, r.badge AS role_badge";
    }
}

try {
    $sql = "SELECT u.id, u.username, u.avatar, u.timeout_until {$select_roles}
            FROM users u
            {$join_roles}
            WHERE u.auth_token = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$_COOKIE["auth_token"]]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo json_encode(["ok" => false, "error" => "invalid login"]);
        exit;
    }

    // fallback role/color/badge behaviour
    $roleName = strtolower((string)($row['role'] ?? ''));
    if (empty($row['role_color'])) {
        if ($roleName === 'owner') {
            $row['role_color'] = '#ffffff';
        } elseif ($roleName === 'moderator') {
            $row['role_color'] = '#c0c0c0';
        } else {
            // deterministic fallback colour from username
            $palette = ['#9bbcff','#ffb86b','#7ee787','#ffd1dc','#caa9ff','#ffd166','#80ffea','#ff8fb7','#f7b267','#a0e7e5'];
            $seed = crc32($row['username'] ?? '');
            $row['role_color'] = $palette[$seed % count($palette)];
        }
    }
    if (empty($row['role_badge'])) {
        if ($roleName === 'owner') $row['role_badge'] = '★';
        elseif ($roleName === 'moderator') $row['role_badge'] = '✧';
        else $row['role_badge'] = '';
    }

    // timed out?
    $timeout_until = $row['timeout_until'] ?? null;
    $timed_out = false;
    if ($timeout_until && strtotime($timeout_until) > time()) {
        $timed_out = true;
    }

    echo json_encode([
        "ok" => true,
        "id" => (int)$row['id'],
        "username" => $row['username'],
        "avatar" => $row['avatar'],
        "role" => $row['role'] ?? '',
        "role_color" => $row['role_color'],
        "role_badge" => $row['role_badge'],
        "timeout_until" => $timeout_until,
        "timed_out" => $timed_out
    ]);
    exit;
} catch (Exception $e) {
    error_log("whoami error: " . $e->getMessage());
    echo json_encode(["ok" => false, "error" => "server error"]);
    exit;
}
