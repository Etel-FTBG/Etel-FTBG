<?php
/**
 * ETEL Delivery - EtelTaxi Bridge
 *
 * Server-to-server link to EtelTaxi (a separate, standalone system -
 * not this codebase, not reachable except over HTTP). Same
 * shared-service-key pattern as etelid_bridge.php, but the opposite
 * direction: EtelID calls INTO Delivery via NfcController.php;
 * Delivery calls INTO EtelTaxi via this file.
 *
 * Used by:
 *   - NfcController (nfc_driver_status: live EtelTaxi association-dues
 *     status for the Traffic Officer NFC card check, instead of a
 *     manually-toggled Delivery-side field)
 *
 * Configure the shared secret on BOTH sides (this is a DIFFERENT
 * secret from ETELID_SERVICE_KEY - it protects a different pairing):
 *   EtelTaxi api/.env:  ETELTAXI_SERVICE_KEY=<key>
 *   Delivery api/.env:  ETELTAXI_SERVICE_KEY=<key>
 *     (or config.php:   define('CFG_ETELTAXI_SERVICE_KEY','<key>');)
 *   Delivery api/.env:  ETELTAXI_HOST=ethelmarket.com  (optional, this
 *     is also the default)
 */

if (!defined('APP_VER')) { http_response_code(403); exit; }

if (!function_exists('taxi_bridge_service_key')) {
    function taxi_bridge_service_key() {
        if (function_exists('etel_env')) {
            $k = etel_env('ETELTAXI_SERVICE_KEY', 'CFG_ETELTAXI_SERVICE_KEY', '');
            if ($k) return $k;
        }
        $k = getenv('ETELTAXI_SERVICE_KEY');
        if (!$k && defined('CFG_ETELTAXI_SERVICE_KEY')) $k = CFG_ETELTAXI_SERVICE_KEY;
        return $k ? $k : '';
    }
}

if (!function_exists('taxi_bridge_host')) {
    function taxi_bridge_host() {
        if (function_exists('etel_env')) {
            $h = etel_env('ETELTAXI_HOST', 'CFG_ETELTAXI_HOST', '');
            if ($h) return $h;
        }
        $h = getenv('ETELTAXI_HOST');
        if (!$h && defined('CFG_ETELTAXI_HOST')) $h = CFG_ETELTAXI_HOST;
        return $h ? $h : 'ethelmarket.com';
    }
}

if (!function_exists('taxi_bridge_call')) {
    function taxi_bridge_call($action, $payload) {
        $key = taxi_bridge_service_key();
        if (!$key) return null; // not configured - caller treats this as "unavailable", never a hard failure

        $host = taxi_bridge_host();
        $url  = 'https://' . $host . '/EtelTaxi/api/boot.php?action=' . urlencode($action);
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

/* Live association-dues status for a driver, keyed by phone and/or
   etel_id (same identifiers the NFC flow already has on hand from the
   tapped card's owner). Returns null if EtelTaxi is unreachable or not
   configured - the caller shows "unavailable", never silently shows a
   stale or wrong status. Returns array('is_registered' => false) if
   this person has no EtelTaxi driver account at all. */
if (!function_exists('taxi_bridge_check_association_payment')) {
    function taxi_bridge_check_association_payment($phone, $etel_id) {
        return taxi_bridge_call('service_check_association_payment', array(
            'phone'   => $phone,
            'etel_id' => $etel_id,
        ));
    }
}
