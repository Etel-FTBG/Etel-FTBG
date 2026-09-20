<?php
// ============================================================
// ETEL TAXI - Push notification sender  v2.0
// Standard Web Push (RFC 8291/8292), not Firebase - reuses the same
// VAPID identity Delivery & Ride already has (see api/lib/webpush.php,
// ported directly from Delivery's proven implementation). No separate
// Firebase project needed.
// ============================================================

require_once __DIR__ . '/webpush.php';

if (!function_exists('taxi_send_push')) {

/**
 * $pushSubscriptionJson: the raw JSON string stored in
 * taxi_drivers.push_subscription (from PushManager.subscribe().toJSON()).
 * $pdo/$driverId (optional): if given, a dead subscription (404/410 -
 * uninstalled, permission revoked, etc.) is cleared from that driver's
 * row so it isn't retried forever. Returns true only on a real
 * successful send.
 */
function taxi_send_push($pushSubscriptionJson, $title, $body, $data = array(), $pdo = null, $driverId = null) {
    if (!$pushSubscriptionJson) return false;
    $subscription = json_decode($pushSubscriptionJson, true);
    if (!is_array($subscription)) return false;

    $payload = array('title' => $title, 'body' => $body, 'data' => $data);
    $result = etel_taxi_send_web_push($subscription, $payload);
    if (!$result['ok']) {
        error_log('[EtelTaxi webpush] send failed: ' . $result['error']);
        if ($pdo && $driverId && in_array($result['http_code'], array(404, 410), true)) {
            try {
                $pdo->prepare('UPDATE ' . tbl('taxi_drivers') . ' SET push_subscription = NULL WHERE id = ?')->execute(array($driverId));
            } catch (Exception $e) {}
        }
    }
    return $result['ok'];
}

}
