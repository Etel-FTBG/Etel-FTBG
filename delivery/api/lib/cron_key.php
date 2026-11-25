<?php
/**
 * Secret keys kept out of web addresses (cron jobs, diagnose.php, etel-os.php, updater.php).
 * A key in the address (...?key=SECRET) ends up in the server's access log, in browser history and in any proxy. These files now read the key from a
 * HEADER first:  X-Cron-Key  (cron jobs)  or  X-Etel-Key  (diagnose.php, etel-os.php)  or  X-Auth (updater.php, as before).
 * A key still found in the address is handled by the Etel Shield setting url_key_mode: allow | monitor (works, and each use is written in the security log as
 * keyurl_<file>, so you can see which cron job still uses the old address) | refuse (the key in the address is ignored). Starts on monitor.
 * Cron jobs in cPanel:  curl -s -H "X-Cron-Key: YOUR_KEY" https://YOUR-SITE/delivery/api/cron_dispatch_scheduled.php > /dev/null
 * Fails open to the old behaviour only on monitor / allow; never reveals the key. PHP 5 style, ASCII only.
 */
require_once __DIR__ . '/shield.php';   /* settings, the connection address and the security log */

if (!function_exists('etel_secret_from_request')) {

/* One request header by name (X-Cron-Key -> HTTP_X_CRON_KEY, or from getallheaders on hosts that do not pass it on). */
function etel_req_header($name) {
    $k = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$k]) && is_string($_SERVER[$k]) && trim($_SERVER[$k]) !== '') return trim($_SERVER[$k]);
    if (function_exists('getallheaders')) {
        foreach ((array) getallheaders() as $hk => $hv) {
            if (strcasecmp($hk, $name) === 0 && is_string($hv) && trim($hv) !== '') return trim($hv);
        }
    }
    return '';
}

/* May a secret key found in the web address be used? (url_key_mode). In monitor mode the use is written to the security log. */
function etel_key_url_allowed($what) {
    try {
        $cfg = etel_shield_cfg();
        $mode = isset($cfg['url_key_mode']) ? $cfg['url_key_mode'] : 'monitor';
        if ($mode === 'refuse') return false;
        if ($mode === 'monitor') {
            $c = etel_shield_client($cfg);
            $rule = 'keyurl_' . preg_replace('/[^a-z0-9_]/', '', strtolower(substr(basename((string) $what, '.php'), 0, 30)));
            etel_shield_log($rule, 'monitor', $c[0], (string) $what, 'secret key in the web address: move it to a header (see lib/cron_key.php)', $c[1]);
        }
        return true;
    } catch (Exception $e) { return true; } catch (Throwable $e) { return true; }
}

/* The key of this request: the first of $headers that is present, else (when allowed) the query value $get. '' when there is none. */
function etel_secret_from_request($headers, $get, $what) {
    foreach ((array) $headers as $h) {
        $v = etel_req_header($h);
        if ($v !== '') return $v;
    }
    if (isset($_GET[$get]) && is_string($_GET[$get]) && trim($_GET[$get]) !== '') {
        return etel_key_url_allowed($what) ? trim($_GET[$get]) : '';
    }
    return '';
}

}
