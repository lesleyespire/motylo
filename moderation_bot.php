<?php
// moderation_bot.php
// Run periodically (cron or curl) to scan recent messages and auto-moderate.
// Secure by ?key=SECRET or run from CLI.
//
// Requirements: config.php defines $pdo (PDO) connection. Tables:
// - messages (id, user_id, message, created_at, deleted_at)  <-- adapt to your schema
// - users (id, username, timeout_until)
// - notifications (id,user_id,type,source_user_id,message,context,ref_id,is_read,created_at)
// If you don't have moderation_processed table, create using the SQL included below.

require "config.php";
set_time_limit(60);

// ----------------- config -----------------
$SECRET = 'replace-with-a-strong-secret'; // set a strong secret and keep private
$secret_param = $_GET['key'] ?? null;
if (php_sapi_name() !== 'cli') {
    if (!$secret_param || !hash_equals($SECRET, $secret_param)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden']);
        exit;
    }
}

// bot identity: create a "bot user" if desired and set $BOT_USER_ID to that user's id
$BOT_USERNAME = 'ModBot';
$BOT_USER_ID = null; // leave null to attempt to locate or create the bot account below

// moderation parameters
$SCAN_WINDOW_MINUTES = 15;    // look at recent messages in last N minutes
$MAX_MESSAGES = 200;         // max messages to scan per run
$TIMEOUT_SECONDS = 86400;    // 1 day timeout (seconds)
$REPLY_TEMPLATE = "Automoderator: Your message appears to break site rules. It has been removed and you have been timed out for 1 day. Contact staff if you believe this was a mistake.";

// A simple list of regexes for disallowed content (example). Tweak carefully.
// Use case-insensitive patterns. These are examples — expand/replace with your own rules.
$BAD_PATTERNS = [
    '/\bfuck(ed|ing)?\b/i',
    '/\b(asshole|bitch|cunt)\b/i',
    '/\b(nigger|faggot)\b/i', // explicit slurs — keep if you will auto-remove these
    // add more rules here...
];

// db table names (adjust if your schema differs)
$MSG_TABLE = 'messages';
$USERS_TABLE = 'users';
$NOTIF_TABLE = 'notifications';
$PROCESSED_TABLE = 'moderation_processed';

// -------------- helper functions --------------
function json_ok($data = []) { header('Content-Type: application/json'); echo json_encode(array_merge(['ok' => true], $data)); exit; }
function json_err($msg) { header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => $msg]); exit; }

// -------------- ensure bot user exists --------------
try {
    if (empty($BOT_USER_ID)) {
        // try to find user by username
        $st = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $st->execute([$BOT_USERNAME]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) $BOT_USER_ID = (int)$row['id'];
        else {
            // create a bot user -- minimal fields, adapt to your schema (avoid needing passwords)
            $stmt = $pdo->prepare("INSERT INTO users (username, avatar) VALUES (?, ?)");
            $stmt->execute([$BOT_USERNAME, NULL]);
            $BOT_USER_ID = (int)$pdo->lastInsertId();
        }
    }
} catch (Exception $e) {
    json_err("db error finding/creating bot user: " . $e->getMessage());
}

// -------------- ensure processed table exists (non-destructive) --------------
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `{$PROCESSED_TABLE}` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            message_id INT NOT NULL UNIQUE,
            processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    // nonfatal; continue
}

// -------------- fetch candidate messages --------------
try {
    // messages in last N minutes that are not already deleted and not processed
    $sql = "
        SELECT m.*
        FROM `{$MSG_TABLE}` m
        LEFT JOIN `{$PROCESSED_TABLE}` p ON p.message_id = m.id
        WHERE p.message_id IS NULL
          AND (m.deleted_at IS NULL OR m.deleted_at = '0000-00-00 00:00:00')
          AND m.created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ORDER BY m.id ASC
        LIMIT ?
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$SCAN_WINDOW_MINUTES, $MAX_MESSAGES]);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    json_err("db fetch error: " . $e->getMessage());
}

$actions = [];
foreach ($candidates as $msg) {
    $text = $msg['message'] ?? '';
    $shouldModerate = false;
    foreach ($BAD_PATTERNS as $pat) {
        if (@preg_match($pat, $text)) {
            $shouldModerate = true;
            break;
        }
    }
    if (!$shouldModerate) {
        // mark as processed (so we don't scan again)
        try {
            $ins = $pdo->prepare("INSERT IGNORE INTO `{$PROCESSED_TABLE}` (message_id) VALUES (?)");
            $ins->execute([(int)$msg['id']]);
        } catch (Exception $e) {}
        continue;
    }

    // begin transaction for safety
    try {
        $pdo->beginTransaction();

        // 1) reply as bot (insert new message in same thread/context)
        // adapt fields to your messages schema. Minimal example assumes fields: user_id, message, created_at
        $replyStmt = $pdo->prepare("INSERT INTO `{$MSG_TABLE}` (user_id, message, created_at) VALUES (?, ?, NOW())");
        $replyStmt->execute([$BOT_USER_ID, $REPLY_TEMPLATE]);
        $reply_id = (int)$pdo->lastInsertId();

        // 2) mark original message as deleted by moderator: set deleted_at and optionally overwrite message text
        $updateStmt = $pdo->prepare("UPDATE `{$MSG_TABLE}` SET deleted_at = NOW(), message = ? WHERE id = ? LIMIT 1");
        $updateStmt->execute(['Message removed by a site moderator', (int)$msg['id']]);

        // 3) set timeout on the offending user (timeout_until = now + TIMEOUT_SECONDS)
        $timeoutUntil = date('Y-m-d H:i:s', time() + $TIMEOUT_SECONDS);
        $tstmt = $pdo->prepare("UPDATE `{$USERS_TABLE}` SET timeout_until = ? WHERE id = ? LIMIT 1");
        $tstmt->execute([$timeoutUntil, (int)$msg['user_id']]);

        // 4) create a notification record to inform the user (non-fatal if notifications table differs)
        try {
            $nstmt = $pdo->prepare("INSERT INTO `{$NOTIF_TABLE}` (user_id, type, source_user_id, message, context, ref_id, is_read) VALUES (?, ?, ?, ?, ?, ?, 0)");
            $noteMsg = "Your recent message was removed for rule violations and you have been timed out until {$timeoutUntil}.";
            $nstmt->execute([(int)$msg['user_id'], 'mod_action', $BOT_USER_ID, $noteMsg, 'moderation', (int)$msg['id']]);
        } catch (Exception $e) {
            // ignore notification insertion errors
        }

        // 5) mark processed
        $ins = $pdo->prepare("INSERT IGNORE INTO `{$PROCESSED_TABLE}` (message_id) VALUES (?)");
        $ins->execute([(int)$msg['id']]);

        $pdo->commit();

        $actions[] = [
            'message_id' => (int)$msg['id'],
            'action' => 'deleted_and_timed_out',
            'reply_id' => $reply_id,
            'user_id' => (int)$msg['user_id']
        ];

    } catch (Exception $e) {
        $pdo->rollBack();
        // log error but continue
        $actions[] = ['message_id' => (int)$msg['id'], 'error' => $e->getMessage()];
        // ensure processed marker inserted to avoid infinite loop? we might prefer to leave it unchecked so admin can re-run after fix
    }
}

// finished
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'scanned' => count($candidates), 'actions' => $actions]);
exit;
