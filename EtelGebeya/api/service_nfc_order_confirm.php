<?php
/**
 * Etel Gebeya - service-to-service NFC delivery confirmation, step 2.
 *
 * Called by EtelID after the scanning person picked one of the orders
 * returned by service_nfc_order_list.php - marks that vendor
 * sub-order delivered, logs it, and bubbles the parent order's status
 * once every vendor sub-order on it is settled. Same service-key trust
 * boundary as service_nfc_order_list.php (see that file's header).
 *
 * Place at: EtelGebeya/api/service_nfc_order_confirm.php
 * Called as: POST https://ethelmarket.com/EtelGebeya/api/service_nfc_order_confirm.php
 *            body: {"order_vendor_id":123,"courier_phone":"+251..."}
 * Header:    X-Etel-Service-Key: <same key as api/config.php ETELID_SERVICE_KEY>
 */

require __DIR__ . '/config.php';

function snoc_header($name) {
    $key = 'HTTP_' . str_replace('-', '_', strtoupper($name));
    if (isset($_SERVER[$key])) return $_SERVER[$key];
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, $name) === 0) return $v;
        }
    }
    return '';
}

if (!defined('ETELID_SERVICE_KEY') || !ETELID_SERVICE_KEY || strcasecmp(trim((string) ETELID_SERVICE_KEY), 'CHANGE_ME') === 0) {
    err('Service key not configured on Etel Gebeya.', 500);
}
$provided = snoc_header('X-Etel-Service-Key');
if (!$provided && isset($_GET['key'])) { $provided = $_GET['key']; }
$match = hash_equals(trim((string) ETELID_SERVICE_KEY), trim((string) $provided));
if (!$match) err('Invalid or missing service key.', 401);

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) $body = $_POST;

$orderVendorId = isset($body['order_vendor_id']) ? (int) $body['order_vendor_id'] : 0;
$courierPhone  = isset($body['courier_phone']) ? trim($body['courier_phone']) : '';
$confirmerName = isset($body['confirmer_name']) ? trim($body['confirmer_name']) : '';
if (!$orderVendorId || !$courierPhone) err('Missing required fields.', 400);

$pdo = db();
if (!$pdo) err('Database unavailable', 500);

$last9 = substr(preg_replace('/\D+/', '', $courierPhone), -9);
if (!$last9) err('Invalid courier phone.', 400);

$courier = null;
foreach ($pdo->query("SELECT * FROM couriers WHERE status = 'active'")->fetchAll() as $c) {
    if (substr(preg_replace('/\D+/', '', (string) $c['phone']), -9) === $last9) { $courier = $c; break; }
}
if (!$courier) err('This EtelID account is not registered as an Etel Gebeya courier.', 403);

$stmt = $pdo->prepare(
    "SELECT ov.*, o.code AS order_code
       FROM order_vendors ov
       JOIN orders o ON o.id = ov.order_id
      WHERE ov.id = ? LIMIT 1"
);
$stmt->execute([$orderVendorId]);
$ov = $stmt->fetch();
if (!$ov) err('Order not found.', 404);
if ((int) $ov['courier_id'] !== (int) $courier['id']) {
    err('This order is not assigned to that courier.', 403);
}
if ($ov['status'] === 'delivered') err('Already confirmed delivered.', 400);
if (in_array($ov['status'], ['cancelled', 'returned'], true)) {
    err('This order was ' . $ov['status'] . ' and cannot be confirmed.', 400);
}

$pdo->beginTransaction();
try {
    // Atomic status flip, not a blind UPDATE - the SELECT above and this
    // UPDATE are not otherwise atomic, so two concurrent confirm taps for
    // the same order_vendor_id could both pass the checks above and both
    // reach here. Only the request that actually flips the row (still
    // 'assigned'/'processing'/'shipped', not already 'delivered') is
    // allowed to log the delivery and bump the courier's stats - a
    // second/racing request gets a clean error instead of double-logging
    // and double-incrementing total_deliveries. Same pattern used for
    // EtelPay's dispute-resolution double-refund fix.
    $flip = $pdo->prepare(
        "UPDATE order_vendors SET status = 'delivered', delivered_at = NOW()
          WHERE id = ? AND status NOT IN ('delivered', 'cancelled', 'returned')"
    );
    $flip->execute([$ov['id']]);
    if ($flip->rowCount() === 0) {
        $pdo->rollBack();
        err('Already confirmed delivered.', 400);
    }

    $note = 'Delivery confirmed via NFC tap' . ($confirmerName ? (' by ' . $confirmerName) : '');
    $pdo->prepare("INSERT INTO order_status_log (order_id, status, note, created_at) VALUES (?, 'delivered', ?, NOW())")
        ->execute([$ov['order_id'], $note]);

    $pdo->prepare("UPDATE couriers SET total_deliveries = total_deliveries + 1 WHERE id = ?")
        ->execute([$courier['id']]);

    // Bubble the parent order's status once every still-active vendor
    // sub-order under it has been delivered - mirrors the same
    // weakest-stage rule the vendor panel's own bubbleOrderStatus()
    // uses, just triggered from a delivered NFC-confirmed sub-order
    // instead of a manual status change.
    $subs = $pdo->prepare("SELECT status FROM order_vendors WHERE order_id = ?");
    $subs->execute([$ov['order_id']]);
    $statuses = $subs->fetchAll(PDO::FETCH_COLUMN);
    $active = array_filter($statuses, fn($s) => !in_array($s, ['cancelled', 'returned'], true));
    if ($active && !array_diff($active, ['delivered'])) {
        $pdo->prepare("UPDATE orders SET status = 'delivered', completed_at = NOW() WHERE id = ? AND status <> 'delivered'")
            ->execute([$ov['order_id']]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    // Log server-side only - raw exception text (can include table/column
    // names or SQL fragments) has no business in an HTTP response body.
    error_log('[EtelGebeya][nfc_order_confirm] ' . $e->getMessage());
    err('Could not confirm delivery. Please try again.', 500);
}

ok(['message' => 'Delivery confirmed for order ' . $ov['order_code'] . '.']);
