<?php
/* ETEL Delivery - two-step sign-in is REQUIRED for every admin and staff account (launch-readiness phase 3).
 *
 * Staff accounts are role 'admin' (with a staff_role), so "who needs it" = every account that can use admin_* actions.
 *
 * Is it required?  First answer wins:
 *   1. api/.env  ADMIN_2FA_REQUIRED=0   the owner's emergency switch (lost phone AND lost backup codes AND no other admin).
 *      Edit the file in cPanel File Manager, sign in, fix the account, then delete the line. The health page shows a warning while it is set.
 *      ADMIN_2FA_REQUIRED=1 forces it on.
 *   2. pricing_rules 'require_admin_2fa' (Admin > Rides > System health): 1 = required, 0 = the owner switched it off.
 *   3. nothing saved = REQUIRED (the safe default for launch).
 * An admin without two-step can still open the Two-step sign-in set-up, the health page and sign in; every other admin_* action
 * answers 403 code 2fa_setup_required until it is on (boot.php etel_admin_2fa_enforce).
 * Ways in when a code is lost (see delivery/docs/TWO_STEP_RECOVERY.md): backup codes, another admin resets the account,
 * phpMyAdmin one-liner, or the .env switch above.
 * PHP 5 style, ASCII only.
 */
if (!function_exists('etel_admin_2fa_required')) {

/* '0', '1' or null (not set). */
function etel_admin_2fa_env_override() {
    $v = '';
    if (function_exists('etel_env')) {
        $v = etel_env('ADMIN_2FA_REQUIRED', 'CFG_ADMIN_2FA_REQUIRED', '');
    } else {
        if (!function_exists('etel_parse_env_file')) require_once __DIR__ . '/../env_loader.php';
        $env = etel_parse_env_file(dirname(__DIR__) . '/.env');
        $v = isset($env['ADMIN_2FA_REQUIRED']) ? $env['ADMIN_2FA_REQUIRED'] : (getenv('ADMIN_2FA_REQUIRED') !== false ? getenv('ADMIN_2FA_REQUIRED') : '');
    }
    $v = strtolower(trim((string) $v));
    if ($v === '0' || $v === 'false' || $v === 'off' || $v === 'no') return '0';
    if ($v === '1' || $v === 'true' || $v === 'on' || $v === 'yes') return '1';
    return null;
}

/* array('required' => bool, 'source' => 'env'|'setting'|'default'). */
function etel_admin_2fa_policy($pdo) {
    $o = etel_admin_2fa_env_override();
    if ($o !== null) return array('required' => $o === '1', 'source' => 'env');
    try {
        $s = $pdo->prepare("SELECT rule_value FROM pricing_rules WHERE rule_key='require_admin_2fa' LIMIT 1");
        $s->execute();
        $v = $s->fetchColumn();
        if ($v !== false && $v !== null && $v !== '') return array('required' => intval($v) === 1, 'source' => 'setting');
    } catch (Exception $e) { /* no table yet: default */ }
    return array('required' => true, 'source' => 'default');
}

function etel_admin_2fa_required($pdo) {
    $p = etel_admin_2fa_policy($pdo);
    return $p['required'];
}

/* Admin and staff accounts that have no two-step sign-in yet. Array of array(id, name, staff_role). Empty when all are covered. */
function etel_admin_2fa_missing($pdo) {
    $out = array();
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
        $sr = in_array('staff_role', $cols, true) ? ', u.staff_role' : ", '' AS staff_role";
        $q = $pdo->query("SELECT u.id, u.name" . $sr . " FROM users u WHERE u.role = 'admin'"
            . (in_array('active', $cols, true) ? ' AND (u.active IS NULL OR u.active = 1)' : '')
            . " AND NOT EXISTS (SELECT 1 FROM admin_2fa a WHERE a.user_id = u.id AND a.enabled = 1) ORDER BY u.id ASC LIMIT 50");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = array('id' => intval($r['id']), 'name' => (string) $r['name'], 'staff_role' => (string) $r['staff_role']);
    } catch (Exception $e) { /* table missing: nothing to report */ }
    return $out;
}

} // end function_exists guard
