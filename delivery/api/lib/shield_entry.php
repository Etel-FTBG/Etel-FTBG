<?php
/**
 * Etel Shield - the same protection for the files that are NOT behind boot.php: uploads, document downloads, private files, the payment return page,
 * the trip share link, the payment and Telegram callbacks, and the error / policy report sinks. Each file calls etel_shield_entry('<kind>') on its first lines.
 *
 *   upload   people signed in sending or fetching files   blocked connections, hacking tools, Ethiopia-only (connection), flood limit per sign-in token
 *   public   open pages (trip share, payment return, private file links)   the same, flood limit per connection
 *   callback payment and Telegram servers calling back    ONLY the owner's block list and a high flood limit: never by country, never an automatic block
 *   sink     error and policy reports from the apps       flood limit per connection; over the limit the answer is an empty 204 (the apps ignore it)
 *
 * Uses the same settings, counters, block list and security log as boot.php (entry_mode: off | monitor | block). Fails open on any error.
 * Emergency switch: SHIELD_OFF=1 in api/.env (read here by itself, because these files do not load boot.php). PHP 5 style, ASCII only.
 */
require_once __DIR__ . '/shield.php';

if (!function_exists('etel_shield_entry')) {

/* SHIELD_OFF=1 in api/.env, also for the files that do not load boot.php */
function etel_shield_entry_env_off() {
    if (etel_shield_env_off()) return true;
    try {
        require_once dirname(__DIR__) . '/env_loader.php';
        $e = etel_parse_env_file(dirname(__DIR__) . '/.env');
        $v = isset($e['SHIELD_OFF']) ? strtolower(trim((string) $e['SHIELD_OFF'])) : '';
        return ($v === '1' || $v === 'true' || $v === 'on' || $v === 'yes');
    } catch (Exception $x) { return false; } catch (Throwable $x) { return false; }
}

/* The refusal: JSON for people, an empty 204 for the report sinks, a plain 429 for callbacks. */
function etel_shield_entry_refuse($kind, $status, $msg, $rule, $retry) {
    while (ob_get_level()) { @ob_end_clean(); }
    if ($retry > 0 && !headers_sent()) header('Retry-After: ' . intval($retry));
    if ($kind === 'sink') { http_response_code(204); exit; }
    http_response_code($status);
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('success' => false, 'ok' => false, 'message' => $msg, 'code' => $rule));
    exit;
}

function etel_shield_entry($kind) {
    try {
        if (etel_shield_entry_env_off()) return;
        $cfg = etel_shield_cfg();
        if (empty($cfg['enabled']) || $cfg['entry_mode'] === 'off') return;
        $c = etel_shield_client($cfg); $ip = $c[0]; $country = $c[1];
        if ($ip === '' || etel_shield_is_internal($ip) || etel_shield_in_list($ip, $cfg['allow_ips'])) return;
        $what = 'file:' . basename(isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : 'entry');
        $block = ($cfg['entry_mode'] === 'block');

        /* the owner's block list always applies; the automatic blocks apply to everything except the callbacks */
        if (etel_shield_in_list($ip, $cfg['deny_ips'])) etel_shield_entry_refuse($kind, 403, 'Access from your connection is blocked.', 'blocked', 0);
        if ($kind !== 'callback') {
            $bans = etel_shield_bans();
            if (isset($bans[$ip]) && $bans[$ip] > time()) etel_shield_entry_refuse($kind, 403, 'Access from your connection is temporarily blocked. Please try again later.', 'blocked', $bans[$ip] - time());
        }

        /* hacking tools */
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
        if ($kind !== 'callback' && $cfg['scanner_mode'] !== 'off' && $ua !== '' && preg_match(etel_shield_scanner_ua(), $ua)) {
            $refuse = ($block && $cfg['scanner_mode'] === 'block');
            etel_shield_log('scanner', $refuse ? 'block' : 'monitor', $ip, $what, substr($ua, 0, 100), $country);
            if ($refuse) {
                etel_shield_strike($ip, 'hacking tool', 1, $cfg, $what, $country);
                etel_shield_entry_refuse($kind, 403, 'Request refused.', 'blocked', 0);
            }
        }

        /* Ethiopia only: the connection (people's files only; the payment servers and the report sinks are never asked) */
        if (($kind === 'upload' || $kind === 'public') && $cfg['geo_mode'] !== 'off' && etel_shield_geo_foreign($ip, $country, $cfg)) {
            $refuse = ($block && $cfg['geo_mode'] === 'enforce');
            etel_shield_log('geo_ip', $refuse ? 'block' : 'monitor', $ip, $what, 'connection outside Ethiopia' . ($country !== '' ? ' (' . $country . ')' : ''), $country);
            if ($refuse) etel_shield_entry_refuse($kind, 403, 'This service is available inside Ethiopia only.', 'geo_blocked', 0);
        }

        /* floods */
        $auth = isset($_SERVER['HTTP_AUTHORIZATION']) ? (string) $_SERVER['HTTP_AUTHORIZATION'] : (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) ? (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] : '');
        $signedIn = false;
        if ($kind === 'upload') {
            if (stripos($auth, 'Bearer ') === 0 && strlen($auth) > 20) { $key = 'etok|' . $auth; $signedIn = true; } else { $key = 'eup|' . $ip; }
            $limit = $cfg['rate_upload_per_min'];
        } elseif ($kind === 'public') { $key = 'epub|' . $ip; $limit = $cfg['rate_public_per_min']; }
        elseif ($kind === 'callback') { $key = 'ecb|' . $ip; $limit = $cfg['rate_callback_per_min']; }
        else { $key = 'esink|' . $ip; $limit = $cfg['rate_sink_per_min']; }
        $n = etel_shield_hit($key, 60);
        if ($n > $limit) {
            etel_shield_log('rate_limit', $cfg['entry_mode'], $ip, $what, $kind . ' ' . $n . '/' . $limit . ' per minute', $country);
            if ($block) {
                /* a clear flood of open or signed-out traffic counts toward a block; signed-in people, the callbacks and the sinks never do */
                if (($kind === 'public' || ($kind === 'upload' && !$signedIn)) && $n > $limit * 2) etel_shield_strike($ip, 'flood', 5, $cfg, $what, $country);
                etel_shield_entry_refuse($kind, 429, 'Too many requests. Please slow down and try again in a minute.', 'rate_limited', 60 - (time() % 60));
            }
        }
    } catch (Exception $e) { /* a broken shield never takes the system down */ } catch (Throwable $e) { }
}

}
