<?php
/**
 * ETEL TAXI Web Push sender - pure PHP, no external libraries/composer
 * needed (shared cPanel hosting, no package installs). Ported directly
 * from Delivery & Ride's proven implementation (api/webpush.php there) -
 * same VAPID identity, same encryption code, not reimplemented. Implements:
 *   - RFC 8292 (VAPID): proves to the push service (Google's, Mozilla's,
 *     etc.) that pushes are coming from this server's registered identity.
 *   - RFC 8291 (aes128gcm): encrypts the push payload so only the
 *     subscribed browser can read it - the push service itself never
 *     sees the plaintext.
 *
 * Every intermediate value in the encryption path was already verified
 * byte-for-byte against RFC 8291 Appendix A's official test vectors when
 * this was first built for Delivery - see that file's header comment.
 *
 * Usage:
 *   etel_taxi_send_web_push($subscription, ['title' => '...', 'body' => '...', 'data' => [...]]);
 * where $subscription is the object the browser's PushManager.subscribe()
 * returns, exactly as JSON-decoded (endpoint, keys.p256dh, keys.auth).
 */

if (!function_exists('etel_taxi_webpush_b64url_decode')) {

function etel_taxi_webpush_b64url_decode($s) {
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) $s .= str_repeat('=', 4 - $pad);
    return base64_decode($s);
}
function etel_taxi_webpush_b64url_encode($bin) {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function etel_taxi_webpush_pubkey_from_raw($rawPoint) {
    if (strlen($rawPoint) !== 65 || ord($rawPoint[0]) !== 0x04) return false;
    $derPrefix = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200');
    $der = $derPrefix . $rawPoint;
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    $key = openssl_pkey_get_public($pem);
    return $key ?: false;
}

function etel_taxi_webpush_der_sig_to_raw($der, $keySizeBytes = 32) {
    $offset = 0;
    if (ord($der[$offset++]) !== 0x30) return false;
    $seqLen = ord($der[$offset++]);
    if ($seqLen & 0x80) { $n = $seqLen & 0x7f; $offset += $n; }

    $readInt = function($der, &$offset) {
        if (ord($der[$offset++]) !== 0x02) return false;
        $len = ord($der[$offset++]);
        $bytes = substr($der, $offset, $len);
        $offset += $len;
        if (strlen($bytes) > 0 && ord($bytes[0]) === 0x00) $bytes = substr($bytes, 1);
        return $bytes;
    };
    $r = $readInt($der, $offset);
    $s = $readInt($der, $offset);
    if ($r === false || $s === false) return false;
    $r = str_pad($r, $keySizeBytes, "\x00", STR_PAD_LEFT);
    $s = str_pad($s, $keySizeBytes, "\x00", STR_PAD_LEFT);
    return $r . $s;
}

function etel_taxi_webpush_vapid_header($endpoint, $vapidPrivatePem, $vapidPublicKeyB64, $subject) {
    static $cache = array();
    $parts = parse_url($endpoint);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return false;
    $aud = $parts['scheme'] . '://' . $parts['host'];
    if (isset($cache[$aud])) return $cache[$aud];

    $header  = etel_taxi_webpush_b64url_encode(json_encode(array('typ' => 'JWT', 'alg' => 'ES256')));
    $payload = etel_taxi_webpush_b64url_encode(json_encode(array(
        'aud' => $aud,
        'exp' => time() + 12 * 3600,
        'sub' => $subject,
    )));
    $signingInput = $header . '.' . $payload;

    $privKey = openssl_pkey_get_private($vapidPrivatePem);
    if (!$privKey) return false;
    $ok = openssl_sign($signingInput, $derSignature, $privKey, OPENSSL_ALGO_SHA256);
    if (!$ok) return false;
    $rawSig = etel_taxi_webpush_der_sig_to_raw($derSignature);
    if ($rawSig === false) return false;
    $jwt = $signingInput . '.' . etel_taxi_webpush_b64url_encode($rawSig);

    $result = array('Authorization' => 'vapid t=' . $jwt . ', k=' . $vapidPublicKeyB64);
    $cache[$aud] = $result;
    return $result;
}

function etel_taxi_webpush_encrypt($payloadPlain, $p256dhB64, $authB64) {
    $uaPublicRaw = etel_taxi_webpush_b64url_decode($p256dhB64);
    $authSecret  = etel_taxi_webpush_b64url_decode($authB64);
    if (strlen($uaPublicRaw) !== 65 || strlen($authSecret) !== 16) return false;

    $uaPublicKey = etel_taxi_webpush_pubkey_from_raw($uaPublicRaw);
    if (!$uaPublicKey) return false;

    $asKeyRes = openssl_pkey_new(array('curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC));
    if (!$asKeyRes) return false;
    $asDetails = openssl_pkey_get_details($asKeyRes);
    $asX = str_pad($asDetails['ec']['x'], 32, "\x00", STR_PAD_LEFT);
    $asY = str_pad($asDetails['ec']['y'], 32, "\x00", STR_PAD_LEFT);
    $asPublicRaw = "\x04" . $asX . $asY;

    $sharedSecret = openssl_pkey_derive($uaPublicKey, $asKeyRes, 32);
    if ($sharedSecret === false) return false;

    $keyInfo = "WebPush: info" . "\x00" . $uaPublicRaw . $asPublicRaw;
    $ikm = hash_hkdf('sha256', $sharedSecret, 32, $keyInfo, $authSecret);

    $salt = openssl_random_pseudo_bytes(16);
    $cekInfo   = "Content-Encoding: aes128gcm" . "\x00";
    $nonceInfo = "Content-Encoding: nonce" . "\x00";
    $cek   = hash_hkdf('sha256', $ikm, 16, $cekInfo, $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, $nonceInfo, $salt);

    $rs = 4096;
    $header = $salt . pack('N', $rs) . chr(strlen($asPublicRaw)) . $asPublicRaw;
    $padded = $payloadPlain . "\x02";
    $tag = '';
    $ciphertext = openssl_encrypt($padded, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ciphertext === false) return false;

    return $header . $ciphertext . $tag;
}

/**
 * Send a single Web Push notification.
 * $subscription: assoc array with 'endpoint' and 'keys' => ['p256dh'=>, 'auth'=>]
 * $payload: assoc array, JSON-encoded and encrypted as the notification body
 * Returns: array('ok' => bool, 'http_code' => int, 'error' => string|null)
 * A 404/410 http_code means the subscription is dead (uninstalled, permission
 * revoked, etc.) - caller should clear it from taxi_drivers.push_subscription.
 */
function etel_taxi_send_web_push($subscription, $payload) {
    if (!defined('VAPID_PRIVATE_KEY_PEM') || !defined('VAPID_PUBLIC_KEY') || !VAPID_PRIVATE_KEY_PEM || !VAPID_PUBLIC_KEY) {
        return array('ok' => false, 'http_code' => 0, 'error' => 'VAPID keys not configured in api/.env');
    }
    if (empty($subscription['endpoint']) || empty($subscription['keys']['p256dh']) || empty($subscription['keys']['auth'])) {
        return array('ok' => false, 'http_code' => 0, 'error' => 'Invalid subscription object');
    }

    $endpoint = $subscription['endpoint'];
    $body = etel_taxi_webpush_encrypt(json_encode($payload), $subscription['keys']['p256dh'], $subscription['keys']['auth']);
    if ($body === false) {
        return array('ok' => false, 'http_code' => 0, 'error' => 'Encryption failed');
    }

    $vapidHeaders = etel_taxi_webpush_vapid_header($endpoint, VAPID_PRIVATE_KEY_PEM, VAPID_PUBLIC_KEY, VAPID_SUBJECT);
    if (!$vapidHeaders) {
        return array('ok' => false, 'http_code' => 0, 'error' => 'VAPID signing failed');
    }

    $headers = array(
        'Authorization: ' . $vapidHeaders['Authorization'],
        'Content-Type: application/octet-stream',
        'Content-Encoding: aes128gcm',
        'TTL: 86400',
        'Content-Length: ' . strlen($body),
    );

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ));
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return array('ok' => false, 'http_code' => 0, 'error' => 'cURL: ' . $curlErr);
    }
    $ok = ($httpCode >= 200 && $httpCode < 300);
    return array('ok' => $ok, 'http_code' => $httpCode, 'error' => $ok ? null : ('Push service returned ' . $httpCode . ': ' . substr($response, 0, 300)));
}

}
