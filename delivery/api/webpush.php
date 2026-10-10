<?php
/**
 * ETEL Web Push sender - pure PHP, no external libraries/composer needed
 * (this runs on shared cPanel hosting where installing packages isn't
 * an option). Implements:
 *   - RFC 8292 (VAPID): proves to the push service (Google's, Mozilla's,
 *     etc.) that pushes are coming from this server's registered identity.
 *   - RFC 8291 (aes128gcm): encrypts the push payload so only the
 *     subscribed browser can read it - the push service itself never
 *     sees the plaintext.
 *
 * IMPORTANT: every intermediate value in the encryption path (ECDH
 * secret, HKDF outputs, CEK, nonce, header, final ciphertext) was
 * verified byte-for-byte against RFC 8291 Appendix A's official test
 * vectors before this was written - see the reasoning trail in the
 * conversation this was built in if that ever needs re-checking.
 *
 * Usage:
 *   etel_send_web_push($subscription, ['title' => '...', 'body' => '...', 'data' => [...]]);
 * where $subscription is the object the browser's PushManager.subscribe()
 * returns, exactly as JSON-decoded (endpoint, keys.p256dh, keys.auth).
 */

function etel_webpush_b64url_decode($s) {
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) $s .= str_repeat('=', 4 - $pad);
    return base64_decode($s);
}
function etel_webpush_b64url_encode($bin) {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

/* Build a usable OpenSSL public-key resource from the raw 65-byte
   uncompressed P-256 point the browser gives us (p256dh). PHP has no
   direct "raw point -> key object" function, so we wrap it in the fixed,
   standard DER prefix for a P-256 SubjectPublicKeyInfo and load that -
   this exact technique was verified against a real key before use. */
function etel_webpush_pubkey_from_raw($rawPoint) {
    if (strlen($rawPoint) !== 65 || ord($rawPoint[0]) !== 0x04) return false;
    $derPrefix = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200');
    $der = $derPrefix . $rawPoint;
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    $key = openssl_pkey_get_public($pem);
    return $key ?: false;
}

/* Convert an ECDSA DER signature (what openssl_sign produces) into the
   raw 64-byte R||S format JWS/JWT requires (RFC 7518 section 3.4) -
   these are NOT the same encoding, and a JWT signed with the DER bytes
   directly will be silently rejected by every push service. */
function etel_webpush_der_sig_to_raw($der, $keySizeBytes = 32) {
    $offset = 0;
    if (ord($der[$offset++]) !== 0x30) return false; // SEQUENCE
    $seqLen = ord($der[$offset++]);
    if ($seqLen & 0x80) { $n = $seqLen & 0x7f; $offset += $n; } // skip long-form length bytes (unused at this size but handled for safety)

    $readInt = function($der, &$offset) {
        if (ord($der[$offset++]) !== 0x02) return false; // INTEGER
        $len = ord($der[$offset++]);
        $bytes = substr($der, $offset, $len);
        $offset += $len;
        // Strip a single leading 0x00 sign-guard byte if present
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

/* Build and sign the VAPID JWT for a given push service origin. Cached
   per-origin for the lifetime of the request since building many
   pushes to the same push service (e.g. FCM for many Android drivers)
   would otherwise re-sign an identical JWT repeatedly. */
function etel_webpush_vapid_header($endpoint, $vapidPrivatePem, $vapidPublicKeyB64, $subject) {
    static $cache = array();
    $parts = parse_url($endpoint);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return false;
    $aud = $parts['scheme'] . '://' . $parts['host'];
    $cacheKey = $aud;
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];

    $header  = etel_webpush_b64url_encode(json_encode(array('typ' => 'JWT', 'alg' => 'ES256')));
    $payload = etel_webpush_b64url_encode(json_encode(array(
        'aud' => $aud,
        'exp' => time() + 12 * 3600,
        'sub' => $subject,
    )));
    $signingInput = $header . '.' . $payload;

    $privKey = openssl_pkey_get_private($vapidPrivatePem);
    if (!$privKey) return false;
    $ok = openssl_sign($signingInput, $derSignature, $privKey, OPENSSL_ALGO_SHA256);
    if (!$ok) return false;
    $rawSig = etel_webpush_der_sig_to_raw($derSignature);
    if ($rawSig === false) return false;
    $jwt = $signingInput . '.' . etel_webpush_b64url_encode($rawSig);

    $result = array(
        'Authorization' => 'vapid t=' . $jwt . ', k=' . $vapidPublicKeyB64,
    );
    $cache[$cacheKey] = $result;
    return $result;
}

/* Encrypt $payloadJson per RFC 8291 for a specific subscription, and
   return the raw bytes ready to POST as the request body. */
function etel_webpush_encrypt($payloadPlain, $p256dhB64, $authB64) {
    $uaPublicRaw = etel_webpush_b64url_decode($p256dhB64);
    $authSecret  = etel_webpush_b64url_decode($authB64);
    if (strlen($uaPublicRaw) !== 65 || strlen($authSecret) !== 16) return false;

    $uaPublicKey = etel_webpush_pubkey_from_raw($uaPublicRaw);
    if (!$uaPublicKey) return false;

    // Fresh ephemeral P-256 key pair for this message only (per RFC 8291 -
    // the application server's ECDH key pair can be discarded right after).
    $asKeyRes = openssl_pkey_new(array('curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC));
    if (!$asKeyRes) return false;
    $asDetails = openssl_pkey_get_details($asKeyRes);
    $asX = str_pad($asDetails['ec']['x'], 32, "\x00", STR_PAD_LEFT);
    $asY = str_pad($asDetails['ec']['y'], 32, "\x00", STR_PAD_LEFT);
    $asPublicRaw = "\x04" . $asX . $asY;

    $sharedSecret = openssl_pkey_derive($uaPublicKey, $asKeyRes, 32);
    if ($sharedSecret === false) return false;

    // -- Combine ECDH + auth secrets (RFC 8291 section 3.3) --
    $keyInfo = "WebPush: info" . "\x00" . $uaPublicRaw . $asPublicRaw;
    $ikm = hash_hkdf('sha256', $sharedSecret, 32, $keyInfo, $authSecret);

    // -- RFC 8188 content-encryption key/nonce derivation --
    $salt = openssl_random_pseudo_bytes(16);
    $cekInfo   = "Content-Encoding: aes128gcm" . "\x00";
    $nonceInfo = "Content-Encoding: nonce" . "\x00";
    $cek   = hash_hkdf('sha256', $ikm, 16, $cekInfo, $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, $nonceInfo, $salt);

    // -- Single-record aes128gcm body: header || ciphertext --
    $rs = 4096;
    $header = $salt . pack('N', $rs) . chr(strlen($asPublicRaw)) . $asPublicRaw;
    $padded = $payloadPlain . "\x02"; // padding delimiter, no extra padding needed at this size
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
 * revoked, etc.) and the caller should delete it from push_subscriptions.
 */
function etel_webpush_make_handle($subscription, $payload, &$err) {
    $err = null;
    $vapidPrivatePem = etel_env('VAPID_PRIVATE_KEY_PEM', 'CFG_VAPID_PRIVATE_KEY_PEM', '');
    $vapidPublicKey  = etel_env('VAPID_PUBLIC_KEY', 'CFG_VAPID_PUBLIC_KEY', '');
    $vapidSubject    = etel_env('VAPID_SUBJECT', 'CFG_VAPID_SUBJECT', 'mailto:admin@ethelmarket.com');
    // .env stores literal "\n" for line breaks (see webpush-generate-keys.php) - restore real newlines
    $vapidPrivatePem = str_replace('\\n', "\n", $vapidPrivatePem);

    if (!$vapidPrivatePem || !$vapidPublicKey) {
        $err = array('ok' => false, 'http_code' => 0, 'error' => 'VAPID keys not configured - run api/webpush-generate-keys.php and set them in .env');
        return null;
    }
    if (empty($subscription['endpoint']) || empty($subscription['keys']['p256dh']) || empty($subscription['keys']['auth'])) {
        $err = array('ok' => false, 'http_code' => 0, 'error' => 'Invalid subscription object');
        return null;
    }

    $endpoint = $subscription['endpoint'];
    $body = etel_webpush_encrypt(json_encode($payload), $subscription['keys']['p256dh'], $subscription['keys']['auth']);
    if ($body === false) {
        $err = array('ok' => false, 'http_code' => 0, 'error' => 'Encryption failed');
        return null;
    }

    $vapidHeaders = etel_webpush_vapid_header($endpoint, $vapidPrivatePem, $vapidPublicKey, $vapidSubject);
    if (!$vapidHeaders) {
        $err = array('ok' => false, 'http_code' => 0, 'error' => 'VAPID signing failed');
        return null;
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
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_SSL_VERIFYPEER => true,
    ));
    return $ch;
}

// Turns a finished cURL handle into the array('ok','http_code','error') shape.
function etel_webpush_result_from_handle($ch, $response, $curlCode = null) {
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    // Handles run through curl_multi do not fill curl_error(); the per-handle
    // result code comes from curl_multi_info_read() and is passed in here.
    if ($curlErr === '' && $curlCode !== null && (int)$curlCode !== 0) $curlErr = curl_strerror((int)$curlCode);
    if ($curlErr) {
        return array('ok' => false, 'http_code' => 0, 'error' => 'cURL: ' . $curlErr);
    }
    // 200/201 = delivered to push service; 404/410 = subscription is dead
    $ok = ($httpCode >= 200 && $httpCode < 300);
    return array('ok' => $ok, 'http_code' => $httpCode, 'error' => $ok ? null : ('Push service returned ' . $httpCode . ': ' . substr((string)$response, 0, 300)));
}

function etel_send_web_push($subscription, $payload) {
    $err = null;
    $ch = etel_webpush_make_handle($subscription, $payload, $err);
    if (!$ch) return $err;
    $response = curl_exec($ch);
    $result = etel_webpush_result_from_handle($ch, $response);
    curl_close($ch);
    return $result;
}

/**
 * Send a single push to an Expo push token - used by the native ETEL
 * Drive app (React Native/Expo), which has no Web Push API. Expo's own
 * push service forwards this to FCM/APNs; no Google service account or
 * Firebase project needed server-side.
 * Returns the same array('ok','http_code','error') shape as
 * etel_send_web_push, so etel_push_to_user can treat both uniformly -
 * http_code is set to 410 on a dead/uninstalled token (Expo's
 * "DeviceNotRegistered" error) to reuse the existing dead-subscription
 * cleanup path below.
 */
function etel_send_expo_push($token, $payload) {
    if (!$token) return array('ok' => false, 'http_code' => 0, 'error' => 'Missing Expo push token');
    $message = array(
        'to'       => $token,
        'title'    => isset($payload['title']) ? $payload['title'] : '',
        'body'     => isset($payload['body']) ? $payload['body'] : '',
        'data'     => isset($payload['data']) ? $payload['data'] : array(),
        'sound'    => 'default',
        'priority' => 'high',
    );
    $ch = curl_init('https://exp.host/--/api/v2/push/send');
    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($message),
        CURLOPT_HTTPHEADER     => array(
            'Content-Type: application/json',
            'Accept: application/json',
            'Accept-Encoding: gzip, deflate',
        ),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_SSL_VERIFYPEER => true,
    ));
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return array('ok' => false, 'http_code' => 0, 'error' => 'cURL: ' . $curlErr);
    }
    $decoded = json_decode($response, true);
    $status  = isset($decoded['data']['status']) ? $decoded['data']['status'] : null;
    $ok      = ($httpCode >= 200 && $httpCode < 300) && $status === 'ok';
    $deadToken = ($status === 'error')
              && isset($decoded['data']['details']['error'])
              && $decoded['data']['details']['error'] === 'DeviceNotRegistered';
    $errMsg = $ok ? null : (
        isset($decoded['data']['message']) ? $decoded['data']['message']
        : (isset($decoded['errors']) ? json_encode($decoded['errors']) : ('HTTP ' . $httpCode))
    );
    return array('ok' => $ok, 'http_code' => $deadToken ? 410 : $httpCode, 'error' => $errMsg);
}

