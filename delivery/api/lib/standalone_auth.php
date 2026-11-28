<?php
/* ETEL Delivery - the sign-in checks for API files that do NOT go through boot.php.

   updater-api.php, upload.php, admin-branding.php, documents_upload.php, documents_download.php and
   backfill_open_trip_fares.php read the login token themselves. They checked the signature, the age and
   token_version, but NOT the rules boot.php's get_auth_user() / require_auth() add:
     - a disabled account (users.active = 0),
     - a device the person signed out (auth_sessions.revoked_at),
     - the idle sign-out (lib/idle_session.php),
     - admin two-step sign-in (lib/admin_2fa_policy.php), for admin accounts.
   So a signed-out or stolen admin token, or an admin without two-step, could still use them. The Updater
   can write PHP files on the server. Each of these files now calls etel_sa_check() after its own token check.

   Every rule fails open on a database error, exactly like boot.php, so nobody is locked out by a missing table.
   PHP 5-style, ASCII only. */

/* The shared libs below refuse to run unless the front door defined APP_VER (their direct-access guard). These files ARE
   front doors (an API entry point each), but boot.php is not loaded, so define it here. */
if (!defined('APP_VER')) define('APP_VER', 'standalone');

if (!function_exists('etel_sa_check')) {

/* Constant-time compare of two signatures (the files used ===). */
function etel_sa_sig_ok($expected, $given) {
    $expected = (string) $expected; $given = (string) $given;
    if (function_exists('hash_equals')) return hash_equals($expected, $given);
    if (strlen($expected) !== strlen($given)) return false;
    $r = 0;
    for ($i = 0; $i < strlen($expected); $i++) $r |= ord($expected[$i]) ^ ord($given[$i]);
    return $r === 0;
}

function etel_sa_has_col($pdo, $table, $col) {
    try {
        $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $s->execute(array($table, $col));
        return intval($s->fetchColumn()) > 0;
    } catch (Exception $e) { return false; }
}

/* Same rule as boot.php user_capabilities(): accounts not managed by EtelGroup are unrestricted. */
function etel_sa_has_capability($pdo, $uid, $capability) {
    if ($capability === null || $capability === '') return true;
    if (!etel_sa_has_col($pdo, 'users', 'is_etelgroup_managed')) return true;
    try {
        $s = $pdo->prepare('SELECT is_etelgroup_managed, staff_role FROM users WHERE id = ? LIMIT 1');
        $s->execute(array(intval($uid)));
        $u = $s->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return true; }
    if (!$u || empty($u['is_etelgroup_managed'])) return true;
    if (empty($u['staff_role'])) return false;
    $caps = array('orders.view', 'rides.view');
    try {
        $s = $pdo->prepare('SELECT capabilities FROM role_permissions WHERE LOWER(role_name) = LOWER(?) LIMIT 1');
        $s->execute(array($u['staff_role']));
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row) { $c = json_decode($row['capabilities'], true); $caps = is_array($c) ? $c : array(); }
    } catch (Exception $e) { /* keep the default */ }
    return in_array('*', $caps, true) || in_array($capability, $caps, true);
}

function etel_sa_2fa_enabled($pdo, $uid) {
    try {
        $s = $pdo->prepare('SELECT enabled FROM admin_2fa WHERE user_id = ? LIMIT 1');
        $s->execute(array(intval($uid)));
        $v = $s->fetchColumn();
        return $v !== false && intval($v) === 1;
    } catch (Exception $e) { return false; }
}

/* Returns null when the request may go on, otherwise array(http_code, message) for the caller's own fail function.
   $user needs id and role. $capability (optional) is the owner permission the file needs, e.g. 'system.backup'. */
function etel_sa_check($pdo, $user, $token, $capability = null) {
    if (!is_array($user) || empty($user['id'])) return array(401, 'Unauthorised - please sign in again');
    $uid = intval($user['id']);
    $role = isset($user['role']) ? (string) $user['role'] : '';

    if (etel_sa_has_col($pdo, 'users', 'active')) {
        try {
            $s = $pdo->prepare('SELECT active FROM users WHERE id = ? LIMIT 1');
            $s->execute(array($uid));
            $a = $s->fetchColumn();
            if ($a !== false && $a !== null && intval($a) === 0) return array(401, 'This account is disabled. Please contact support.');
        } catch (Exception $e) { /* fail open */ }
    }

    try {
        require_once __DIR__ . '/auth_sessions.php';
        if (!etel_sess_touch($pdo, $uid, $token)) return array(401, 'This device was signed out. Please sign in again.');
    } catch (Exception $e) { /* fail open */ }

    try {
        require_once __DIR__ . '/idle_session.php';
        if (etel_idle_expired($pdo, $uid, $role, $token)) return array(401, 'You were signed out because the app was not used for a while. Please sign in again.');
    } catch (Exception $e) { /* fail open */ }

    if ($role === 'admin') {
        $need2fa = false;
        try {
            require_once __DIR__ . '/admin_2fa_policy.php';
            $need2fa = etel_admin_2fa_required($pdo);
        } catch (Exception $e) { $need2fa = false; }
        if ($need2fa && !etel_sa_2fa_enabled($pdo, $uid) && !etel_sess_mfa_ok($pdo, $uid, $token)) {
            return array(403, 'Two-step sign-in is required for admins and staff. Turn on "Admin two-step" in the menu, or sign in with EtelID and its two-step code.');
        }
        if (!etel_sa_has_capability($pdo, $uid, $capability)) {
            return array(403, "You don't have permission to do this (requires: " . $capability . ')');
        }
    }
    return null;
}

} // end function_exists guard
