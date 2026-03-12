<?php
// notifications_lib.php
// Small helper to create a notifications row, broadcast via Pusher and send OneSignal pushes.
// Usage: require 'notifications_lib.php'; send_user_notification($pdo, $to_user_id, $message, $type, $source_user_id, $ref_id, $important);

if (!function_exists('send_one_signal_notification')) {
    function send_one_signal_notification(array $playerIds, $title, $message, $data = []) {
        global $ONESIGNAL_APP_ID, $ONESIGNAL_REST_KEY;
        if (empty($ONESIGNAL_REST_KEY) || empty($ONESIGNAL_APP_ID)) {
            return ['ok' => false, 'error' => 'onesignal_not_configured'];
        }
        $playerIds = array_filter(array_map('trim', $playerIds));
        if (empty($playerIds)) return ['ok' => false, 'error' => 'no_player_ids'];
        $body = [
            'app_id' => $ONESIGNAL_APP_ID,
            'include_player_ids' => array_values($playerIds),
            'headings' => ['en' => $title],
            'contents' => ['en' => $message],
            'data' => $data,
            'ios_badgeType' => 'Increase',
            'ios_badgeCount' => 1,
        ];
        $ch = curl_init("https://onesignal.com/api/v1/notifications");
        $payload = json_encode($body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json; charset=utf-8',
            'Authorization: Basic ' . $ONESIGNAL_REST_KEY
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $json = null;
        if ($resp) $json = json_decode($resp, true);
        if ($http >= 200 && $http < 300) return ['ok' => true, 'resp' => $json];
        return ['ok' => false, 'http' => $http, 'resp' => $json, 'curl_err' => $err, 'raw' => $resp];
    }
}

if (!function_exists('pusher_trigger_event_simple')) {
    function pusher_trigger_event_simple($channel, $event, $data = []) {
        // Minimal wrapper reusing the same behavior as notifications.php.
        // If you already have a pusher_trigger_event in scope, this wrapper will not collide.
        global $PUSHER_APP_KEY, $PUSHER_APP_SECRET, $PUSHER_APP_ID, $PUSHER_APP_CLUSTER;
        if (empty($PUSHER_APP_KEY) || empty($PUSHER_APP_SECRET) || empty($PUSHER_APP_ID)) {
            return ['ok' => false, 'error' => 'pusher not configured'];
        }
        // Prefer SDK if present
        if (class_exists('Pusher\Pusher')) {
            try {
                $options = ['useTLS' => true];
                if (!empty($PUSHER_APP_CLUSTER)) $options['cluster'] = $PUSHER_APP_CLUSTER;
                $pusher = new Pusher\Pusher($PUSHER_APP_KEY, $PUSHER_APP_SECRET, $PUSHER_APP_ID, $options);
                $pusher->trigger($channel, $event, $data);
                return ['ok'=>true];
            } catch (Exception $e) {
                $sdkErr = $e->getMessage();
            }
        }
        // HTTP fallback (keeps compatibility with earlier code)
        $cluster = $PUSHER_APP_CLUSTER ?? '';
        $host = $cluster ? "api-{$cluster}.pusher.com" : "api.pusher.com";
        $urlPath = "/apps/{$PUSHER_APP_ID}/events";
        $body = json_encode(['name' => $event, 'channels' => (is_array($channel) ? $channel : [$channel]), 'data' => $data]);
        $body_md5 = md5($body);
        $auth_key = $PUSHER_APP_KEY;
        $auth_timestamp = time();
        $auth_version = '1.0';
        $query = "auth_key={$auth_key}&auth_timestamp={$auth_timestamp}&auth_version={$auth_version}&body_md5={$body_md5}";
        $string_to_sign = "POST\n{$urlPath}\n{$query}";
        $auth_signature = hash_hmac('sha256', $string_to_sign, $PUSHER_APP_SECRET);
        $full_url = "https://{$host}{$urlPath}?{$query}&auth_signature={$auth_signature}";
        $ch = curl_init($full_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json','Content-Length: '.strlen($body)]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        if (defined('CURL_HTTP_VERSION_1_1')) curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        $resp = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp !== false && $httpcode >= 200 && $httpcode < 300) return ['ok' => true, 'resp' => $resp];
        return ['ok' => false, 'error' => 'pusher_rest_error', 'http' => $httpcode, 'resp' => $resp, 'curl_err' => $err];
    }
}

if (!function_exists('send_user_notification')) {
    function send_user_notification(PDO $pdo, $target_user, $message, $type = 'generic', $source_user_id = null, $ref_id = null, $important = 0) {
        global $NOTIF_DEBUG;
        // 1) Insert notifications row
        try {
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, source_user_id, message, context, ref_id, important) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$target_user, $type, $source_user_id, $message, null, $ref_id, $important ? 1 : 0]);
            $nid = (int)$pdo->lastInsertId();
        } catch (Exception $e) {
            if (!empty($NOTIF_DEBUG)) error_log("send_user_notification: DB insert failed: ".$e->getMessage());
            return ['ok' => false, 'error' => 'db_insert_failed', 'detail' => $e->getMessage()];
        }

        // 1b) enforce cap (delete oldest if >99) - best-effort
        try {
            $cst = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
            $cst->execute([$target_user]);
            $cnt = (int)$cst->fetchColumn();
            if ($cnt > 99) {
                $del = $pdo->prepare("DELETE FROM notifications WHERE user_id = ? ORDER BY id ASC LIMIT 1");
                $del->execute([$target_user]);
            }
        } catch (Exception $_) { /* ignore */ }

        // 2) Broadcast to Pusher / realtime clients
        try {
            $payload = ['id' => $nid, 'type' => $type, 'message' => $message, 'ref_id' => $ref_id, 'important' => (int)$important, 'created_at' => gmdate('Y-m-d\TH:i:s\Z')];
            $pRes = pusher_trigger_event_simple('private-notifs-'.$target_user, 'new_notification', $payload);
        } catch (Exception $e) {
            $pRes = ['ok' => false, 'error' => $e->getMessage()];
        }

        // 3) Send OneSignal push to stored players
        $onesignal_result = null;
        try {
            $ps = $pdo->prepare("SELECT player_id FROM push_subscriptions WHERE user_id = ?");
            $ps->execute([$target_user]);
            $players = $ps->fetchAll(PDO::FETCH_COLUMN, 0);
            if (!empty($players)) {
                $onesignal_result = send_one_signal_notification($players, $message, $message, ['notification_id' => $nid, 'ref_id' => $ref_id]);
                // if OneSignal returns invalid ids, attempt cleanup
                if (isset($onesignal_result['resp']) && is_array($onesignal_result['resp'])) {
                    $resp = $onesignal_result['resp'];
                    $invalid = [];
                    if (!empty($resp['invalid_player_ids']) && is_array($resp['invalid_player_ids'])) $invalid = $resp['invalid_player_ids'];
                    if (!empty($invalid)) {
                        $del = $pdo->prepare("DELETE FROM push_subscriptions WHERE player_id = ?");
                        foreach ($invalid as $bad) { try { $del->execute([$bad]); } catch (Exception $_) {} }
                        // log cleanup
                        if (!empty($NOTIF_DEBUG)) error_log("send_user_notification: cleaned invalid players: ".implode(',', $invalid));
                    }
                }
            } else {
                if (!empty($NOTIF_DEBUG)) error_log("send_user_notification: no players for user {$target_user}");
            }
        } catch (Exception $e) {
            if (!empty($NOTIF_DEBUG)) error_log("send_user_notification: onesignal send error: ".$e->getMessage());
            $onesignal_result = ['ok'=>false,'error'=>'onesignal_send_exception','detail'=>$e->getMessage()];
        }

        // 4) Return combined result for debugging
        return ['ok' => true, 'notification_id' => $nid, 'pusher' => $pRes ?? null, 'onesignal' => $onesignal_result ?? null];
    }
}