/**
 * Stale-subscription pruning. A subscription that returns 404/410 is deleted at
 * once. One that keeps failing for other reasons (unreachable endpoint, rejected
 * keys) is counted in push_subscriptions.fail_count and deleted after
 * ETEL_PUSH_MAX_FAILS failures in a row; any success resets the count. Transient
 * push-service trouble (429, 5xx) is never counted.
 */
if (!defined('ETEL_PUSH_MAX_FAILS')) define('ETEL_PUSH_MAX_FAILS', 5);

function etel_push_failure_counts($r) {
    if (!empty($r['ok'])) return false;
    $c = isset($r['http_code']) ? (int)$r['http_code'] : 0;
    return $c === 0 || $c === 400 || $c === 401 || $c === 403;
}

function etel_ensure_push_fail_column($pdo) {
    static $ok = null;
    if ($ok !== null) return $ok;
    $ok = false;
    try {
        $q = $pdo->query("SHOW COLUMNS FROM push_subscriptions LIKE 'fail_count'");
        if ($q && $q->fetch()) { $ok = true; return $ok; }
        $pdo->exec('ALTER TABLE push_subscriptions ADD COLUMN fail_count TINYINT UNSIGNED NOT NULL DEFAULT 0');
        $ok = true;
    } catch (Exception $e) {
        error_log('[ETEL webpush] fail_count column unavailable: ' . $e->getMessage());
    }
    return $ok;
}

