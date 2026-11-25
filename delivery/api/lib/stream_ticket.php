<?php
/**
 * Stream tickets - keeps the login token out of web addresses.
 * A live stream (EventSource) cannot send a header, so until now the app put the whole login token in the address, and the token ended up in server logs,
 * proxies and browser history. Now the app first asks for a ticket (stream_ticket, signed in with the normal header) and puts only the ticket in the address.
 * A ticket is sealed (AES-256-CBC + HMAC with a key made from TOKEN_SECRET), so the address shows nothing readable, and it only works
 *   - for the one stream action it was made for (and the one id, for order and ride streams),
 *   - for 2 minutes.
 * The login token is inside the ticket, so every normal check (disabled account, signed-out device, idle sign-out) still runs on the real token.
 * Fails closed: a bad, old or wrong-purpose ticket gives nothing. Needs the openssl extension; without it no ticket is made and the app falls back.
 * PHP 5 style, ASCII only.
 */
if (!function_exists('etel_ticket_open')) {

function etel_ticket_key() {
    return hash('sha256', 'etel-stream-ticket|' . (defined('TOKEN_SECRET_FINAL') ? TOKEN_SECRET_FINAL : ''), true);
}

function etel_ticket_b64e($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function etel_ticket_b64d($s) { return base64_decode(strtr((string) $s, '-_', '+/')); }

/* Seals $token for $action (and $id, '' = any) for $secs seconds. Returns '' when it cannot. */
function etel_ticket_make($token, $action, $id, $secs) {
    if (!function_exists('openssl_encrypt') || $token === '' || !defined('TOKEN_SECRET_FINAL') || TOKEN_SECRET_FINAL === '') return '';
    $key = etel_ticket_key();
    $iv = function_exists('openssl_random_pseudo_bytes') ? openssl_random_pseudo_bytes(16) : '';
    if (strlen($iv) !== 16) return '';
    $plain = json_encode(array('t' => $token, 'a' => (string) $action, 'i' => (string) $id, 'e' => time() + max(10, min(600, intval($secs)))));
    $ct = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($ct === false) return '';
    return etel_ticket_b64e($iv . hash_hmac('sha256', $iv . $ct, $key, true) . $ct);
}

/* The login token inside a good ticket, or '' (wrong purpose, wrong id, expired, tampered, unreadable). */
function etel_ticket_open($ticket, $action, $id) {
    try {
        if (!function_exists('openssl_decrypt') || !is_string($ticket) || strlen($ticket) < 60 || strlen($ticket) > 2000) return '';
        $raw = etel_ticket_b64d($ticket);
        if (!is_string($raw) || strlen($raw) < 50) return '';
        $key = etel_ticket_key();
        $iv = substr($raw, 0, 16); $mac = substr($raw, 16, 32); $ct = substr($raw, 48);
        $want = hash_hmac('sha256', $iv . $ct, $key, true);
        $ok = function_exists('hash_equals') ? hash_equals($want, $mac) : ($want === $mac);
        if (!$ok) return '';
        $plain = openssl_decrypt($ct, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        $j = $plain === false ? null : @json_decode($plain, true);
        if (!is_array($j) || !isset($j['t'], $j['a'], $j['i'], $j['e'])) return '';
        if (intval($j['e']) < time() || $j['a'] !== (string) $action) return '';
        if ($j['i'] !== '' && $j['i'] !== (string) $id) return '';
        return (string) $j['t'];
    } catch (Exception $e) { return ''; } catch (Throwable $e) { return ''; }
}

/* url_token_mode (Etel Shield settings): allow | monitor | refuse - what to do when an old app still puts the login token in the address. */
function etel_url_token_mode() {
    try {
        require_once __DIR__ . '/shield.php';
        $cfg = etel_shield_cfg();
        return isset($cfg['url_token_mode']) ? $cfg['url_token_mode'] : 'monitor';
    } catch (Exception $e) { return 'monitor'; } catch (Throwable $e) { return 'monitor'; }
}

/* Called when a login token arrives in the address. Returns true when it may be used. In monitor mode it is written to the security log (once a minute for each address). */
function etel_url_token_note($action) {
    $mode = etel_url_token_mode();
    if ($mode === 'refuse') return false;
    if ($mode === 'monitor') {
        try {
            $cfg = etel_shield_cfg();
            $c = etel_shield_client($cfg);
            etel_shield_log('token_in_url', 'monitor', $c[0], (string) $action, 'login token in the web address (an app that is not updated yet)', $c[1]);
        } catch (Exception $e) { } catch (Throwable $e) { }
    }
    return true;
}

}
