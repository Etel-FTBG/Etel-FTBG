<?php
/**
 * Etel Gebeya - service-to-service NFC courier check.
 *
 * Called by EtelID right after the scanning person's own EtelID login
 * verifies, so the NFC card page can show or hide its "Confirm Gebeya
 * Order" button per person instead of showing it to everyone - a
 * phone is eligible if it matches an active row in Gebeya's own
 * `couriers` table, the exact same match
 * service_nfc_order_list.php already makes. This endpoint changes
 * nothing; it only answers "is that phone a registered courier right
 * now". Same service-key trust boundary as service_nfc_order_list.php
 * (see that file's header).
 *
 * Place at: EtelGebeya/api/service_nfc_check_courier.php
 * Called as: POST https://ethelmarket.com/EtelGebeya/api/service_nfc_check_courier.php
 *            body: {"phone":"+251..."}
 * Header:    X-Etel-Service-Key: <same key as api/config.php ETELID_SERVICE_KEY>
 */

require __DIR__ . '/config.php';

function sncc_header($name) {
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
$provided = sncc_header('X-Etel-Service-Key');
if (!$provided && isset($_GET['key'])) { $provided = $_GET['key']; }
$match = hash_equals(trim((string) ETELID_SERVICE_KEY), trim((string) $provided));
if (!$match) err('Invalid or missing service key.', 401);

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) $body = $_POST;

$phone = isset($body['phone']) ? trim($body['phone']) : '';
if (!$phone) err('Missing required fields.', 400);

$pdo = db();
if (!$pdo) err('Database unavailable', 500);

$last9 = substr(preg_replace('/\D+/', '', $phone), -9);
$isCourier = false;
if ($last9) {
    foreach ($pdo->query("SELECT phone FROM couriers WHERE status = 'active'")->fetchAll() as $c) {
        if (substr(preg_replace('/\D+/', '', (string) $c['phone']), -9) === $last9) { $isCourier = true; break; }
    }
}

ok(array('is_courier' => $isCourier));
