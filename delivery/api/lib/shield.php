<?php
/**
 * Etel Shield - the protection layer in front of the API (our own "Cloudflare"-style shield, no bot check / captcha).
 * boot.php calls etel_shield_guard() right after it reads the request, before any real work. In this order it:
 *   1. finds the real connection address (proxy_mode: only trusts a proxy header when told to),
 *   2. lets the owner's own addresses and this server through,
 *   3. refuses blocked connections (the owner's list, and the automatic blocks below),
 *   4. refuses hacking tools (User-Agent) and over-size requests,
 *   5. slows down floods: signed-in people per sign-in token, signed-out traffic per connection (stricter for sign-in and sign-up), 429 + Retry-After,
 *   6. looks for attack patterns in what was sent (see shield_waf.php; starts in "monitor": only logs),
 *   7. writes everything it notices to the security log (Admin > Security).
 * Automatic blocks (escalating 15 min, 1 h, 1 day): a hacking tool; 3 blocked attack requests in 10 minutes; 5 refusals of a clear flood (twice the limit) of SIGNED-OUT traffic in 10 minutes.
 * Signed-in people are slowed down but never blocked by address (phone networks share one address between many people).
 * A hit costs one tiny file write in the temp folder, no database. Everything fails open. Emergency switch: SHIELD_OFF=1 in api/.env. PHP 5 style, ASCII only.
 */
require_once __DIR__ . '/shield_store.php';
require_once __DIR__ . '/shield_waf.php';
require_once __DIR__ . '/shield_geo.php';

