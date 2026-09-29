<?php
// ============================================================
// ETEL GEBEYA -- EtelID Service API bridge (authenticate + roles)
// Separate from etelpay_bridge.php on purpose: that file does direct
// EtelID database access for a legacy merchant-payout-phone lookup
// (and, worth flagging separately - its company_slug='gebeya' doesn't
// match any real EtelID company slug, so that specific lookup has
// likely never actually matched anything; not touched here since
// that's a different concern from admin login).
//
// This file instead uses EtelID's secured REST Service API - same
// shared-secret model as Delivery/EtelTaxi/EtelGroup use, and the
// canonical slug for this product in EtelID is 'etel-market' (the
// name "EtelGebeya" is this product's own brand; EtelID's registry
// calls it "Etel Market").
//
// Config (add to EtelGebeya/.env):
//   ETELID_HOST=ethelmarket.com
//   ETELID_SERVICE_KEY=<same value EtelID's wp-config.php has>
// ============================================================
if (!defined('DB_HOST')) { http_response_code(403); exit; }

define('EG_ETELID_COMPANY_SLUG', 'etel-market');

function eg_etelid_host() { return $_ENV['ETELID_HOST'] ?? 'ethelmarket.com'; }
function eg_etelid_service_key() { return $_ENV['ETELID_SERVICE_KEY'] ?? ''; }

function eg_etelid_post($path, $payload) {
    $key = eg_etelid_service_key();
    if (!$key) return array('__error' => 'ETELID_SERVICE_KEY is not configured in .env');
    $url = 'https://' . eg_etelid_host() . '/EtelId/wp-json/etelid/v1/' . $path;
    $ctx = stream_context_create(array('http' => array(
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\nX-Etel-Service-Key: " . $key . "\r\n",
        'content' => json_encode($payload),
        'timeout' => 10,
        'ignore_errors' => true, // so a 4xx/5xx response body is still readable, not just false
    )));
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) {
        $err = error_get_last();
        return array('__error' => 'Could not reach EtelID' . (!empty($err['message']) ? ": {$err['message']}" : ''));
    }
    $d = json_decode($resp, true);
    if (!is_array($d)) return array('__error' => 'EtelID returned an unexpected response');
    return $d;
}

/** Authenticate identifier+password against EtelID itself. Returns the
 *  member array on success, or an array with '__error' set to the
 *  specific reason on failure (missing config, unreachable, or the
 *  actual message EtelID's own API returned - e.g. wrong password) -
 *  never silently collapsed to a generic "incorrect" message that
 *  can't be told apart from a server misconfiguration. */
function eg_etelid_authenticate($identifier, $password) {
    $res = eg_etelid_post('service/authenticate', array(
        'identifier' => $identifier, 'password' => $password, 'create_session' => true,
        'app' => 'gebeya', // a limited (Ride-only) EtelID is refused by EtelID itself
    ));
    if (isset($res['__error'])) return $res;
    if (empty($res['ok'])) return array('__error' => $res['message'] ?? 'Incorrect EtelID or password.');
    if (!empty($res['member']['profile_limited'])) {
        return array('__error' => 'Your Etel-ID is limited to Ride. Add your profile photo and both sides of your Fayda ID in the EtelID portal to unlock Etel Gebeya.');
    }
    return $res['member'];
}

/** Any active Etel-Group-assigned role for this phone at
 *  EG_ETELID_COMPANY_SLUG. Returns null if genuinely none assigned,
 *  or an array with '__error' set if the lookup itself failed (config/
 *  network) - callers should treat that differently from "no role". */
function eg_etelid_check_role($phone) {
    $res = eg_etelid_post('service/check_role', array(
        'company_slug' => EG_ETELID_COMPANY_SLUG, 'phone' => $phone,
    ));
    if (isset($res['__error'])) return $res;
    if (empty($res['ok'])) return null; // genuinely no role assigned - not an error
    return $res; // { ok, phone, full_name, etel_id, role_at }
}
