<?php
// ═══════════════════════════════════════════════════════════════════
// ETEL MADEYA - EtelID Bridge
// ═══════════════════════════════════════════════════════════════════
//
// Server-to-server link to the EtelID system (the group's single
// identity). Same Service API pattern already established by Delivery
// & Ride's own api/etelid_bridge.php - never build the request URL
// from a client-supplied Host header (fully attacker-controlled), use
// a fixed trusted domain instead.
//
// Configure both sides with the SAME shared secret:
//   EtelID wp-config.php:      define('ETELID_SERVICE_KEY', '<key>');
//   Etel Madeya config.php:    define('ETELID_SERVICE_KEY', '<key>');
//     (or set the ETELID_SERVICE_KEY environment variable instead)

function etelid_bridge_service_key() {
    if (defined('ETELID_SERVICE_KEY') && ETELID_SERVICE_KEY && strcasecmp(trim((string) ETELID_SERVICE_KEY), 'CHANGE_ME') !== 0) {
        return ETELID_SERVICE_KEY;
    }
    $k = getenv('ETELID_SERVICE_KEY');
    return $k ? $k : '';
}

function etelid_bridge_service_call($path, $payload) {
    $key = etelid_bridge_service_key();
    if (!$key) return null; /* not configured */
    $host = defined('ETELID_HOST') ? ETELID_HOST : 'ethelmarket.com';
    $url  = 'https://' . $host . '/EtelId/wp-json/etelid/v1/service/' . $path;
    $body = json_encode($payload);
    $resp = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => array('Content-Type: application/json',
                                            'X-Etel-Service-Key: ' . $key),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
        ));
        $resp = curl_exec($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(array('http' => array(
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nX-Etel-Service-Key: " . $key . "\r\n",
            'content'       => $body,
            'timeout'       => 5,
            'ignore_errors' => true,
        )));
        $resp = @file_get_contents($url, false, $ctx);
    }
    if (!$resp) return null;
    $d = @json_decode($resp, true);
    return is_array($d) ? $d : null;
}

function etelid_bridge_member_from_service($d) {
    if (!isset($d['ok']) || !$d['ok'] || empty($d['member'])) return null;
    $m = $d['member'];
    return array(
        'etel_id'   => isset($m['etel_id'])   ? $m['etel_id']   : '',
        'full_name' => isset($m['full_name']) ? $m['full_name'] : '',
        'phone'     => isset($m['phone'])     ? $m['phone']     : '',
        'status'    => isset($m['status'])    ? $m['status']    : '',
    );
}

/**
 * Authenticate an EtelID (identifier + password) server-to-server.
 * Returns array('member' => [...]) on success, or array('error' => msg).
 * Fails closed (never falls back to any local/direct DB check) if the
 * Service API isn't configured or unreachable - same choice Delivery's
 * bridge made after an audit flagged direct-DB coupling as fragile.
 */
function etelid_bridge_authenticate($identifier, $password) {
    $svc = etelid_bridge_service_call('authenticate', array(
        'identifier' => $identifier,
        'password'   => $password,
        'app'        => 'madeya', // a limited (Ride-only) EtelID is refused by EtelID itself
    ));
    if ($svc !== null) {
        $m = etelid_bridge_member_from_service($svc);
        if ($m) return array('member' => $m);
        return array('error' => isset($svc['message']) ? $svc['message'] : 'EtelID sign-in failed.');
    }
    return array('error' => 'EtelID sign-in is temporarily unavailable. Please try again shortly.');
}

/**
 * Normalize a phone number for matching purposes only (never for
 * display or storage) - strips everything but digits, then keeps the
 * last 9, so '0936533606', '+251936533606', '251936533606' and
 * '936533606' all normalize the same way. Ethiopian mobile numbers
 * are 9 digits after the leading 0 (local) or 251 (country code) is
 * dropped, so comparing on the last 9 digits treats both formats as
 * the same subscriber without needing the admin to re-type anyone's
 * phone number in a specific format.
 */
function etelid_bridge_normalize_phone($phone) {
    $digits = preg_replace('/\D+/', '', (string)$phone);
    return substr($digits, -9);
}

/**
 * Any active EtelGroup-assigned role for this phone at company_slug
 * (set from the EtelGroup admin's Employees panel). Returns
 * { ok, phone, full_name, etel_id, role_at } or null if none/unreachable.
 */
function etelid_bridge_check_role($company_slug, $phone) {
    $svc = etelid_bridge_service_call('check_role', array(
        'company_slug' => $company_slug,
        'phone'        => $phone,
    ));
    if (is_array($svc) && !empty($svc['ok'])) return $svc;
    return null;
}
