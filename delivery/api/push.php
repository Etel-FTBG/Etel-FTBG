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
 * Same as etel_push_send() but does not make the customer wait for it.
 * A push has to reach a third-party push service; when that is slow or the
 * server's DNS cannot resolve it, each attempt used to hold the HTTP
 * response for the full cURL timeout (order creation took 27-40 s). This
 * queues the push and sends it AFTER the response has gone out to the app.
 * Falls back to a normal synchronous send on CLI/cron or when the host has
 * no finish-request function, so nothing is ever dropped. Returns true when
 * queued (or the sync result in the fallback case).
 */
function etel_push_send_async($pdo, $user_id, $title, $body, $data = array()) {
    if (PHP_SAPI === 'cli' || (!function_exists('litespeed_finish_request') && !function_exists('fastcgi_finish_request'))) {
        if (PHP_SAPI !== 'cli') error_log('[ETEL][timing] push sent inline: no finish-request function on this host (sapi=' . PHP_SAPI . ')');
        return etel_push_send($pdo, $user_id, $title, $body, $data);
    }
    static $queue = array();
    static $registered = false;
    $queue[] = array($user_id, $title, $body, $data);
    if (!$registered) {
        $registered = true;
        ignore_user_abort(true);
        register_shutdown_function(function () use ($pdo, &$queue) {
            while (ob_get_level() > 0) { @ob_end_flush(); }
            if (function_exists('litespeed_finish_request')) { @litespeed_finish_request(); }
            elseif (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
            foreach ($queue as $q) {
                try { etel_push_send($pdo, $q[0], $q[1], $q[2], $q[3]); }
                catch (Exception $e) { error_log('[ETEL] deferred push failed: ' . $e->getMessage()); }
                catch (Throwable $e) { error_log('[ETEL] deferred push failed: ' . $e->getMessage()); }
            }
        });
    }
    return true;
}

/**
 * The in-app "notifications" table was inserted into by order dispatch but
 * never created anywhere, so every insert threw and, because the courier
 * push sat in the same try block, the courier's push was skipped too.
 */
function etel_ensure_notifications_table($pdo) {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL,
        title VARCHAR(160) NOT NULL,
        body TEXT NULL,
        type VARCHAR(40) NULL,
        data TEXT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_user_read (user_id, is_read, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
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
