<?php
/**
 * ETEL Delivery — Push Notification Helper
 *
 * REWRITTEN: this used to call Firebase Cloud Messaging's Legacy HTTP API
 * (fcm.googleapis.com/fcm/send with a server key). Google fully shut that
 * endpoint down in mid-2024 - every call through it has been silently
 * failing since (error_log'd, but nothing crashes, so this went unnoticed
 * for a long time). This now uses standards-based Web Push (RFC 8291/8292)
 * instead, which needs no Google/Firebase account or server key at all -
 * it talks directly to whichever push service each browser uses. See
 * api/webpush.php for the actual implementation and
 * api/webpush-generate-keys.php to generate the one-time VAPID keys this
 * needs (set in api/.env).
 *
 * Function names/signatures are unchanged on purpose - the existing call
 * sites (RideController.php, ServiceGiverController.php) don't need to
 * change at all; they just start actually working.
 *
 * PHP 7.1+ required (hash_hkdf, openssl_pkey_derive) - this was already
 * implied by prior work in this codebase assuming PHP 8.1.
 */

if (!defined('APP_VER')) { http_response_code(403); exit; }

require_once __DIR__ . '/webpush.php';

/**
 * Send a push notification to a single user (all of their devices, if
 * they've installed this on more than one).
 *
 * @param PDO    $pdo     Active DB connection
 * @param int    $user_id Target user ID
 * @param string $title   Notification title
 * @param string $body    Notification body text
 * @param array  $data    Extra key-value data payload (optional) - available
 *                         to the notificationclick handler in sw.js as
 *                         event.notification.data
 * @return bool  True if at least one subscription received it successfully
 */
function etel_push_send($pdo, $user_id, $title, $body, $data = array()) {
    $payload = array(
        'title' => $title,
        'body'  => $body,
        'url'   => isset($data['url']) ? $data['url'] : '/delivery/login.html',
        'data'  => $data,
    );
    $results = etel_push_to_user($pdo, $user_id, $payload);
    foreach ($results as $r) { if ($r['ok']) return true; }
    return false;
}

/**
 * Send push to multiple users at once.
 *
 * @param PDO    $pdo      Active DB connection
 * @param array  $user_ids Array of user IDs
 * @param string $title
 * @param string $body
 * @param array  $data
 */
function etel_push_send_multi($pdo, $user_ids, $title, $body, $data = array()) {
    if (empty($user_ids)) return;
    $payload = array('title' => $title, 'body' => $body, 'url' => isset($data['url']) ? $data['url'] : '/delivery/login.html', 'data' => $data);
    foreach ($user_ids as $uid) {
        etel_push_to_user($pdo, intval($uid), $payload);
    }
}
