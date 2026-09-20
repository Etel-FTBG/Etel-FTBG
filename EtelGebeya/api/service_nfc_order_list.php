<?php
/**
 * Etel Gebeya - service-to-service NFC order list.
 *
 * Called by EtelID (class-etelid-nfc.php) after the scanning person's
 * own EtelID login verified on EtelID's own side - this endpoint
 * trusts that assertion the same way Etel Madeya's
 * service_nfc_register.php and EtelPay's service_nfc_charge.php trust
 * a valid ETELID_SERVICE_KEY caller. A bare NFC tap alone still can
 * never confirm a delivery; it only identifies which courier's card
 * was tapped, and lists that courier's still-open deliveries for the
 * scanning person to pick from in step 2 (service_nfc_order_confirm.php).
 *
 * Place at: EtelGebeya/api/service_nfc_order_list.php
 * Called as: POST https://ethelmarket.com/EtelGebeya/api/service_nfc_order_list.php
 *            body: {"courier_phone":"+251...","courier_etel_id":"EG-2026-00001"}
 * Header:    X-Etel-Service-Key: <same key as includes/config.php... see api/config.php ETELID_SERVICE_KEY>
 */

require __DIR__ . '/config.php';

function snol_header($name) {
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
$provided = snol_header('X-Etel-Service-Key');
if (!$provided && isset($_GET['key'])) { $provided = $_GET['key']; }
$match = hash_equals(trim((string) ETELID_SERVICE_KEY), trim((string) $provided));
if (!$match) err('Invalid or missing service key.', 401);

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) $body = $_POST;

$courierPhone = isset($body['courier_phone']) ? trim($body['courier_phone']) : '';
if (!$courierPhone) err('Missing required fields.', 400);

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
    "SELECT ov.id, ov.status, ov.subtotal, ov.shipping_fee, ov.tracking_code,
            o.code AS order_code, o.currency, o.payment_status, o.payment_method,
            v.shop_name AS vendor_name, u.full_name AS customer_name, u.phone AS customer_phone
       FROM order_vendors ov
       JOIN orders o   ON o.id = ov.order_id
       JOIN vendors v  ON v.id = ov.vendor_id
       JOIN users u    ON u.id = o.customer_id
      WHERE ov.courier_id = ?
        AND ov.status NOT IN ('delivered', 'cancelled', 'returned')
      ORDER BY o.placed_at DESC
      LIMIT 25"
);
$stmt->execute([$courier['id']]);
$rows = $stmt->fetchAll();

$orders = [];
foreach ($rows as $r) {
    $orders[] = [
        'id'             => (int) $r['id'],
        'order_code'     => $r['order_code'],
        'vendor_name'    => $r['vendor_name'],
        'customer_name'  => $r['customer_name'],
        'customer_phone' => $r['customer_phone'],
        'amount'         => (float) $r['subtotal'] + (float) $r['shipping_fee'],
        'currency'       => $r['currency'],
        'status'         => $r['status'],
        'payment_method' => $r['payment_method'],
        'payment_status' => $r['payment_status'],
        'tracking_code'  => $r['tracking_code'],
    ];
}

ok(['orders' => $orders]);
