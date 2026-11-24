<?php
/**
 * Automatic sign-out when the person is not using the app.
 *
 * "Not using" = no tap, key press or scroll for the number of minutes set for the role. The app (js/etel-idle-logout.js) reports real activity to the action
 * session_ping; background refreshes do NOT count, so a shop screen left open on a counter or a phone left on a table is signed out, while a person who is
 * working is not. The server enforces the same limit (get_auth_user), so a stolen or forgotten token stops working too, not just the screen.
 *
 * Limits (minutes, 0 = never), per role. Defaults below; the owner changes them in pricing_rules  idle_minutes_<role>  (Admin > Security).
 *   admin 15, customer 30, courier 30, driver 30, service_giver 240 (a shop waits for orders), agent 60, anything else 30.
 * Never signed out for being idle (the work is happening without taps): a courier who is online or has a job, a driver who is online.
 * Safety: a session that has never reported activity (an older app) is never signed out for being idle. Emergency switch: api/.env IDLE_TIMEOUT_OFF=1.
 * Fails open on any error. PHP 5 style, ASCII only.
 */
if (!function_exists('etel_idle_defaults')) {

function etel_idle_defaults() {
    return array('admin' => 15, 'customer' => 30, 'courier' => 30, 'driver' => 30, 'service_giver' => 240, 'agent' => 60, 'other' => 30);
}

function etel_idle_roles() { return array_keys(etel_idle_defaults()); }

/* true when the owner's emergency switch is on (api/.env IDLE_TIMEOUT_OFF=1) */
function etel_idle_env_off() {
    $v = '';
    if (function_exists('etel_env')) $v = etel_env('IDLE_TIMEOUT_OFF', 'CFG_IDLE_TIMEOUT_OFF', '');
    $v = strtolower(trim((string) $v));
    return ($v === '1' || $v === 'true' || $v === 'on' || $v === 'yes');
}

/* array(role => minutes) with the owner's saved values over the defaults. */
function etel_idle_settings($pdo) {
    $out = etel_idle_defaults();
    try {
        $q = $pdo->query("SELECT rule_key, rule_value FROM pricing_rules WHERE rule_key LIKE 'idle_minutes_%'");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $role = substr($r['rule_key'], strlen('idle_minutes_'));
            if (!isset($out[$role]) || !is_numeric($r['rule_value'])) continue;
            $m = intval($r['rule_value']);
            if ($m < 0) $m = 0;
            if ($m > 0 && $m < 5) $m = 5;      /* never less than 5 minutes: the warning needs time */
            if ($m > 1440) $m = 1440;
            $out[$role] = $m;
        }
    } catch (Exception $e) { /* no table yet: defaults */ }
    return $out;
}

/* The setting key a user's role maps to. */
function etel_idle_role_key($role) {
    $role = (string) $role;
    $d = etel_idle_defaults();
    return isset($d[$role]) ? $role : 'other';
}

/* Minutes allowed without activity for this role; 0 = never. */
function etel_idle_limit($pdo, $role) {
    if (etel_idle_env_off()) return 0;
    $s = etel_idle_settings($pdo);
    $k = etel_idle_role_key($role);
    return isset($s[$k]) ? intval($s[$k]) : 30;
}

/* Adds the column the first time it is needed. */
function etel_idle_ensure_column($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        require_once __DIR__ . '/auth_sessions.php';
        require_once __DIR__ . '/schema_helpers.php';
        try { etel_sess_create_table($pdo); } catch (Exception $e) {}
        rides_add_missing_cols($pdo, 'auth_sessions', array('last_active' => '`last_active` DATETIME NULL DEFAULT NULL'));
    } catch (Exception $e) { /* fail open */ }
}

/* true when the work is happening without taps (a courier who is online or has a job, a driver who is online). */
function etel_idle_exempt($pdo, $uid, $role) {
    try {
        if ($role === 'courier') {
            $q = $pdo->prepare("SELECT status FROM couriers WHERE user_id = ? LIMIT 1");
            $q->execute(array(intval($uid)));
            $st = strtolower((string) $q->fetchColumn());
            if ($st === 'online' || $st === 'busy' || $st === 'on_job') return true;
            $j = $pdo->prepare("SELECT 1 FROM orders o JOIN couriers c ON c.id = o.courier_id WHERE c.user_id = ? AND o.status IN ('assigned','picked_up','in_transit') LIMIT 1");
            $j->execute(array(intval($uid)));
            return $j->fetchColumn() ? true : false;
        }
        if ($role === 'driver') {
            $q = $pdo->prepare("SELECT online FROM ride_drivers WHERE user_id = ? LIMIT 1");
            $q->execute(array(intval($uid)));
            return intval($q->fetchColumn()) === 1;
        }
    } catch (Exception $e) { return true; }   /* cannot tell: do not sign out someone who may be working */
    return false;
}

/* The session row of this token: array(id, last_active) or null. */
function etel_idle_row($pdo, $token) {
    require_once __DIR__ . '/auth_sessions.php';
    $fp = etel_sess_fingerprint($token);
    if ($fp === null) return null;
    /* idle_sec is worked out by the database itself (the same clock that wrote last_active), so a different PHP time zone cannot shift it */
    $q = $pdo->prepare('SELECT id, last_active, TIMESTAMPDIFF(SECOND, last_active, NOW()) AS idle_sec FROM auth_sessions WHERE token_hash = ?');
    $q->execute(array($fp[0]));
    $r = $q->fetch(PDO::FETCH_ASSOC);
    return $r ? $r : null;
}

/* Called by get_auth_user for every authenticated request. true = the session ended because the person did not use the app. */
function etel_idle_expired($pdo, $uid, $role, $token) {
    try {
        $limit = etel_idle_limit($pdo, $role);
        if ($limit <= 0) return false;
        etel_idle_ensure_column($pdo);
        $r = etel_idle_row($pdo, $token);
        if (!$r || empty($r['last_active'])) return false;                       /* never reported activity: an older app, never expired */
        if (intval($r['idle_sec']) <= $limit * 60) return false;                  /* still inside the limit */
        if (etel_idle_exempt($pdo, $uid, $role)) return false;
        return true;
    } catch (Exception $e) { return false; }
}

/* session_ping: the app says the person just used it ($active) or only asks for the numbers. Returns array(idle_minutes, exempt, seconds_left). */
function etel_idle_ping($pdo, $uid, $role, $token, $active) {
    $limit = etel_idle_limit($pdo, $role);
    $exempt = etel_idle_exempt($pdo, $uid, $role);
    $left = null;
    try {
        etel_idle_ensure_column($pdo);
        require_once __DIR__ . '/auth_sessions.php';
        $fp = etel_sess_fingerprint($token);
        if ($fp !== null) {
            if ($active) {
                $pdo->prepare('UPDATE auth_sessions SET last_active = NOW() WHERE token_hash = ?')->execute(array($fp[0]));
                $left = $limit > 0 ? $limit * 60 : null;
            } else {
                $r = etel_idle_row($pdo, $token);
                if ($r && !empty($r['last_active']) && $limit > 0) $left = max(0, $limit * 60 - intval($r['idle_sec']));
            }
        }
    } catch (Exception $e) { /* fail open */ }
    return array('idle_minutes' => $limit, 'exempt' => $exempt, 'seconds_left' => $left);
}

}
