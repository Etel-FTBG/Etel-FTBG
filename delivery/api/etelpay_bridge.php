<?php
/**
 * ETEL Delivery - EtelPay Bridge
 *
 * Server-to-server link to EtelPay (a separate, standalone system),
 * same shape as taxi_bridge.php. Reuses the already-configured
 * ETELID_SERVICE_KEY - EtelPay's own service endpoints
 * (etelid_member_detail.php etc.) already authenticate against that
 * same shared EtelGroup service key, so no new secret needs setting
 * up on either side.
 *
 * Used by:
 *   - NfcController (nfc_driver_status: live EtelPay wallet
 *     status/KYC for the Traffic Officer NFC card check)
 */

if (!defined('APP_VER')) { http_response_code(403); exit; }

if (!function_exists('etelpay_bridge_service_key')) {
    function etelpay_bridge_service_key() {
        if (function_exists('etel_env')) {
            $k = etel_env('ETELID_SERVICE_KEY', 'CFG_ETELID_SERVICE_KEY', '');
            if ($k) return $k;
        }
        $k = getenv('ETELID_SERVICE_KEY');
        if (!$k && defined('CFG_ETELID_SERVICE_KEY')) $k = CFG_ETELID_SERVICE_KEY;
        return $k ? $k : '';
    }
}

if (!function_exists('etelpay_bridge_host')) {
    function etelpay_bridge_host() {
        if (function_exists('etel_env')) {
            $h = etel_env('ETELPAY_HOST', 'CFG_ETELPAY_HOST', '');
            if ($h) return $h;
        }
        $h = getenv('ETELPAY_HOST');
        if (!$h && defined('CFG_ETELPAY_HOST')) $h = CFG_ETELPAY_HOST;
        return $h ? $h : 'ethelmarket.com';
    }
}

if (!function_exists('etelpay_bridge_call')) {
    function etelpay_bridge_call($path, $payload) {
        $key = etelpay_bridge_service_key();
        if (!$key) return null; // not configured - caller treats this as "unavailable", never a hard failure

        $host = etelpay_bridge_host();
        $url  = 'https://' . $host . '/Etel.Pay/wallet/api/' . $path;
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
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\nX-Etel-Service-Key: " . $key . "\r\n",
                'content' => $body,
                'timeout' => 5,
                'ignore_errors' => true,
            )));
            $resp = @file_get_contents($url, false, $ctx);
        }
        if (!$resp) return null;
        $d = @json_decode($resp, true);
        if (!is_array($d) || empty($d['success'])) return null;
        return isset($d['data']) ? $d['data'] : array();
    }
}

/* Live EtelPay wallet status for a driver, keyed by phone (the same
   identifier the NFC flow already has on hand from the tapped card's
   owner). Returns null if EtelPay is unreachable or not configured -
   the caller shows "unavailable", never silently shows a stale or
   wrong status. Returns array('has_wallet' => false) if this person
   has no EtelPay wallet at all. Deliberately never returns a balance -
   see service_check_wallet.php's own comment on why. */
if (!function_exists('etelpay_bridge_check_wallet')) {
    function etelpay_bridge_check_wallet($phone) {
        return etelpay_bridge_call('service_check_wallet.php', array(
            'phone' => $phone,
        ));
    }
}
