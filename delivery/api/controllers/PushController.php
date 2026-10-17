<?php
/**
 * ETEL - Push Subscription Controller
 * Actions: save_push_subscription, remove_push_subscription
 * Any authenticated user (driver, courier, customer, etc.) can register
 * a push subscription for their own account.
 */
if (!defined('APP_VER')) { http_response_code(403); exit('Direct access forbidden'); }

$pdo = db();

// This one action is intentionally public - the VAPID public key is, by
// design, meant to be given to any browser that wants to subscribe (it's
// not a secret, unlike the private key it's paired with).
if ($action === 'get_vapid_public_key') {
    $pub = etel_env('VAPID_PUBLIC_KEY', 'CFG_VAPID_PUBLIC_KEY', '');
    if (!$pub) fail('Push notifications are not configured yet on this server', 503);
    ok(array('publicKey' => $pub));
    return;
}

$u = require_auth($pdo);

switch ($action) {

case 'save_push_subscription': {
    $sub = isset($body['subscription']) ? $body['subscription'] : null;
    if (!$sub || empty($sub['endpoint']) || empty($sub['keys']['p256dh']) || empty($sub['keys']['auth'])) {
        fail('Invalid subscription object', 400);
    }
    $endpoint = trim($sub['endpoint']);
    require_once __DIR__ . '/../webpush.php';
    if (!etel_push_endpoint_allowed($endpoint)) fail('Invalid subscription endpoint', 400);
    $endpointHash = hash('sha256', $endpoint);
    $p256dh = trim($sub['keys']['p256dh']);
    $auth   = trim($sub['keys']['auth']);
    $ua     = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null;
    try {
        $s = $pdo->prepare(
            'INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth, user_agent)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE p256dh = VALUES(p256dh), auth = VALUES(auth), user_agent = VALUES(user_agent)'
        );
        $s->execute(array($u['id'], $endpoint, $endpointHash, $p256dh, $auth, $ua));
        ok(array('message' => 'Push subscription saved'));
    } catch (Exception $e) {
        fail_ex($e, 'Could not save subscription', 500);
    }
    break;
}

case 'remove_push_subscription': {
    $endpoint = isset($body['endpoint']) ? trim($body['endpoint']) : '';
    if (!$endpoint) fail('endpoint is required', 400);
    $endpointHash = hash('sha256', $endpoint);
    try {
        $pdo->prepare('DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint_hash = ?')
            ->execute(array($u['id'], $endpointHash));
        ok(array('message' => 'Push subscription removed'));
    } catch (Exception $e) {
        fail_ex($e, 'Could not remove subscription', 500);
    }
    break;
}

case 'save_push_token': {
    // Expo push token variant of save_push_subscription above - used by
    // the native ETEL Drive app, which has no Web Push API. The token
    // itself is stored in the existing `endpoint` column (hashed the same
    // way for the same one-row-per-install uniqueness), with type='expo'
    // so etel_push_to_user (webpush.php) knows to send it through Expo's
    // push service instead of Web Push encryption. See migration 059.
    $token = isset($body['token']) ? trim($body['token']) : '';
    if (!$token) fail('token is required', 400);
    $endpointHash = hash('sha256', $token);
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null;
    try {
        $s = $pdo->prepare(
            "INSERT INTO push_subscriptions (user_id, type, endpoint, endpoint_hash, p256dh, auth, user_agent)
             VALUES (?, 'expo', ?, ?, NULL, NULL, ?)
             ON DUPLICATE KEY UPDATE type = 'expo', endpoint = VALUES(endpoint), user_agent = VALUES(user_agent)"
        );
        $s->execute(array($u['id'], $token, $endpointHash, $ua));
        ok(array('message' => 'Push token saved'));
    } catch (Exception $e) {
        fail_ex($e, 'Could not save push token', 500);
    }
    break;
}

case 'remove_push_token': {
    $token = isset($body['token']) ? trim($body['token']) : '';
    if (!$token) fail('token is required', 400);
    $endpointHash = hash('sha256', $token);
    try {
        $pdo->prepare("DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint_hash = ? AND type = 'expo'")
            ->execute(array($u['id'], $endpointHash));
        ok(array('message' => 'Push token removed'));
    } catch (Exception $e) {
        fail_ex($e, 'Could not remove push token', 500);
    }
    break;
}

case 'test_send_push': {
    // Self-test: sends a push to the calling user's own account, so the
    // whole pipeline (subscription -> encryption -> delivery) can be
    // verified with just one device/account, without needing to simulate
    // a real ride booking or order from a second account.
    require_once __DIR__ . '/../push.php';
    $count = $pdo->prepare('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?');
    $count->execute(array($u['id']));
    $subCount = intval($count->fetchColumn());
    if ($subCount === 0) {
        fail('No push subscription found for your account yet. Open the app, make sure notifications are allowed, and reload - it should subscribe automatically.', 400);
    }
    $sent = etel_push_send($pdo, $u['id'], 'ETEL Test Push', 'If you see this, real push notifications are working correctly.', array('type' => 'test', 'url' => '/delivery/login.html'));
    if ($sent) {
        ok(array('message' => 'Test push sent to ' . $subCount . ' subscription(s) - check your notification tray now.'));
    } else {
        fail('Push send failed for all ' . $subCount . ' subscription(s) - check the server error log for the specific reason (look for "[ETEL webpush]" entries).', 500);
    }
    break;
}

default:
    fail('Unknown push action', 400);
}