/**
 * Send a push to every subscription a user has (they may have installed
 * the PWA on more than one device, or the native app on another), removing
 * any that come back dead. $pdo: active PDO connection. $userId: int.
 * $payload: assoc array. Branches on the `type` column (migration 059) -
 * 'expo' rows go through etel_send_expo_push, everything else (including
 * pre-migration rows with no type set) goes through the existing Web Push
 * path unchanged.
 */
function etel_push_to_user($pdo, $userId, $payload) {
    $results = array();
    $track = etel_ensure_push_fail_column($pdo);
    try {
        $s = $pdo->prepare('SELECT id, type, endpoint, p256dh, auth' . ($track ? ', fail_count' : '') . ' FROM push_subscriptions WHERE user_id = ?');
        $s->execute(array($userId));
        $subs = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('[ETEL webpush] could not read subscriptions: ' . $e->getMessage());
        return $results;
    }
    // Web Push to ALL of a user's devices at once. A user can have many stale
    // subscriptions (one per reinstall/re-login); sending them one after another
    // made the wait the SUM of every slow or unreachable push service. In parallel
    // the wait is only the slowest single attempt (capped by the cURL timeouts).
    $t0 = microtime(true);
    $out = array();
    $web = array();
    foreach ($subs as $i => $sub) {
        $type = isset($sub['type']) ? $sub['type'] : 'webpush';
        if ($type === 'expo') {
            $out[$i] = etel_send_expo_push($sub['endpoint'], $payload);
            continue;
        }
        $subscription = array('endpoint' => $sub['endpoint'], 'keys' => array('p256dh' => $sub['p256dh'], 'auth' => $sub['auth']));
        $err = null;
        $ch = etel_webpush_make_handle($subscription, $payload, $err);
        if (!$ch) { $out[$i] = $err; continue; }
        $web[$i] = $ch;
    }
    if (!empty($web)) {
        $mh = curl_multi_init();
        foreach ($web as $ch) curl_multi_add_handle($mh, $ch);
        $running = 0;
        do { $status = curl_multi_exec($mh, $running); } while ($status === CURLM_CALL_MULTI_PERFORM);
        while ($running > 0 && $status === CURLM_OK) {
            if (curl_multi_select($mh, 1.0) === -1) usleep(100000);
            do { $status = curl_multi_exec($mh, $running); } while ($status === CURLM_CALL_MULTI_PERFORM);
        }
        $codes = array();
        while (($info = curl_multi_info_read($mh)) !== false) {
            if ($info['msg'] === CURLMSG_DONE) {
                $hid = is_object($info['handle']) ? spl_object_id($info['handle']) : (int)$info['handle'];
                $codes[$hid] = $info['result'];
            }
        }
        foreach ($web as $i => $ch) {
            $hid = is_object($ch) ? spl_object_id($ch) : (int)$ch;
            $out[$i] = etel_webpush_result_from_handle($ch, curl_multi_getcontent($ch), isset($codes[$hid]) ? $codes[$hid] : null);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    }
    $ms = (int)round((microtime(true) - $t0) * 1000);
    if ($ms > 1500) error_log('[ETEL][timing] push to user ' . $userId . ': ' . count($subs) . ' subscription(s) took ' . $ms . ' ms');

    foreach ($subs as $i => $sub) {
        $type = isset($sub['type']) ? $sub['type'] : 'webpush';
        $r = isset($out[$i]) ? $out[$i] : array('ok' => false, 'http_code' => 0, 'error' => 'no result');
        $results[] = $r;
        if (!$r['ok'] && in_array($r['http_code'], array(404, 410), true)) {
            try {
                $pdo->prepare('DELETE FROM push_subscriptions WHERE id = ?')->execute(array($sub['id']));
            } catch (Exception $e) {}
        } elseif (!$r['ok']) {
            error_log('[ETEL webpush] send failed for user ' . $userId . ' (' . $type . '): ' . $r['error']);
            if ($track && etel_push_failure_counts($r)) {
                try {
                    if ((int)$sub['fail_count'] + 1 >= ETEL_PUSH_MAX_FAILS) {
                        $pdo->prepare('DELETE FROM push_subscriptions WHERE id = ?')->execute(array($sub['id']));
                        error_log('[ETEL webpush] removed stale subscription ' . $sub['id'] . ' for user ' . $userId);
                    } else {
                        $pdo->prepare('UPDATE push_subscriptions SET fail_count = fail_count + 1 WHERE id = ?')->execute(array($sub['id']));
                    }
                } catch (Exception $e) {}
            }
        } elseif ($track && !empty($sub['fail_count'])) {
            try { $pdo->prepare('UPDATE push_subscriptions SET fail_count = 0 WHERE id = ?')->execute(array($sub['id'])); } catch (Exception $e) {}
        }
    }
    return $results;
}