if (!function_exists('etel_shield_guard')) {

/* Cloudflare's published address ranges: a CF-Connecting-IP header is believed only when the request really comes from one of them. */
function etel_shield_cf_ranges() {
    return array('173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
                 '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
                 '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32');
}

/* The connection address of this request (also the country Cloudflare reports, when it does). */
function etel_shield_client($cfg) {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    $country = '';
    if ($cfg['proxy_mode'] === 'cloudflare' && etel_shield_in_list($ip, etel_shield_cf_ranges()) && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $c = trim((string) $_SERVER['HTTP_CF_CONNECTING_IP']);
        if (@inet_pton($c) !== false) $ip = $c;
        if (!empty($_SERVER['HTTP_CF_IPCOUNTRY'])) $country = strtoupper(substr((string) $_SERVER['HTTP_CF_IPCOUNTRY'], 0, 2));
    } elseif ($cfg['proxy_mode'] === 'any_xff' && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {   /* TESTING ONLY: anyone can set this header */
        $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
        $c = trim($parts[0]);
        if (@inet_pton($c) !== false) $ip = $c;
    }
    return array($ip, $country);
}

/* this server itself, local networks: never limited */
function etel_shield_is_internal($ip) {
    if ($ip === '') return true;
    return etel_shield_in_list($ip, array('127.0.0.0/8', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', 'fc00::/7', 'fe80::/10'));
}

/* Counts one hit for $key in the current minute (a tiny append to a file); returns the hits so far this minute. -1 = cannot count (fail open). */
function etel_shield_hit($key, $windowSec) {
    $tmp = etel_shield_tmp();
    if ($tmp === '') return -1;
    $bucket = (int) floor(time() / $windowSec);
    $f = $tmp . '/h.' . substr(md5($key), 0, 16) . '.' . $bucket;
    $h = @fopen($f, 'ab');
    if (!$h) return -1;
    @fwrite($h, '1'); @fclose($h);
    clearstatcache(true, $f);
    if (mt_rand(1, 300) === 1) etel_shield_sweep($tmp);
    return (int) @filesize($f);
}

/* removes counter files that are over 15 minutes old */
function etel_shield_sweep($tmp) {
    $now = time();
    foreach ((array) @glob($tmp . '/[hl].*') as $f) { if (@filemtime($f) < $now - 900) @unlink($f); }
}

function etel_shield_action_class($action) {
    if (preg_match('/(^|_)(login|signin|forgot|reset_password|verify_otp|send_otp|otp)(_|$)/', $action)) return 'login';
    if ($action === 'register' || preg_match('/(^|_)(register|signup)$/', $action)) return 'signup';
    return 'other';
}

/* Sends the refusal and stops. $rule goes in the answer as "code" so the app can tell the cases apart. */
function etel_shield_deny($status, $msg, $rule, $retry) {
    if ($retry > 0 && !headers_sent()) header('Retry-After: ' . intval($retry));
    if (function_exists('fail')) fail($msg, $status, array('code' => $rule));
    http_response_code($status);
    echo json_encode(array('success' => false, 'message' => $msg, 'code' => $rule));
    exit;
}

/* Counts a "strike" against a connection; at the limit it is blocked, longer each time. */
function etel_shield_strike($ip, $kind, $limit, $cfg, $action, $country) {
    $n = etel_shield_hit('strike|' . $kind . '|' . $ip, 600);
    if ($n < $limit) return;
    try {
        $pdo = db();
        etel_shield_ensure_tables($pdo);
        $q = $pdo->prepare("SELECT COUNT(*) FROM shield_events WHERE ip = ? AND rule = 'ban' AND at > DATE_SUB(NOW(), INTERVAL 1 DAY)");
        $q->execute(array($ip));
        $level = min(count($cfg['ban_minutes']) - 1, intval($q->fetchColumn()));
        $minutes = intval($cfg['ban_minutes'][$level]);
        etel_shield_ban($pdo, $ip, $kind, $minutes, 'shield');
        $pdo->prepare("INSERT INTO shield_events (at, ip, rule, mode, action, detail, ua, country) VALUES (NOW(), ?, 'ban', 'block', ?, ?, '', ?)")
            ->execute(array(substr($ip, 0, 45), substr((string) $action, 0, 60), substr('blocked for ' . $minutes . ' minutes: ' . $kind, 0, 255), substr((string) $country, 0, 2)));
    } catch (Exception $e) { /* fail open */ }
}

/* The guard. $body is the decoded JSON body, $raw the raw body. */
function etel_shield_guard($action, $body, $raw) {
    try {
        if (function_exists('header_remove')) @header_remove('X-Powered-By');
        if (etel_shield_env_off()) return;
        $cfg = etel_shield_cfg();
        if (empty($cfg['enabled'])) return;
        $c = etel_shield_client($cfg); $ip = $c[0]; $country = $c[1];
        if ($ip === '' || etel_shield_is_internal($ip) || etel_shield_in_list($ip, $cfg['allow_ips'])) return;
        $GLOBALS['ETEL_SHIELD_IP'] = $ip;

        /* 3. blocked connections: the owner's list, then the automatic blocks */
        if (etel_shield_in_list($ip, $cfg['deny_ips'])) etel_shield_deny(403, 'Access from your connection is blocked.', 'blocked', 0);
        $bans = etel_shield_bans();
        if (isset($bans[$ip]) && $bans[$ip] > time()) etel_shield_deny(403, 'Access from your connection is temporarily blocked. Please try again later.', 'blocked', $bans[$ip] - time());

        /* 4. hacking tools, over-size requests */
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
        if ($cfg['scanner_mode'] !== 'off' && $ua !== '' && preg_match(etel_shield_scanner_ua(), $ua)) {
            etel_shield_log('scanner', $cfg['scanner_mode'], $ip, $action, substr($ua, 0, 100), $country);
            if ($cfg['scanner_mode'] === 'block') {
                etel_shield_strike($ip, 'hacking tool', 1, $cfg, $action, $country);
                etel_shield_deny(403, 'Request refused.', 'blocked', 0);
            }
        }
        if (strlen($raw) > $cfg['max_body_kb'] * 1024) {
            etel_shield_log('oversize', 'block', $ip, $action, strlen($raw) . ' bytes', $country);
            etel_shield_deny(413, 'The request is too large.', 'too_large', 0);
        }

        /* 4b. Ethiopia only: the connection (monitor = log only) */
        if ($cfg['geo_mode'] !== 'off' && !etel_shield_waf_exempt($action, $cfg['geo_exempt_actions']) && etel_shield_geo_foreign($ip, $country, $cfg)) {
            etel_shield_log('geo_ip', $cfg['geo_mode'], $ip, $action, 'connection outside Ethiopia' . ($country !== '' ? ' (' . $country . ')' : ''), $country);
            if ($cfg['geo_mode'] === 'enforce') etel_shield_deny(403, 'This service is available inside Ethiopia only.', 'geo_blocked', 0);
        }

        /* 5. floods */
        if ($cfg['rate_mode'] !== 'off') {
            $auth = isset($_SERVER['HTTP_AUTHORIZATION']) ? (string) $_SERVER['HTTP_AUTHORIZATION'] : (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) ? (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] : '');
            $class = etel_shield_action_class($action);
            if ($class === 'login') { $key = 'login|' . $ip; $limit = $cfg['rate_login_per_min']; $signedIn = false; }
            elseif ($class === 'signup') { $key = 'signup|' . $ip; $limit = $cfg['rate_signup_per_min']; $signedIn = false; }
            elseif (stripos($auth, 'Bearer ') === 0 && strlen($auth) > 20) { $key = 'tok|' . $auth; $limit = $cfg['rate_auth_per_min']; $signedIn = true; }
            else { $key = 'anon|' . $ip; $limit = $cfg['rate_anon_per_min']; $signedIn = false; }
            $n = etel_shield_hit($key, 60);
            if ($n > $limit) {
                etel_shield_log('rate_limit', $cfg['rate_mode'], $ip, $action, $class . ' ' . $n . '/' . $limit . ' per minute' . ($signedIn ? ' (signed in)' : ''), $country);
                if ($cfg['rate_mode'] === 'block') {
                    /* only a clear flood (twice the limit or more) counts toward a block, and never for signed-in people: many phones share one address */
                    if (!$signedIn && $n > $limit * 2) etel_shield_strike($ip, 'flood', 5, $cfg, $action, $country);
                    etel_shield_deny(429, 'Too many requests. Please slow down and try again in a minute.', 'rate_limited', 60 - (time() % 60));
                }
            }
        }

        /* 5b. Ethiopia only: the position the app sends (admin screens are not checked) */
        if ($cfg['geo_device_mode'] !== 'off' && strpos($action, 'admin_') !== 0 && !etel_shield_waf_exempt($action, $cfg['geo_exempt_actions'])) {
            $out = etel_shield_geo_device_out($body);
            if ($out) {
                etel_shield_log('geo_device', $cfg['geo_device_mode'], $ip, $action, $out[0] . ' = ' . round($out[1], 4) . ',' . round($out[2], 4) . ' is outside Ethiopia', $country);
                if ($cfg['geo_device_mode'] === 'enforce') etel_shield_deny(403, 'This service works inside Ethiopia only: your device position is outside Ethiopia.', 'geo_device', 0);
            }
        }

        /* 6. attack patterns */
        if ($cfg['waf_mode'] !== 'off' && !etel_shield_waf_exempt($action, $cfg['waf_exempt_actions'])) {
            $hit = etel_shield_waf_scan($_GET, $body);
            if ($hit) {
                etel_shield_log('waf_' . $hit[0], $cfg['waf_mode'], $ip, $action, $hit[1] . ': ' . $hit[2], $country);
                if ($cfg['waf_mode'] === 'block') {
                    etel_shield_strike($ip, 'attack requests', 3, $cfg, $action, $country);
                    etel_shield_deny(403, 'Request refused for security reasons.', 'waf', 0);
                }
            }
        }
    } catch (Exception $e) { /* a broken shield never takes the system down */ } catch (Throwable $e) { }
}

}
