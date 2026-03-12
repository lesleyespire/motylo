<?php
require_once "db.php";
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["ok"=>false,"error"=>"not_logged_in"]);
    exit;
}

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? null;
$target_id = intval($_POST['target_id'] ?? 0);
$community_id = intval($_POST['community_id'] ?? 0);
$reason = trim($_POST['reason'] ?? "");

if (!$action || !$community_id) {
    echo json_encode(["ok"=>false,"error"=>"missing_mode"]);
    exit;
}

/*
|--------------------------------------------------------------------------
| Fetch Actor Highest Role
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT r.*
    FROM community_user_roles ur
    JOIN community_roles r ON ur.role_id = r.id
    WHERE ur.user_id = ? AND r.community_id = ?
    ORDER BY r.priority DESC
    LIMIT 1
");
$stmt->execute([$user_id, $community_id]);
$actor_role = $stmt->fetch(PDO::FETCH_ASSOC);

$actor_priority = $actor_role['priority'] ?? 0;
$actor_can_timeout = $actor_role['can_timeout'] ?? 0;
$actor_can_ban = $actor_role['can_ban'] ?? 0;
$actor_can_assign = $actor_role['can_assign_roles'] ?? 0;
$actor_is_admin = $actor_role['is_admin'] ?? 0;

/*
|--------------------------------------------------------------------------
| Fetch Target Highest Role
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT r.priority
    FROM community_user_roles ur
    JOIN community_roles r ON ur.role_id = r.id
    WHERE ur.user_id = ? AND r.community_id = ?
    ORDER BY r.priority DESC
    LIMIT 1
");
$stmt->execute([$target_id, $community_id]);
$target_role = $stmt->fetch(PDO::FETCH_ASSOC);
$target_priority = $target_role['priority'] ?? 0;

/*
|--------------------------------------------------------------------------
| OWNER BYPASS
|--------------------------------------------------------------------------
*/
$is_owner = false;
$stmt = $pdo->prepare("SELECT owner_id FROM communities WHERE id = ?");
$stmt->execute([$community_id]);
if ($stmt->fetchColumn() == $user_id) {
    $is_owner = true;
}

/*
|--------------------------------------------------------------------------
| PRIORITY CHECK
|--------------------------------------------------------------------------
*/
function canModerate($actor_priority, $target_priority, $is_owner) {
    if ($is_owner) return true;
    return $actor_priority > $target_priority;
}

switch ($action) {

    case "timeout":

        if (!$is_owner && !$actor_can_timeout && !$actor_is_admin) {
            echo json_encode(["ok"=>false,"error"=>"no_permission"]);
            exit;
        }

        if (!canModerate($actor_priority,$target_priority,$is_owner)) {
            echo json_encode(["ok"=>false,"error"=>"insufficient_priority"]);
            exit;
        }

        $duration = intval($_POST['duration'] ?? 600);
        $expires = date("Y-m-d H:i:s", time()+$duration);

        $stmt = $pdo->prepare("
            INSERT INTO community_timeouts (community_id,user_id,expires_at)
            VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE expires_at = VALUES(expires_at)
        ");
        $stmt->execute([$community_id,$target_id,$expires]);

        echo json_encode(["ok"=>true]);
        break;

    case "untimeout":

        if (!$is_owner && !$actor_can_timeout && !$actor_is_admin) {
            echo json_encode(["ok"=>false,"error"=>"no_permission"]);
            exit;
        }

        if (!canModerate($actor_priority,$target_priority,$is_owner)) {
            echo json_encode(["ok"=>false,"error"=>"insufficient_priority"]);
            exit;
        }

        $stmt = $pdo->prepare("
            DELETE FROM community_timeouts
            WHERE community_id = ? AND user_id = ?
        ");
        $stmt->execute([$community_id,$target_id]);

        echo json_encode(["ok"=>true]);
        break;

    case "ban":

        if (!$is_owner && !$actor_can_ban && !$actor_is_admin) {
            echo json_encode(["ok"=>false,"error"=>"no_permission"]);
            exit;
        }

        if (!canModerate($actor_priority,$target_priority,$is_owner)) {
            echo json_encode(["ok"=>false,"error"=>"insufficient_priority"]);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT IGNORE INTO community_bans (community_id,user_id)
            VALUES (?,?)
        ");
        $stmt->execute([$community_id,$target_id]);

        echo json_encode(["ok"=>true]);
        break;

    case "unban":

        if (!$is_owner && !$actor_can_ban && !$actor_is_admin) {
            echo json_encode(["ok"=>false,"error"=>"no_permission"]);
            exit;
        }

        $stmt = $pdo->prepare("
            DELETE FROM community_bans
            WHERE community_id = ? AND user_id = ?
        ");
        $stmt->execute([$community_id,$target_id]);

        echo json_encode(["ok"=>true]);
        break;

    case "report":

        $stmt = $pdo->prepare("
            INSERT INTO community_reports (community_id, reporter_id, target_id, reason, created_at)
            VALUES (?,?,?,?,NOW())
        ");
        $stmt->execute([$community_id,$user_id,$target_id,$reason]);

        echo json_encode(["ok"=>true]);
        break;

    default:
        echo json_encode(["ok"=>false,"error"=>"invalid_action"]);
}
