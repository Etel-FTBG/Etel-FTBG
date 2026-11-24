<?php
/**
 * Etel Shield - settings, blocked connections and the security log (the data side).
 * Normal requests never touch the database for this: the settings and the ban list are kept in small files in the server's temp folder (refreshed from the
 * database at most once a minute, and at once when the owner saves). Fails open on any error: a broken shield must never take the system down.
 * PHP 5 style, ASCII only.
 */
if (!function_exists('etel_shield_defaults')) {

function etel_shield_defaults() {
    return array(
        'enabled'           => 1,            /* master switch (emergency: SHIELD_OFF=1 in api/.env) */
        'proxy_mode'        => 'none',       /* none = trust only the connection itself | cloudflare = CF-Connecting-IP when the request really comes from Cloudflare | any_xff = TESTING ONLY */
        'rate_mode'         => 'block',      /* off | monitor | block */
        'rate_anon_per_min' => 1500,         /* signed-out requests per connection per minute (phone networks share addresses: this is generous) */
        'rate_auth_per_min' => 600,          /* signed-in requests per sign-in token per minute */
        'rate_login_per_min' => 60,          /* sign-in / password / code requests per connection per minute */
        'rate_signup_per_min' => 20,         /* sign-up requests per connection per minute */
        'ban_minutes'       => array(15, 60, 1440),   /* first, second, later block of the same connection */
        'scanner_mode'      => 'block',      /* hacking tools (sqlmap, nikto ...) */
        'waf_mode'          => 'monitor',    /* attack patterns in what was sent: off | monitor (log only) | block */
        'geo_mode'          => 'off',        /* Ethiopia only: off | monitor | enforce (see shield_geo.php) */
        'max_body_kb'       => 2048,         /* biggest request the API accepts */
        'allow_ips'         => array(),      /* never limited or blocked (office, owner) - exact address or CIDR */
        'deny_ips'          => array(),      /* always blocked */
        'waf_exempt_actions' => array('admin_doc*', 'admin_legal_save', 'admin_hub_content_save', 'admin_hub_settings_save'),
        'keep_days'         => 30
    );
}

function etel_shield_tmp() {
    static $d = null;
    if ($d !== null) return $d;
    $d = rtrim(sys_get_temp_dir(), '/\\') . '/etel_shield_' . substr(md5(__DIR__), 0, 10);
    if (!is_dir($d)) @mkdir($d, 0700, true);
    if (!is_dir($d) || !is_writable($d)) $d = '';
    return $d;
}

function etel_shield_env_off() {
    $v = function_exists('etel_env') ? strtolower(trim((string) etel_env('SHIELD_OFF', 'CFG_SHIELD_OFF', ''))) : '';
    return ($v === '1' || $v === 'true' || $v === 'on' || $v === 'yes');
}

/* Keeps only the settings we know, in the right type and range. */
function etel_shield_clean($in) {
    $d = etel_shield_defaults();
    $o = $d;
    if (!is_array($in)) return $o;
    $modes3 = array('off', 'monitor', 'block');
    foreach (array('enabled') as $k) { if (isset($in[$k])) $o[$k] = $in[$k] ? 1 : 0; }
    if (isset($in['proxy_mode']) && in_array($in['proxy_mode'], array('none', 'cloudflare', 'any_xff'), true)) $o['proxy_mode'] = $in['proxy_mode'];
    foreach (array('rate_mode', 'scanner_mode', 'waf_mode') as $k) { if (isset($in[$k]) && in_array($in[$k], $modes3, true)) $o[$k] = $in[$k]; }
    if (isset($in['geo_mode']) && in_array($in['geo_mode'], array('off', 'monitor', 'enforce'), true)) $o['geo_mode'] = $in['geo_mode'];
    $lim = array('rate_anon_per_min' => array(30, 100000), 'rate_auth_per_min' => array(30, 100000), 'rate_login_per_min' => array(3, 10000), 'rate_signup_per_min' => array(2, 10000), 'max_body_kb' => array(64, 51200), 'keep_days' => array(1, 365));
    foreach ($lim as $k => $r) { if (isset($in[$k]) && is_numeric($in[$k])) $o[$k] = max($r[0], min($r[1], intval($in[$k]))); }
    if (isset($in['ban_minutes']) && is_array($in['ban_minutes'])) {
        $b = array();
        foreach ($in['ban_minutes'] as $m) { if (is_numeric($m)) $b[] = max(1, min(10080, intval($m))); }
        if ($b) $o['ban_minutes'] = array_slice($b, 0, 5);
    }
    foreach (array('allow_ips', 'deny_ips') as $k) {
        if (isset($in[$k]) && is_array($in[$k])) {
            $l = array();
            foreach ($in[$k] as $ip) { $ip = trim((string) $ip); if ($ip !== '' && etel_shield_valid_ip_or_cidr($ip)) $l[] = $ip; }
            $o[$k] = array_values(array_unique(array_slice($l, 0, 200)));
        }
    }
    if (isset($in['waf_exempt_actions']) && is_array($in['waf_exempt_actions'])) {
        $l = array();
        foreach ($in['waf_exempt_actions'] as $a) { $a = trim((string) $a); if ($a !== '' && preg_match('/^[a-z0-9_*]{2,60}$/i', $a)) $l[] = $a; }
        $o['waf_exempt_actions'] = array_values(array_unique(array_slice($l, 0, 100)));
    }
    return $o;
}

function etel_shield_valid_ip_or_cidr($s) {
    $p = explode('/', $s, 2);
    if (@inet_pton($p[0]) === false) return false;
    if (count($p) === 2) { $max = strpos($p[0], ':') !== false ? 128 : 32; if (!ctype_digit($p[1]) || intval($p[1]) > $max) return false; }
    return true;
}

/* true when $ip is inside $cidr (or equal to it): IPv4 and IPv6 */
function etel_shield_cidr_match($ip, $cidr) {
    $p = explode('/', $cidr, 2);
    $a = @inet_pton($ip); $b = @inet_pton($p[0]);
    if ($a === false || $b === false || strlen($a) !== strlen($b)) return false;
    $bits = count($p) === 2 ? intval($p[1]) : strlen($a) * 8;
    $bytes = (int) floor($bits / 8); $rem = $bits % 8;
    if ($bytes > 0 && substr($a, 0, $bytes) !== substr($b, 0, $bytes)) return false;
    if ($rem === 0) return true;
    $mask = (0xFF << (8 - $rem)) & 0xFF;
    return ((ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask));
}

function etel_shield_in_list($ip, $list) {
    foreach ($list as $c) { if (etel_shield_cidr_match($ip, $c)) return true; }
    return false;
}

/* The settings: the temp-folder copy when it is fresh (60 s), else the database, else the defaults. */
function etel_shield_cfg($pdo = null) {
    static $cfg = null;
    if ($cfg !== null && $pdo === null) return $cfg;
    $tmp = etel_shield_tmp(); $f = $tmp !== '' ? $tmp . '/cfg.json' : '';
    if ($pdo === null && $f !== '' && is_file($f) && filemtime($f) > time() - 60) {
        $j = @json_decode(@file_get_contents($f), true);
        if (is_array($j)) { $cfg = etel_shield_clean($j); return $cfg; }
    }
    $saved = null;
    try {
        if ($pdo === null && function_exists('db')) $pdo = db();
        if ($pdo !== null) {
            $q = $pdo->prepare("SELECT rule_value FROM pricing_rules WHERE rule_key = 'shield_config' LIMIT 1");
            $q->execute();
            $v = $q->fetchColumn();
            if ($v) $saved = @json_decode($v, true);
        }
    } catch (Exception $e) { $saved = null; }
    $cfg = etel_shield_clean(is_array($saved) ? $saved : array());
    if ($f !== '') @file_put_contents($f, json_encode($cfg), LOCK_EX);
    return $cfg;
}

function etel_shield_cfg_save($pdo, $in) {
    $cfg = etel_shield_clean($in);
    $pdo->prepare("INSERT INTO pricing_rules (rule_key, rule_value, description) VALUES ('shield_config', ?, 'Etel Shield settings (Admin > Security)') ON DUPLICATE KEY UPDATE rule_value = VALUES(rule_value)")
        ->execute(array(json_encode($cfg)));
    $tmp = etel_shield_tmp();
    if ($tmp !== '') @unlink($tmp . '/cfg.json');
    return etel_shield_cfg($pdo);
}

function etel_shield_ensure_tables($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `shield_events` (`id` BIGINT(20) NOT NULL AUTO_INCREMENT, `at` DATETIME NOT NULL, `ip` VARCHAR(45) NOT NULL DEFAULT '', `rule` VARCHAR(40) NOT NULL DEFAULT '', `mode` VARCHAR(10) NOT NULL DEFAULT '', `action` VARCHAR(60) NOT NULL DEFAULT '', `detail` VARCHAR(255) NOT NULL DEFAULT '', `ua` VARCHAR(120) NOT NULL DEFAULT '', `country` VARCHAR(2) NOT NULL DEFAULT '', PRIMARY KEY (`id`), KEY `idx_at` (`at`), KEY `idx_ip` (`ip`, `at`), KEY `idx_rule` (`rule`, `at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS `shield_bans` (`id` INT(11) NOT NULL AUTO_INCREMENT, `ip` VARCHAR(45) NOT NULL, `reason` VARCHAR(120) NOT NULL DEFAULT '', `until_at` DATETIME NOT NULL, `created_at` DATETIME NOT NULL, `created_by` VARCHAR(40) NOT NULL DEFAULT 'shield', PRIMARY KEY (`id`), UNIQUE KEY `uq_ip` (`ip`), KEY `idx_until` (`until_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) { /* fail open */ }
}

/* array(ip => unix time the block ends) of the blocks that are still running: from the temp copy, else rebuilt from the database. */
function etel_shield_bans() {
    $tmp = etel_shield_tmp(); $f = $tmp !== '' ? $tmp . '/bans.json' : '';
    if ($f !== '' && is_file($f) && filemtime($f) > time() - 60) {
        $j = @json_decode(@file_get_contents($f), true);
        if (is_array($j)) return $j;
    }
    return etel_shield_bans_rebuild(null);
}

function etel_shield_bans_rebuild($pdo) {
    $out = array();
    try {
        if ($pdo === null && function_exists('db')) $pdo = db();
        if ($pdo !== null) {
            etel_shield_ensure_tables($pdo);
            foreach ($pdo->query("SELECT ip, UNIX_TIMESTAMP(until_at) AS u FROM shield_bans WHERE until_at > NOW()")->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['ip']] = intval($r['u']);
        }
    } catch (Exception $e) { return $out; }
    $tmp = etel_shield_tmp();
    if ($tmp !== '') @file_put_contents($tmp . '/bans.json', json_encode($out), LOCK_EX);
    return $out;
}

/* Blocks a connection for $minutes. */
function etel_shield_ban($pdo, $ip, $minutes, $reason, $by) {
    etel_shield_ensure_tables($pdo);
    $minutes = max(1, min(525600, intval($minutes)));
    $pdo->prepare("INSERT INTO shield_bans (ip, reason, until_at, created_at, created_by) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL $minutes MINUTE), NOW(), ?)
                   ON DUPLICATE KEY UPDATE reason = VALUES(reason), until_at = VALUES(until_at), created_at = NOW(), created_by = VALUES(created_by)")
        ->execute(array(substr($ip, 0, 45), substr($reason, 0, 120), substr($by, 0, 40)));
    etel_shield_bans_rebuild($pdo);
}

function etel_shield_unban($pdo, $ip) {
    etel_shield_ensure_tables($pdo);
    $pdo->prepare("DELETE FROM shield_bans WHERE ip = ?")->execute(array($ip));
    etel_shield_bans_rebuild($pdo);
}

/* One security-log row; the same connection and rule is logged at most once a minute (a flood must not flood the database). */
function etel_shield_log($rule, $mode, $ip, $action, $detail, $country) {
    try {
        $tmp = etel_shield_tmp();
        if ($tmp !== '') {
            $m = $tmp . '/l.' . md5($ip . '|' . $rule) . '.' . date('YmdHi');
            if (is_file($m)) return;
            @touch($m);
        }
        if (!function_exists('db')) return;
        $pdo = db();
        etel_shield_ensure_tables($pdo);
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 120) : '';
        $pdo->prepare("INSERT INTO shield_events (at, ip, rule, mode, action, detail, ua, country) VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?)")
            ->execute(array(substr($ip, 0, 45), substr($rule, 0, 40), substr($mode, 0, 10), substr((string) $action, 0, 60), substr((string) $detail, 0, 255), $ua, substr((string) $country, 0, 2)));
        if (mt_rand(1, 200) === 1) {
            $cfg = etel_shield_cfg();
            $pdo->exec("DELETE FROM shield_events WHERE at < DATE_SUB(NOW(), INTERVAL " . intval($cfg['keep_days']) . " DAY) LIMIT 5000");
            $pdo->exec("DELETE FROM shield_bans WHERE until_at < DATE_SUB(NOW(), INTERVAL 2 DAY)");
        }
    } catch (Exception $e) { /* fail open */ }
}

}
