<?php
/**
 * ETEL Delivery API v3.2.6 + Service Giver Extension
 * Pure PHP/MySQL  cPanel compatible  PHP 5.6+
 */

/*  CRITICAL: Buffer everything, show NO errors  */
if (ob_get_level()) ob_end_clean();
ob_start();
error_reporting(0);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

/* Catch PHP fatals (parse/require errors in controllers, OOM, etc.)
   and return JSON instead of a blank 500 - same pattern as the updater */
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
        while (ob_get_level()) { @ob_end_clean(); }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(array(
            'success' => false,
            'message' => 'Server fatal error',
            'fatal'   => $e['message'],
            'file'    => basename($e['file']) . ':' . $e['line']
        ));
    }
});

/*  LOAD config.php  */
$_cfg_file = __DIR__ . '/config.php';
if (file_exists($_cfg_file)) {
    require_once $_cfg_file;
}

/* Centralized phone/amount validation - see validate.php for why this
   exists instead of each controller rolling its own. */
require_once __DIR__ . '/validate.php';

/*  LOAD .env  bulletproof cPanel reader
   parse_ini_file() silently fails when .env contains non-ASCII
   characters (unicode dashes, box-drawing chars in comments).
   The actual line-by-line parser now lives in env_loader.php so
   diagnose.php and etel-os.php can share it instead of each rolling
   their own (an audit found both were still using parse_ini_file(),
   the exact thing this comment warns against).
   Also works when php-fpm pools block putenv/getenv round-trips.
    */
require_once __DIR__ . '/env_loader.php';
$_etel_env = etel_parse_env_file(__DIR__ . '/.env');

/* Hex-grid spatial indexing (see lib/hexgrid.php) - used by Ride's
   driver/request matching and Delivery's courier/order matching to
   filter by nearby hex cells instead of scanning every online
   driver/courier and computing distance for each one. Pure functions,
   no side effects, so it's loaded unconditionally here rather than
   per-action like some other lib files. */
require_once __DIR__ . '/lib/hexgrid.php';

/* Helper: .env array > getenv > CFG_ constant > default.
   Checks our parsed array first  never relies on putenv/getenv. */
function etel_env($key, $cfg_const, $default = '') {
    global $_etel_env;
    if (isset($_etel_env[$key]) && $_etel_env[$key] !== '') return $_etel_env[$key];
    $g = getenv($key);
    if ($g !== false && $g !== '') return $g;
    if (defined($cfg_const) && constant($cfg_const) !== '') return constant($cfg_const);
    return $default;
}

/*  CONFIG (priority: .env array > getenv > CFG_* > default)  */
if (!defined('DB_HOST'))      define('DB_HOST',      etel_env('DB_HOST',      'CFG_DB_HOST',      'localhost'));
if (!defined('DB_NAME'))      define('DB_NAME',      etel_env('DB_NAME',      'CFG_DB_NAME',      'etel_delivery'));
if (!defined('DB_USER'))      define('DB_USER',      etel_env('DB_USER',      'CFG_DB_USER',      ''));
if (!defined('DB_PASS'))      define('DB_PASS',      etel_env('DB_PASS',      'CFG_DB_PASS',      ''));
if (!defined('TOKEN_SECRET')) define('TOKEN_SECRET', etel_env('TOKEN_SECRET', 'CFG_TOKEN_SECRET', ''));

/*  Guard: refuse to serve without DB password  */
if (!DB_PASS) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(array(
        'success' => false,
        'message' => 'Database not configured. Set credentials in api/config.php (CFG_DB_PASS) or api/.env (DB_PASS).',
        'diag' => array(
            'config_php_present' => file_exists($_cfg_file),
            'env_present'        => file_exists(__DIR__ . '/.env'),
            'cfg_db_pass_set'    => defined('CFG_DB_PASS') && CFG_DB_PASS !== '',
            'env_db_pass_set'    => getenv('DB_PASS') ? true : false,
            'db_name'            => defined('CFG_DB_NAME') ? CFG_DB_NAME : (getenv('DB_NAME') ?: '(none)'),
            'db_user'            => defined('CFG_DB_USER') ? CFG_DB_USER : (getenv('DB_USER') ?: '(none)'),
        )
    ));
    exit;
}
// Was: auto-generate a token secret from hash('sha256', php_uname('n') .
// DB_NAME . DB_USER . 'etel2026') whenever TOKEN_SECRET wasn't set, so the
// app would still "work" without config. Two audits flagged this as a real
// weakness (one rated it high-severity): hostname, DB name, and DB user
// are all things an attacker could plausibly learn (error messages, leaked
// backups, etc.), and 'etel2026' is a fixed string baked into the source -
// so this "secret" was reconstructable, which would let an attacker forge
// valid bearer tokens for any user id. Since bearer tokens gate every
// authenticated action (including admin), a guessable signing key is a
// full auth bypass, not a minor issue - so this now fails closed with the
// same guard pattern as the missing-DB-password check above, instead of
// silently substituting a weak key. TOKEN_SECRET is already set in this
// deployment's api/.env, so this should never actually trigger here.
if (!defined('TOKEN_SECRET_FINAL')) {
    if (!TOKEN_SECRET || TOKEN_SECRET === 'YOUR_GENERATED_64_CHAR_SECRET_HERE') {
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode(array(
            'success' => false,
            'message' => 'Server not configured securely. Set a real, random TOKEN_SECRET (64+ hex characters) in api/.env - e.g. run: php -r "echo bin2hex(random_bytes(32));" and paste the result as TOKEN_SECRET=... in api/.env.',
        ));
        exit;
    }
    define('TOKEN_SECRET_FINAL', TOKEN_SECRET);
}
define('APP_VER',      '3.7.4');
define('SCHEMA_VER',   '1.0');

/*  CORS HEADERS (send before anything else)  */
ob_end_clean();   // flush any buffered junk
header('Content-Type: application/json; charset=utf-8');
/* Allowed origins -- add any additional domains you serve from */
$_allowed_origins = array(
    'https://ethelmarket.com',
    'https://www.ethelmarket.com',
    'http://localhost',
    'http://127.0.0.1',
);
$_origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if (in_array($_origin, $_allowed_origins, true)) {
    header('Access-Control-Allow-Origin: ' . $_origin);
    header('Vary: Origin');
} elseif (!$_origin) {
    header('Access-Control-Allow-Origin: ' . (isset($_SERVER['HTTP_HOST']) ? 'https://' . $_SERVER['HTTP_HOST'] : 'null'));
}
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('X-App-Version: ' . APP_VER);

/* -- Standard hardening headers for a JSON API: this endpoint never
   serves HTML, so there's no reason a browser should try to sniff/frame/
   leak-referrer it. Cheap, safe, and closes off a few generic attack
   classes (MIME-sniffing, clickjacking via iframe, referrer leakage). -- */
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

/* -- LiteSpeed Cache: never cache API responses -- */
header('X-LiteSpeed-Cache-Control: no-cache');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit;
}

/* -- LiteSpeed auto-purge helper -----------------
   Call etel_purge_cache() inside any write action
   to instantly invalidate cached HTML + static files. */
function etel_purge_cache($tags = 'etel-html,etel-static') {
    if (!headers_sent()) {
        header('X-LiteSpeed-Purge: tag=' . $tags);
    }
}
/* Write actions that should trigger a cache purge */
define('ETEL_WRITE_ACTIONS', array(
    'customer_create_order','courier_update_order_status','admin_update_order',
    'admin_create_user','admin_add_item','admin_update_item','admin_delete_item',
    'admin_toggle_item','admin_create_service_giver','admin_verify_service_giver',
    'admin_suspend_service_giver','sg_update_order_status','sg_complete_order',
    'sg_add_item','sg_update_item','sg_delete_item','admin_purge_cache',
    'admin_update_courier',
    'admin_backfill_order_service_giver_ids',
    'courier_update_location',
    'nfc_confirm_pickup','nfc_confirm_delivery',
));

/*  READ INPUT  */
$raw    = file_get_contents('php://input');
$body   = $raw ? @json_decode($raw, true) : array();
if (!$body || !is_array($body)) $body = array();
$action = isset($body['action'])   ? trim($body['action'])
        : (isset($_GET['action'])  ? trim($_GET['action'])  : '');

/* Auto-purge LiteSpeed cache for write actions */
if ($action && in_array($action, ETEL_WRITE_ACTIONS, true)) {
    etel_purge_cache();
}

/*  HELPERS  */
function ok($data = null) {
    echo json_encode(array('success' => true, 'data' => $data, 'v' => APP_VER));
    exit;
}
function fail($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(array('success' => false, 'message' => $msg, 'v' => APP_VER));
    exit;
}
/* Like fail(), but for exceptions: logs the real exception message/trace
   server-side only and sends the client a generic, non-leaking message.
   Raw PDO/DB exception text can contain table names, column names, and
   SQL fragments - none of that belongs in an HTTP response body. */
/* Returns the real column names of $table for the current database,
   cached per-request. Several inserts (new user, new courier, etc.) used
   to "discover" the schema by blindly trying several INSERT variants and
   silently swallowing whichever ones threw a PDOException - that masks
   real errors too (a duplicate-key or connection failure would look
   identical to "column doesn't exist" and just get retried/ignored).
   Checking the schema once up front lets those call sites build a single,
   correct INSERT and let genuine failures surface normally. */
function etel_table_columns($pdo, $table) {
    static $cache = array();
    if (isset($cache[$table])) return $cache[$table];
    try {
        $s = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $s->execute(array($table));
        $cols = $s->fetchAll(PDO::FETCH_COLUMN);
        if (!$cols) $cols = array();
    } catch (Exception $e) {
        error_log('[ETEL] etel_table_columns(' . $table . ') failed: ' . $e->getMessage());
        $cols = array();
    }
    $cache[$table] = $cols;
    return $cols;
}

function fail_ex($e, $context, $code = 500) {
    error_log('[ETEL] ' . $context . ': ' . $e->getMessage());
    http_response_code($code);
    echo json_encode(array('success' => false, 'message' => $context . ' - please try again or contact support', 'v' => APP_VER));
    exit;
}
/* Tokens embed the user's token_version at issue time (see migration 006).
   Bumping token_version server-side (etel_bump_token_version()) makes every
   token issued before that moment fail verification immediately - that's
   the whole revocation mechanism, no session table or per-request DB write
   needed beyond the single UPDATE at bump time. */
function gen_token($user_id, $version = 0) {
    $ts  = time();
    $sig = hash_hmac('sha256', $user_id . ':' . $ts . ':' . $version, TOKEN_SECRET_FINAL);
    return base64_encode($user_id . ':' . $ts . ':' . $version . ':' . $sig);
}
/* Returns array('uid'=>int,'version'=>int) on a structurally/cryptographically
   valid token, or null. This only checks the signature and expiry - it does
   NOT check the version against the database (get_auth_user() does that,
   since it already loads the user row on every authenticated request). */
function verify_token($token) {
    $d = @base64_decode($token);
    if (!$d) return null;
    $p = explode(':', $d);
    if (count($p) < 4) return null; // old 3-part tokens (pre-revocation) fail closed, not open
    $uid = $p[0]; $ts = $p[1]; $version = $p[2]; $sig = $p[3];
    if ((time() - intval($ts)) > 86400 * 7) return null;
    $expected = hash_hmac('sha256', $uid . ':' . $ts . ':' . $version, TOKEN_SECRET_FINAL);
    $match = function_exists('hash_equals') ? hash_equals($expected, $sig) : ($sig === $expected);
    return $match ? array('uid' => intval($uid), 'version' => intval($version)) : null;
}
/* Invalidate every outstanding token for a user immediately. Call this on
   logout, and reuse it from any future password-reset or account-suspend
   flow - it's the single point that actually revokes access, independent
   of how a token was originally issued. */
function etel_bump_token_version($pdo, $user_id) {
    try {
        if (!in_array('token_version', etel_table_columns($pdo, 'users'), true)) return false;
        $pdo->prepare('UPDATE users SET token_version = token_version + 1 WHERE id = ?')
            ->execute(array($user_id));
        return true;
    } catch (Exception $e) {
        error_log('[ETEL] etel_bump_token_version failed for uid=' . $user_id . ': ' . $e->getMessage());
        return false;
    }
}
function get_bearer() {
    $h = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    if (!$h && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $h = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    if (!$h && function_exists('getallheaders')) {
        $hdrs = getallheaders();
        if (isset($hdrs['Authorization'])) $h = $hdrs['Authorization'];
        elseif (isset($hdrs['authorization'])) $h = $hdrs['authorization'];
    }
    /* SSE: EventSource cannot set headers  accept token via GET for sse_* actions.
       Also accept it for *_beacon_ping actions: navigator.sendBeacon (used for a
       best-effort "still here" ping when a tab backgrounds) cannot set custom
       headers either, only a body. Both are narrow, explicitly-named, idempotent
       actions - this does not open the token param up for general use. */
    if (!$h && isset($_GET['token']) && isset($_GET['action']) &&
        (strpos($_GET['action'], 'sse_') === 0 || substr($_GET['action'], -12) === '_beacon_ping')) {
        return trim($_GET['token']);
    }
    return (strpos($h, 'Bearer ') === 0) ? substr($h, 7) : '';
}
function get_auth_user($pdo) {
    $t = get_bearer(); if (!$t) return null;
    $tok = verify_token($t); if (!$tok) return null;
    $cols = etel_table_columns($pdo, 'users');
    $hasVersionCol = in_array('token_version', $cols, true);
    $hasStaffRole  = in_array('staff_role', $cols, true);
    $hasManagedCol = in_array('is_etelgroup_managed', $cols, true);
    $s = $pdo->prepare('SELECT id,name,phone,role' . ($hasVersionCol ? ',token_version' : '') . ($hasStaffRole ? ',staff_role' : '') . ($hasManagedCol ? ',is_etelgroup_managed' : '') . ' FROM users WHERE id=?');
    $s->execute(array($tok['uid']));
    $u = $s->fetch(PDO::FETCH_ASSOC);
    if (!$u) return null;
    // If the migration hasn't run yet, there's nothing to check the version
    // against - fail open on that specific gap rather than locking everyone
    // out, since a missing column is an ops issue, not a compromised token.
    if ($hasVersionCol && intval($u['token_version']) !== $tok['version']) return null;
    return $u;
}
function require_auth($pdo) {
    $u = get_auth_user($pdo);
    if (!$u) fail('Unauthorized  please login again', 401);
    return $u;
}

// Loads the capability list for a user.
// - Not EtelGroup-managed (is_etelgroup_managed=0, the real signal -
//   NOT "staff_role happens to be empty", which can't distinguish a
//   true legacy account from a managed one that hasn't synced staff_role
//   under an older code version) -> unrestricted, unchanged forever.
// - EtelGroup-managed with a current staff_role -> look up that role's
//   permissions (or a safe read-only baseline if the role name has no
//   configured row yet).
// - EtelGroup-managed with NO current staff_role (their EtelGroup role
//   was removed) -> zero capabilities. Access is revoked, not silently
//   left at whatever it was.
function user_capabilities($pdo, $u) {
    $managed = !empty($u['is_etelgroup_managed']);
    if (!$managed) return array('*');
    if (empty($u['staff_role'])) return array();
    try {
        $s = $pdo->prepare('SELECT capabilities FROM role_permissions WHERE LOWER(role_name) = LOWER(?) LIMIT 1');
        $s->execute(array($u['staff_role']));
        $row = $s->fetch();
    } catch (Exception $e) { $row = null; }
    if (!$row) return array('orders.view', 'rides.view');
    $caps = json_decode($row['capabilities'], true);
    return is_array($caps) ? $caps : array();
}
function user_has_capability($pdo, $u, $capability) {
    $caps = user_capabilities($pdo, $u);
    return in_array('*', $caps, true) || in_array($capability, $caps, true);
}

function require_admin($pdo, $capability = null) {
    $u = require_auth($pdo);
    if ($u['role'] !== 'admin') fail('Admin access required', 403);
    if ($capability !== null && !user_has_capability($pdo, $u, $capability)) {
        fail("You don't have permission to do this (requires: $capability)", 403);
    }
    return $u;
}
function require_courier($pdo) {
    $u = require_auth($pdo);
    if ($u['role'] !== 'courier' && $u['role'] !== 'admin') fail('Courier access required', 403);
    return $u;
}
function get_or_create_courier_profile($pdo, $user_id) {
    try {
        $s = $pdo->prepare('SELECT id, zone, home_zone, status FROM couriers WHERE user_id=?');
        $s->execute(array($user_id));
        $courier = $s->fetch();
    } catch (Exception $e) { $courier = null; }
    if (!$courier) {
        $code = 'C-' . str_pad($user_id, 5, '0', STR_PAD_LEFT);
        try {
            $pdo->prepare("INSERT IGNORE INTO couriers (user_id,courier_code,status,rating,total_deliveries,active,created_at) VALUES (?,?,'online',0.00,0,1,NOW())")
                ->execute(array($user_id, $code));
            $s = $pdo->prepare('SELECT id, zone, home_zone, status FROM couriers WHERE user_id=?');
            $s->execute(array($user_id));
            $courier = $s->fetch();
        } catch (Exception $e) {
            error_log('[ETEL] get_or_create_courier_profile failed for uid=' . $user_id . ': ' . $e->getMessage());
        }
    }
    return $courier;
}

/*  DB  */
function db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            array(
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            )
        );
    } catch (PDOException $e) {
        // Log real error to server log, never expose to browser
        error_log('[ETEL] DB connect failed: ' . $e->getMessage());
        fail('Database connection failed. Please check api/.env credentials and ensure the database exists in cPanel.', 503);
    }
    return $pdo;
}

/* 
   RATE LIMITING (login brute-force protection)
 */
function rate_limit_check($pdo, $identifier, $max_attempts = 5, $window_seconds = 600, $lockout_seconds = 900) {
    /* Silently skip if login_attempts table does not exist yet */
    try {
        $ip  = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        $key = substr($identifier . '|' . $ip, 0, 120);
        $now = time();
        $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')
            ->execute(array(date('Y-m-d H:i:s', $now - $lockout_seconds)));
        $s = $pdo->prepare(
            'SELECT COUNT(*) as cnt, MAX(attempted_at) as last_attempt
             FROM login_attempts WHERE identifier = ? AND attempted_at > ?'
        );
        $s->execute(array($key, date('Y-m-d H:i:s', $now - $window_seconds)));
        $row = $s->fetch();
        if ((int)$row['cnt'] >= $max_attempts) {
            $last = strtotime($row['last_attempt']);
            $wait = max(0, ($last + $lockout_seconds) - $now);
            $mins = ceil($wait / 60);
            fail("Too many failed attempts. Try again in {$mins} minute(s).", 429);
        }
    } catch (Exception $e) { /* table not yet created -- allow through */ }
}
function rate_limit_fail($pdo, $identifier) {
    try {
        $ip  = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        $key = substr($identifier . '|' . $ip, 0, 120);
        $pdo->prepare('INSERT INTO login_attempts (identifier, attempted_at) VALUES (?, NOW())')
            ->execute(array($key));
    } catch (Exception $e) { error_log('[ETEL] rate_limit_fail failed: ' . $e->getMessage()); }
}
function rate_limit_clear($pdo, $identifier) {
    try {
        $ip  = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        $key = substr($identifier . '|' . $ip, 0, 120);
        $pdo->prepare('DELETE FROM login_attempts WHERE identifier = ?')
            ->execute(array($key));
    } catch (Exception $e) { error_log('[ETEL] rate_limit_clear failed: ' . $e->getMessage()); }
}

/* 
   ROUTES
 */
// Marks a referral reward as paid once the REFERRED user (not the
// referrer) completes their first order or ride. Safe to call after
// every completion, not just the first: the WHERE clause only matches
// while status is still 'pending', so a reward already paid is a
// no-op on every subsequent call - no separate "is this their first
// completion" counting needed, since customer_referral_rewards has a
// UNIQUE constraint on referred_user_id (one row per referred person).
function etel_maybe_pay_referral_reward($pdo, $completed_user_id) {
    try {
        $pdo->prepare("UPDATE customer_referral_rewards SET status='paid', paid_at=NOW() WHERE referred_user_id=? AND status='pending'")
            ->execute(array($completed_user_id));
    } catch (Exception $e) {
        // customer_referral_rewards / paid_at may not exist yet on an
        // unmigrated install - never let this break order/ride completion.
    }
}

switch ($action) {
    case 'admin_purge_cache': {
        $pdo = db(); require_admin($pdo);
        etel_purge_cache('*');
        ok(array('purged' => true, 'ts' => time()));
        break;
    }
    case 'get_my_notifications': {
        // Polled every 60s by courier.js (and potentially other apps)
        // but no notifications system exists anywhere in this codebase
        // yet - this was a real 404, not a whitelist gap. Rather than
        // fabricate fake notification content, this returns honest
        // zero data so the poll succeeds cleanly. Replace with a real
        // notifications table/query when an actual notifications
        // feature gets built.
        require_auth(db());
        ok(array('unread' => 0, 'notifications' => array()));
        break;
    }
    case 'ping': case 'db_ping': {
        $dbOk = false; $dbMsg = '';
        try { db(); $dbOk = true; $dbMsg = 'connected'; }
        catch (Exception $e) { error_log('[ETEL] db_ping failed: ' . $e->getMessage()); $dbMsg = 'error'; }
        ok(array('db' => $dbOk ? 'connected' : 'error', 'db_message' => $dbMsg,
                 'version' => APP_VER, 'php' => PHP_VERSION, 'time' => date('Y-m-d H:i:s')));
        break;
    }
    case 'version_check':
        ok(array('api_version' => 'v' . APP_VER, 'schema_version' => SCHEMA_VER));
        break;
    case 'logout': {
        // Actually revoke the token server-side (bump token_version) instead
        // of just telling the client to forget it - a stolen token used to
        // stay valid up to 7 days after "logout" regardless.
        $pdo = db();
        $u = get_auth_user($pdo);
        if ($u) etel_bump_token_version($pdo, $u['id']);
        ok(array('logged_out' => true));
        break;
    }
    case 'get_config': {
        $key = etel_env('GOOGLE_MAPS_API_KEY', 'CFG_MAPS_KEY', '');
        ok(array('google_maps_api_key' => $key));
        break;
    }
    case 'get_patch_info': {
        // Was a permanent stub - always reported patches_available:false
        // regardless of anything, which is why core.js's silentPatchCheck()
        // (fully built: polls this, compares versions, would show
        // #update-banner) could never actually fire - there was no real
        // data behind it, AND no page had an #update-banner element in
        // its HTML to show anyway. Now backed by the real canary/release
        // system in lib/app_releases.php: resolves the version THIS
        // specific user should be on for the named app (canary testers
        // get the canary build if one is set, everyone else gets
        // stable), and reports it against whatever version the client
        // says it currently has loaded.
        require_once __DIR__ . '/lib/app_releases.php';
        $pdo = db();
        $u = require_auth($pdo);
        $app = isset($_GET['app']) ? trim($_GET['app']) : (isset($body['app']) ? trim($body['app']) : '');
        if (!in_array($app, app_releases_valid_app_names(), true)) {
            // Unknown/missing app name - fail soft rather than hard, since
            // this is called from a generic page-load hook and a bad
            // response here should never block the page. Older core.js
            // callers that don't send `app` at all also land here.
            ok(array('version' => APP_VER, 'schema_version' => SCHEMA_VER,
                     'patches_available' => false, 'last_check' => date('Y-m-d H:i:s')));
            break;
        }
        $resolved = app_releases_resolve_version_for_user($pdo, $app, $u['id']);
        $currentVersion = isset($_GET['current_version']) ? trim($_GET['current_version']) : (isset($body['current_version']) ? trim($body['current_version']) : '');
        ok(array(
            'app' => $app,
            'version' => $resolved['version'],
            'is_canary_tester' => $resolved['is_canary_tester'],
            'on_canary_build' => $resolved['on_canary_build'],
            'patches_available' => ($currentVersion !== '' && $currentVersion !== $resolved['version']),
            'schema_version' => SCHEMA_VER,
            'last_check' => date('Y-m-d H:i:s'),
        ));
        break;
    }
    case 'get_profile': case 'my_profile': {
        $pdo = db();
        $u = require_auth($pdo);
        ok(array('user' => $u));
        break;
    }
    case 'get_zones': {
        // Was a hardcoded array here - now reads from delivery_zones
        // (migration 007) so an admin can add/rename/deactivate zones
        // from the panel without a code deploy. Falls back to the old
        // hardcoded list if the table doesn't exist yet or is empty, so
        // this action never returns nothing.
        $zones = array();
        try {
            $pdo = db();
            $s = $pdo->query("SELECT name FROM delivery_zones WHERE active=1 ORDER BY sort_order ASC, name ASC");
            $zones = array_column($s->fetchAll(PDO::FETCH_ASSOC), 'name');
        } catch (Exception $e) { /* table may not exist yet - fall back below */ }
        if (!$zones) {
            $zones = array(
                'Mehal Ketema','Tabor','Addis Ketema','Haik Dar','Tulu Dimtu',
                'Misrak','Referral','Hawela Teklehaimanot','Dore Bafana',
                'Stadium','Liqe Mender','University'
            );
        }
        ok(array('zones' => $zones));
        break;
    }
    case 'get_delivery_tiers': {
        // Public (no auth) - powers the customer checkout tier picker
        // (moto / car / express cargo). Same DB-first, hardcoded-fallback
        // pattern as get_zones above.
        $tiers = array();
        try {
            $pdo = db();
            $s = $pdo->query("SELECT tier_key, label, icon, multiplier, eta_delta_min FROM delivery_tiers WHERE active=1 ORDER BY sort_order ASC, label ASC");
            $tiers = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { /* table may not exist yet - fall back below */ }
        if (!$tiers) {
            require_once __DIR__ . '/config-etb.php';
            global $DELIVERY_TIERS;
            foreach ($DELIVERY_TIERS as $key => $t) {
                $tiers[] = array(
                    'tier_key' => $key, 'label' => $t['label'], 'icon' => $t['icon'],
                    'multiplier' => $t['multiplier'], 'eta_delta_min' => $t['eta_delta']
                );
            }
        }
        ok(array('tiers' => $tiers));
        break;
    }
    case 'get_bank_accounts': {
        // Public (no auth) - powers the customer checkout bank-transfer
        // account display. No hardcoded fallback here on purpose: if the
        // table is missing/empty, the client shows nothing rather than a
        // stale/fake account number nobody should be wiring money to.
        $accounts = array();
        try {
            $pdo = db();
            $s = $pdo->query("SELECT bank_key, display_name, account_name, account_number FROM bank_accounts WHERE active=1");
            $accounts = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { /* table may not exist yet - return empty */ }
        ok(array('accounts' => $accounts));
        break;
    }
    case 'register': case 'login': case 'sg_login': case 'etelid_login': case 'admin_etelid_login': case 'change_password':
        require __DIR__ . '/controllers/AuthController.php';
        break;
    case 'admin_get_service_givers': case 'admin_get_service_givers_list': {
        $pdo = db(); require_admin($pdo);
        $status = isset($_GET['status']) ? trim($_GET['status']) : '';
        $limit  = isset($_GET['limit'])  ? intval($_GET['limit']) : 200;
        $where = 'WHERE 1=1'; $params = array();
        if ($status && in_array($status, array('pending','verified','suspended'))) { $where .= ' AND sg.status = ?'; $params[] = $status; }
        try {
            $s = $pdo->prepare("SELECT sg.id, sg.service_giver_code, sg.business_name, sg.service_type, sg.vehicle_type, sg.zone, sg.address, sg.status, sg.is_online, sg.average_rating, sg.completed_deliveries, sg.total_earnings, sg.created_at, u.name, u.phone FROM service_givers sg JOIN users u ON sg.user_id=u.id $where ORDER BY sg.created_at DESC LIMIT $limit");
            $s->execute($params);
            $sgs = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch(Exception $e) { $sgs = array(); }
        ok(array('service_givers' => $sgs, 'count' => count($sgs)));
        break;
    }
    case 'admin_get_users': {
        $pdo = db(); require_admin($pdo);
        $role  = isset($_GET['role'])  ? trim($_GET['role'])  : '';
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 200;
        $where = 'WHERE 1=1'; $params = array();
        if ($role && in_array($role, array('customer','courier','admin','service_giver'))) { $where .= ' AND role = ?'; $params[] = $role; }
        try { $s = $pdo->prepare("SELECT id,name,phone,role,active,created_at FROM users $where ORDER BY created_at DESC LIMIT $limit"); $s->execute($params); $users = $s->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e) { $users = array(); }
        ok(array('users' => $users, 'count' => count($users)));
        break;
    }
    case 'admin_get_couriers': {
        $pdo = db(); require_admin($pdo);
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;
        try { $s = $pdo->prepare("SELECT c.id,c.courier_code,c.vehicle_type,c.plate_number,c.home_zone,c.zone,c.status,c.rating,c.total_deliveries,c.active,c.created_at,u.name,u.phone FROM couriers c JOIN users u ON c.user_id=u.id ORDER BY c.created_at DESC LIMIT $limit"); $s->execute(array()); $couriers = $s->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e) { $couriers = array(); }
        ok(array('couriers' => $couriers, 'count' => count($couriers)));
        break;
    }
    case 'admin_update_courier': {
        // Direct admin control over a courier's vehicle type, zone,
        // plate number, and active/suspended status - the courier
        // never sets these themselves once an admin has assigned them.
        // Accepts both POST body and GET query params (defensive
        // fallback matching courier_get_kpi_summary's pattern) - this
        // is a write action and should normally be POSTed, but a
        // stray isGet=true frontend call shouldn't hard-fail with a
        // confusing "required" error if the value was actually sent
        // as a query param instead.
        $pdo = db(); require_admin($pdo);
        $courierId = isset($body['courier_id']) ? intval($body['courier_id']) : (isset($_GET['courier_id']) ? intval($_GET['courier_id']) : 0);
        if ($courierId <= 0) fail('courier_id is required', 400);
        $fields = array(); $params = array();
        $vtRaw = isset($body['vehicle_type']) ? $body['vehicle_type'] : (isset($_GET['vehicle_type']) ? $_GET['vehicle_type'] : null);
        if ($vtRaw !== null) {
            if (!in_array($vtRaw, array('motorcycle', 'bicycle', 'car', 'on_foot'), true)) fail('Invalid vehicle_type', 400);
            $fields[] = 'vehicle_type=?'; $params[] = $vtRaw;
        }
        $plateRaw = isset($body['plate_number']) ? $body['plate_number'] : (isset($_GET['plate_number']) ? $_GET['plate_number'] : null);
        if ($plateRaw !== null) { $fields[] = 'plate_number=?'; $params[] = trim($plateRaw); }
        $zoneRaw = isset($body['zone']) ? $body['zone'] : (isset($_GET['zone']) ? $_GET['zone'] : null);
        if ($zoneRaw !== null) { $fields[] = 'zone=?'; $params[] = trim($zoneRaw); }
        $homeZoneRaw = isset($body['home_zone']) ? $body['home_zone'] : (isset($_GET['home_zone']) ? $_GET['home_zone'] : null);
        if ($homeZoneRaw !== null) { $fields[] = 'home_zone=?'; $params[] = trim($homeZoneRaw); }
        $activeRaw = isset($body['active']) ? $body['active'] : (isset($_GET['active']) ? $_GET['active'] : null);
        if ($activeRaw !== null) { $fields[] = 'active=?'; $params[] = $activeRaw ? 1 : 0; }
        if (empty($fields)) fail('Nothing to update', 400);
        $params[] = $courierId;
        try {
            $pdo->prepare('UPDATE couriers SET ' . implode(', ', $fields) . ' WHERE id=?')->execute($params);
            ok(array('message' => 'Courier updated'));
        } catch (Exception $e) {
            fail_ex($e, 'Could not update courier', 500);
        }
        break;
    }
    case 'admin_get_live_couriers': {
        // Mirrors admin_get_live_drivers' exact shape/pattern for the
        // Couriers Management live map - couriers already have real
        // last_lat/last_lng/last_seen columns, just never had a
        // dedicated admin endpoint exposing them for map plotting.
        $pdo = db(); require_admin($pdo);
        try {
            $s = $pdo->query("SELECT c.id, c.courier_code, c.vehicle_type, c.zone, c.status, c.last_lat, c.last_lng, c.last_seen, u.name
                FROM couriers c JOIN users u ON u.id = c.user_id
                WHERE c.status IN ('online','busy') AND c.last_lat IS NOT NULL AND c.last_lng IS NOT NULL");
            $couriers = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            fail_ex($e, 'Could not load live couriers', 500);
        }
        ok(array('couriers' => $couriers));
        break;
    }
    case 'admin_get_orders':
    case 'get_orders': {
        $pdo = db(); require_admin($pdo);
        $status = isset($_GET['status']) ? trim($_GET['status']) : '';
        $wm = 'WHERE 1=1'; $ws = 'WHERE 1=1'; $params = array();
        if ($status && in_array($status, array('pending','assigned','picked_up','in_transit','delivered','cancelled'))) { $wm .= ' AND o.status=?'; $ws .= ' AND status=?'; $params[] = $status; }
        try {
            $s = $pdo->prepare("SELECT o.id,o.order_code,o.from_zone,o.to_zone,o.package_type,o.recipient_name,o.recipient_phone,o.fare_total,o.status,o.placed_at,o.delivered_at,o.notes,
                (SELECT oc.status FROM order_confirmations oc WHERE oc.order_id=o.id AND oc.type='pickup' ORDER BY oc.id DESC LIMIT 1) AS pickup_conf,
                (SELECT oc.status FROM order_confirmations oc WHERE oc.order_id=o.id AND oc.type='delivery' ORDER BY oc.id DESC LIMIT 1) AS delivery_conf,
                (SELECT oc.responded_at FROM order_confirmations oc WHERE oc.order_id=o.id AND oc.type='pickup' ORDER BY oc.id DESC LIMIT 1) AS pickup_conf_at,
                (SELECT oc.responded_at FROM order_confirmations oc WHERE oc.order_id=o.id AND oc.type='delivery' ORDER BY oc.id DESC LIMIT 1) AS delivery_conf_at
                FROM orders o $wm ORDER BY o.placed_at DESC LIMIT 200");
            $s->execute($params); $orders = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch(Exception $e) {
            try { $s = $pdo->prepare("SELECT id,order_code,from_zone,to_zone,package_type,recipient_name,recipient_phone,fare_total,status,placed_at,delivered_at,notes,NULL AS pickup_conf,NULL AS delivery_conf,NULL AS pickup_conf_at,NULL AS delivery_conf_at FROM orders $ws ORDER BY placed_at DESC LIMIT 200"); $s->execute($params); $orders = $s->fetchAll(PDO::FETCH_ASSOC); }
            catch(Exception $e2) { $orders = array(); }
        }
        ok(array('orders' => $orders, 'count' => count($orders)));
        break;
    }
    case 'admin_dashboard_stats': {
        $pdo = db(); require_admin($pdo);
        $stats = array('orders'=>0,'pending'=>0,'delivered'=>0,'users'=>0,'couriers'=>0,'revenue'=>0.0,'service_givers'=>0);
        try {
            $stats['orders']    = intval($pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
            $stats['pending']   = intval($pdo->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn());
            $stats['delivered'] = intval($pdo->query("SELECT COUNT(*) FROM orders WHERE status='delivered'")->fetchColumn());
            $stats['revenue']   = round(floatval($pdo->query("SELECT COALESCE(SUM(fare_total),0) FROM orders WHERE status='delivered'")->fetchColumn()),2);
            $stats['users']     = intval($pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
            $stats['couriers']  = intval($pdo->query('SELECT COUNT(*) FROM couriers')->fetchColumn());
            try { $stats['service_givers'] = intval($pdo->query('SELECT COUNT(*) FROM service_givers')->fetchColumn()); } catch(Exception $e2){}
        } catch(Exception $e){ error_log('[ETEL] admin_dashboard_stats query failed: ' . $e->getMessage()); }
        ok($stats);
        break;
    }
    case 'admin_verify_service_giver': {
        $pdo = db(); require_admin($pdo);
        $sg_id = isset($body['id']) ? intval($body['id']) : 0;
        if (!$sg_id) fail('Service giver ID required', 400);
        try { $u = $pdo->prepare('UPDATE service_givers SET status="verified" WHERE id=?'); $u->execute(array($sg_id)); if ($u->rowCount()===0) fail('Service giver not found',404); ok(array('message'=>'Verified','id'=>$sg_id)); } catch(Exception $e){ fail_ex($e, 'Update failed', 500); }
        break;
    }
    case 'admin_suspend_service_giver': {
        $pdo = db(); require_admin($pdo);
        $sg_id = isset($body['id']) ? intval($body['id']) : 0;
        if (!$sg_id) fail('Service giver ID required', 400);
        try { $u = $pdo->prepare('UPDATE service_givers SET status="suspended",is_online=0 WHERE id=?'); $u->execute(array($sg_id)); if ($u->rowCount()===0) fail('Not found',404); ok(array('message'=>'Suspended','id'=>$sg_id)); } catch(Exception $e){ fail_ex($e, 'Operation failed', 500); }
        break;
    }
    case 'admin_create_service_giver': {
        $pdo = db(); require_admin($pdo);
        $name = isset($body['name']) ? trim($body['name']) : '';
        $phone = isset($body['phone']) ? preg_replace('/[^0-9+]/','',trim($body['phone'])) : '';
        $pw = isset($body['password']) ? $body['password'] : '';
        $business_name = isset($body['business_name']) ? trim($body['business_name']) : '';
        $service_type = isset($body['service_type']) ? trim($body['service_type']) : 'retail';
        $vehicle_type = isset($body['vehicle_type']) ? trim($body['vehicle_type']) : 'on_foot';
        $zone = isset($body['zone']) ? trim($body['zone']) : 'Mehal Ketema';
        $id_number = isset($body['identification_number']) ? trim($body['identification_number']) : '';
        $address = isset($body['address']) ? trim($body['address']) : '';
        $lat = (isset($body['lat']) && $body['lat']!=='') ? floatval($body['lat']) : null;
        $lng = (isset($body['lng']) && $body['lng']!=='') ? floatval($body['lng']) : null;
        if (!$name) fail('Name is required',400);
        if (!$phone) fail('Phone is required',400);
        if (!$pw || strlen($pw)<8) fail('Password must be at least 8 characters',400);
        $chk = $pdo->prepare('SELECT id FROM users WHERE phone=?'); $chk->execute(array($phone));
        if ($chk->fetch()) fail('Phone number already registered',409);
        try { $pdo->query('SELECT lat,lng FROM service_givers LIMIT 1'); } catch(Exception $e) { try { $pdo->exec('ALTER TABLE service_givers ADD COLUMN lat DECIMAL(10,7) NULL, ADD COLUMN lng DECIMAL(10,7) NULL'); } catch(Exception $e2){} }
        try {
            $pdo->beginTransaction();
            $hash = password_hash($pw, PASSWORD_DEFAULT);
            $iu = $pdo->prepare('INSERT INTO users (name,phone,password_hash,role,active,created_at) VALUES (?,?,?,"service_giver",1,NOW())');
            $iu->execute(array($name,$phone,$hash));
            $user_id = intval($pdo->lastInsertId());
            if (!$user_id) { $pdo->rollBack(); fail('Failed to create user',500); }
            $sg_code = 'SG-'.str_pad($user_id,5,'0',STR_PAD_LEFT);
            $isg = $pdo->prepare('INSERT INTO service_givers (user_id,service_giver_code,business_name,service_type,vehicle_type,zone,address,identification_number,status,is_online,average_rating,total_deliveries,completed_deliveries,failed_deliveries,total_earnings,rating_count,created_at) VALUES (?,?,?,?,?,?,?,?,"verified",0,0.00,0,0,0,0.00,0,NOW())');
            $isg->execute(array($user_id,$sg_code,$business_name,$service_type,$vehicle_type,$zone,$address,$id_number));
            $sg_id = intval($pdo->lastInsertId());
            if ($lat!==null && $lng!==null) { try { $pdo->prepare('UPDATE service_givers SET lat=?,lng=? WHERE id=?')->execute(array($lat,$lng,$sg_id)); } catch(Exception $e){ error_log('[ETEL] admin_create_service_giver lat/lng update failed for sg_id=' . $sg_id . ': ' . $e->getMessage()); } }
            $pdo->commit();
            ok(array('message'=>'Service giver created','user_id'=>$user_id,'service_giver_id'=>$sg_id,'code'=>$sg_code));
        } catch(Exception $e) { $pdo->rollBack(); fail_ex($e, 'Creation failed', 500); }
        break;
    }
    case 'admin_run_code_audit': case 'admin_get_audit_settings': case 'admin_save_audit_settings': case 'get_feature_flags': case 'admin_toggle_feature': case 'admin_list_etelpay_overrides': case 'admin_set_etelpay_override': case 'admin_remove_etelpay_override': case 'admin_list_support_threads': case 'admin_get_support_thread': case 'admin_reply_support_message': case 'admin_create_user': case 'admin_assign_service_giver': case 'admin_get_all_items': case 'admin_add_item': case 'admin_update_item': case 'admin_delete_item': case 'admin_toggle_item': case 'get_service_givers': case 'admin_get_service_giver': case 'admin_get_service_giver_statistics': case 'admin_get_confirmations': case 'admin_force_confirm': case 'admin_backfill_order_service_giver_ids': case 'admin_update_order': case 'admin_get_order_receipt': case 'admin_analytics': case 'admin_ride_growth': case 'admin_backfill_cash_commission': case 'admin_topup_etel_money': case 'admin_get_pending_etel_topups': case 'admin_approve_etel_topup': case 'admin_reject_etel_topup': case 'admin_get_ride_fare_rules': case 'admin_update_ride_fare_rule': case 'admin_add_vehicle_type': case 'admin_delete_vehicle_type': case 'admin_update_ride_surge_multiplier': case 'admin_get_ride_peak_windows': case 'admin_update_ride_peak_windows': case 'admin_get_zones': case 'admin_update_zone': case 'admin_add_zone': case 'admin_delete_zone': case 'admin_list_role_permissions': case 'admin_update_role_permissions': case 'admin_delete_role_permissions': case 'admin_get_my_capabilities':
    case 'admin_get_delivery_tiers': case 'admin_update_delivery_tier':
    case 'admin_get_bank_accounts': case 'admin_update_bank_account': case 'admin_create_announcement': case 'admin_get_announcements': case 'admin_delete_announcement': case 'admin_get_launch_checklist_state': case 'admin_toggle_launch_checklist_item': case 'admin_get_ride_issues': case 'admin_resolve_ride_issue': case 'admin_get_order_issues': case 'admin_resolve_order_issue': case 'admin_get_sos_alerts': case 'admin_resolve_sos': case 'admin_get_goal_tiers': case 'admin_save_goal_tier': case 'admin_delete_goal_tier': case 'admin_run_system_test':
    case 'admin_list_release_channels': case 'admin_set_release_channel': case 'admin_promote_canary_to_stable': case 'admin_rollback_stable': case 'admin_add_canary_tester': case 'admin_remove_canary_tester': case 'admin_search_canary_candidates':
        require __DIR__ . '/controllers/AdminController.php';
        break;
    case 'equity_get_settings': case 'equity_update_settings': case 'equity_list_investors': case 'equity_add_investor': case 'equity_update_investor': case 'equity_delete_investor': case 'equity_get_dashboard': case 'equity_preview_distribution': case 'equity_distribute_profit': case 'equity_list_distributions': case 'equity_get_distribution': case 'equity_export_investors_csv': case 'equity_regenerate_investor_token': case 'equity_link_investor_user': case 'equity_unlink_investor_user': case 'investor_portal_am_i_investor': case 'investor_portal_get_my_view': case 'investor_portal_get_my_growth_planner_data':
        // Separate controller, not folded into AdminController.php - this
        // is real founder financial/ownership data behind its own
        // 'equity.view'/'equity.manage' capabilities, not a generic admin
        // feature, so it deliberately doesn't share AdminController.php's
        // blast radius. investor_portal_get_my_* actions here are the
        // EtelID-authenticated self-service versions (any logged-in
        // user, gated by whether they're linked to an investor row) -
        // separate from InvestorPortalController.php's older, unauthenticated
        // token-link versions, which stay as a fallback for investors
        // without an EtelID account at all.
        require __DIR__ . '/controllers/EquityController.php';
        break;
    case 'system_reset_preview': case 'system_reset_backup': case 'system_reset_execute':
        // DANGER ZONE - full production data wipe. Gated behind
        // 'system.reset', a capability NOT included by a generic Owner
        // grant and which must be added explicitly in the Roles panel
        // as a deliberate extra step. See SystemResetController.php's
        // header comment for the forced preview -> backup -> confirm
        // sequence this enforces.
        require __DIR__ . '/controllers/SystemResetController.php';
        break;
    case 'equb_create_group': case 'equb_join_group': case 'equb_leave_group': case 'equb_start_group': case 'equb_get_my_groups': case 'equb_get_group_detail': case 'equb_admin_list_groups': case 'equb_am_i_permitted': case 'equb_list_open_groups': case 'equb_admin_grant_access': case 'equb_admin_revoke_access': case 'equb_admin_list_participants': case 'equb_admin_force_process_round': case 'equb_admin_add_member': case 'equb_admin_create_package': case 'equb_admin_update_package': case 'equb_admin_list_packages': case 'equb_list_packages': case 'equb_select_package':
        // Group creation and starting are admin-only (equb.manage) -
        // regular permitted users can only join/leave/browse. The
        // actual per-round money movement lives in
        // lib/equb_processing.php, shared with the standalone
        // equb_cron.php, so there's exactly one place that logic can
        // exist - never duplicated between the scheduled job and the
        // admin's manual "force process" button.
        require __DIR__ . '/controllers/EqubController.php';
        break;
    case 'planning_list_hires': case 'planning_add_hire': case 'planning_update_hire': case 'planning_delete_hire': case 'planning_get_notes': case 'planning_save_notes': case 'planning_reset_and_seed_demo':
        // Business Plan tab: persists the hiring plan + free-text
        // notes. The capital-requirement calculation itself is
        // computed client-side from REAL data already fetched via
        // equity_get_settings and platform_economics_get_costs
        // (both already exist) - not duplicated here.
        require __DIR__ . '/controllers/PlanningController.php';
        break;
    case 'admin_docs_list': case 'admin_docs_get': case 'admin_docs_create_text': case 'admin_docs_update_text':
    case 'admin_docs_update_meta': case 'admin_docs_restore_version': case 'admin_docs_delete':
        // Admin Documents library (browse/view/edit/version PDFs, Office
        // files, Markdown, XML/.rels, etc). Binary upload/replace and
        // file streaming live in the separate documents_upload.php /
        // documents_download.php - boot.php can't parse multipart bodies
        // or stream raw bytes (it always sends a JSON content-type
        // header up front), same reason upload.php/admin-branding.php
        // already stay standalone. See migration 056_admin_documents.sql.
        require __DIR__ . '/controllers/DocumentsController.php';
        break;
    case 'platform_economics_get_costs': case 'platform_economics_update_costs': case 'platform_economics_get_summary':
    case 'platform_economics_get_growth_assumptions': case 'platform_economics_save_growth_assumptions': case 'platform_economics_reset_growth_assumptions':
        // Real-revenue-vs-real-cost-assumptions calculator. Own
        // capabilities (platform_economics.view/.manage), not reused
        // from finance.view (which is specifically "bank accounts").
        require __DIR__ . '/controllers/PlatformEconomicsController.php';
        break;
    case 'investor_portal_get_view': case 'investor_portal_get_growth_planner_data':
        // Token-gated, not admin-gated - see InvestorPortalController.php's
        // header comment. Its own file so this access model (personal
        // token, not staff login) is never confused with the admin
        // capability system.
        require __DIR__ . '/controllers/InvestorPortalController.php';
        break;
    case 'driver_add_fuel_log': case 'driver_list_fuel_logs': case 'driver_delete_fuel_log': case 'driver_get_fuel_summary':
        require __DIR__ . '/controllers/DriverFuelController.php';
        break;
    case 'courier_add_fuel_log': case 'courier_list_fuel_logs': case 'courier_delete_fuel_log': case 'courier_get_fuel_summary':
        require __DIR__ . '/controllers/CourierFuelController.php';
        break;
    case 'customer_create_order': case 'customer_get_orders': case 'submit_order_issue': case 'customer_get_order_issues': case 'courier_get_available_orders': case 'courier_accept_order': case 'courier_update_order_status': case 'courier_get_my_orders': case 'courier_get_profile': case 'courier_go_online': case 'courier_go_offline': case 'courier_update_location': case 'courier_get_kpi_summary': case 'rate_order': case 'get_order_ratings': case 'track_order': case 'schedule_order': case 'get_scheduled_orders': case 'request_pickup_confirmation': case 'check_pickup_confirmation': case 'sg_pending_confirmations': case 'sg_confirm_pickup': case 'request_delivery_confirmation': case 'check_delivery_confirmation': case 'customer_pending_confirmations': case 'customer_confirm_delivery': case 'customer_wallet_history': case 'initiate_telebirr_payment': case 'check_telebirr_payment': case 'admin_list_telebirr_payments': case 'admin_confirm_telebirr_payment': case 'confirm_bank_payment': case 'admin_list_bank_payments': case 'admin_confirm_bank_payment': case 'admin_financial_report': case 'get_payment_method_logos': case 'admin_set_payment_logo':
    case 'customer_get_favorites': case 'customer_toggle_favorite': case 'customer_get_addresses': case 'customer_add_address': case 'customer_update_address': case 'customer_delete_address': case 'customer_get_messages': case 'customer_send_message': case 'customer_get_referral_info': case 'customer_get_settings': case 'customer_update_settings':
        require __DIR__ . '/controllers/OrderController.php';
        break;
    case 'sg_profile': case 'sg_update_profile': case 'sg_orders': case 'sg_accept_order': case 'sg_update_order_status': case 'sg_get_kitchen_ticket': case 'sg_order_detail': case 'sg_scan_qr': case 'sg_complete_order': case 'sg_stats': case 'sg_set_online': case 'sg_notifications': case 'sg_add_item': case 'sg_get_my_items': case 'sg_update_item': case 'sg_delete_item': case 'sg_get_staff': case 'sg_add_staff': case 'sg_remove_staff': case 'sg_update_staff_role': case 'sg_list_roles': case 'sg_save_role': case 'sg_delete_role': case 'sg_my_role': case 'sg_financial_report': case 'sg_get_pending_payout_request': case 'admin_list_payout_requests': case 'admin_review_payout_request':
        require __DIR__ . '/controllers/ServiceGiverController.php';
        break;
    case 'agent_ride_quote': case 'agent_book_ride': case 'agent_search_items': case 'agent_order_quote': case 'agent_place_order': case 'agent_ride_status': case 'agent_cancel_ride': case 'agent_order_status': case 'agent_find_and_quote':
        require __DIR__ . '/controllers/AgentController.php';
        break;
    case 'book_ride': case 'ride_status': case 'my_active_ride': case 'cancel_ride': case 'rate_ride': case 'ride_history': case 'driver_go_online': case 'driver_go_offline': case 'driver_update_location': case 'driver_get_nearby_requests': case 'driver_get_profile': case 'driver_get_earnings_for_date': case 'driver_get_kpi_summary': case 'driver_beacon_ping': case 'driver_accept_ride': case 'driver_start_ride': case 'driver_complete_ride': case 'driver_cancel_ride': case 'passenger_get_ride_history': case 'driver_hail_customer': case 'driver_arrived': case 'customer_confirm_arrival': case 'driver_update_profile': case 'driver_cashout': case 'driver_topup_etel_money': case 'driver_submit_manual_topup': case 'driver_get_topup_history': case 'get_etelpay_merchant': case 'admin_get_rides': case 'admin_get_ride_stats': case 'admin_cancel_ride': case 'admin_manual_complete_ride': case 'admin_get_drivers': case 'admin_get_live_drivers': case 'admin_get_suspended_drivers': case 'admin_suspend_driver': case 'admin_unsuspend_driver': case 'send_ride_message': case 'get_ride_messages': case 'ping_ride_typing': case 'driver_get_announcements': case 'driver_mark_announcement_read': case 'driver_get_referral_info': case 'submit_ride_issue': case 'sos_trigger': case 'ride_get_favorite_routes': case 'ride_add_favorite_route': case 'ride_delete_favorite_route': case 'ride_get_messages': case 'ride_estimate_fare': case 'passenger_get_nearby_drivers': case 'ride_get_vehicle_types': case 'driver_toggle_wait': case 'driver_get_goal_tiers': case 'get_ride_path': case 'driver_get_pending_vehicle_change': case 'admin_get_pending_vehicle_changes': case 'admin_approve_vehicle_change': case 'admin_reject_vehicle_change': case 'admin_get_flagged_identities': case 'admin_approve_driver_identity': case 'admin_reject_driver_identity':
    case 'admin_list_driver_compliance': case 'admin_update_driver_compliance':
    case 'driver_get_compliance_status': case 'driver_submit_compliance_document':
    case 'admin_get_pending_compliance_requests': case 'admin_approve_compliance_document': case 'admin_reject_compliance_document':
    case 'rate_rider': case 'ride_get_share_link': case 'ride_stop_sharing':
    case 'ride_schedule': case 'ride_get_scheduled': case 'ride_cancel_scheduled':
        require __DIR__ . '/controllers/RideController.php';
        break;
    case 'save_push_subscription': case 'remove_push_subscription': case 'get_vapid_public_key': case 'test_send_push':
        require __DIR__ . '/controllers/PushController.php';
        break;
    case 'nfc_list_courier_orders': case 'nfc_confirm_pickup': case 'nfc_confirm_delivery':
    case 'nfc_driver_status': case 'nfc_start_ride':
        // Service-key gated (EtelID calls these server-to-server after an
        // NFC card scan) - see NfcController.php's own header comment.
        require __DIR__ . '/controllers/NfcController.php';
        break;
    case 'get_items': case 'customer_get_items': {
        $pdo = db();
        $cat = isset($_GET['category']) ? trim($_GET['category']) : '';
        $sgid = isset($_GET['service_giver_id']) ? intval($_GET['service_giver_id']) : 0;
        $q = isset($_GET['q']) ? trim($_GET['q']) : '';
        $w = "WHERE sg.status = 'verified'"; $p = array();
        $vc = array('food','grocery','pharmacy','laundry','parcel','gift','package','flower');
        if ($cat && in_array($cat, $vc, true)) { $w .= ' AND i.category = ?'; $p[] = $cat; }
        if ($sgid > 0) { $w .= ' AND i.service_giver_id = ?'; $p[] = $sgid; }
        if ($q !== '') { $w .= ' AND (i.name LIKE ? OR sg.business_name LIKE ?)'; $lk='%'.$q.'%'; $p[]=$lk; $p[]=$lk; }
        try {
            $st = $pdo->prepare("SELECT i.id,i.name,i.name_am,i.description,i.description_am,i.category,i.price,
                COALESCE(i.original_price,i.price) AS original_price,i.image_url,
                COALESCE(i.stock_quantity,i.stock_qty,100) AS stock_quantity,
                COALESCE(i.preparation_time,i.prep_time_min,15) AS preparation_time,
                COALESCE(i.is_available,i.active,1) AS is_available,
                i.service_giver_id,sg.business_name,COALESCE(sg.zone,'') AS zone,
                COALESCE(sg.service_type,sg.category,'general') AS service_type,
                COALESCE(sg.average_rating,sg.rating,0) AS rating
                FROM items i JOIN service_givers sg ON i.service_giver_id=sg.id $w
                ORDER BY rating DESC,i.id DESC LIMIT 200");
            $st->execute($p); ok(array('items'=>$st->fetchAll(),'count'=>$st->rowCount()));
        } catch(PDOException $e) {
            try {
                $fb=$pdo->prepare("SELECT i.id,i.name,i.description,i.category,i.price,
                    i.price AS original_price,i.image_url,100 AS stock_quantity,
                    15 AS preparation_time,1 AS is_available,i.service_giver_id,
                    sg.business_name,'' AS zone,'general' AS service_type,0 AS rating
                    FROM items i JOIN service_givers sg ON i.service_giver_id=sg.id
                    ORDER BY i.id DESC LIMIT 200");
                $fb->execute(); ok(array('items'=>$fb->fetchAll(),'count'=>$fb->rowCount()));
            } catch(PDOException $e2) { fail_ex($e2, 'Operation failed', 500); }
        }
        break;
    }
    case 'etelid_members_list': {
        $configured_key = etel_env('ETELID_SERVICE_KEY', 'CFG_ETELID_SERVICE_KEY', '');
        if (!$configured_key) fail('Service key not configured on Delivery.', 500);
        $provided = '';
        if (isset($_SERVER['HTTP_X_ETEL_SERVICE_KEY'])) {
            $provided = $_SERVER['HTTP_X_ETEL_SERVICE_KEY'];
        } elseif (function_exists('getallheaders')) {
            $hdrs = getallheaders();
            foreach ($hdrs as $hk => $hv) {
                if (strcasecmp($hk, 'X-Etel-Service-Key') === 0) { $provided = $hv; break; }
            }
        }
        $match = function_exists('hash_equals') ? hash_equals($configured_key, (string)$provided) : ($provided === $configured_key);
        if (!$match) fail('Invalid or missing service key.', 401);
        $pdo = db();
        $limit = isset($_GET['limit']) ? max(1, min(500, (int)$_GET['limit'])) : 200;
        try {
            $rows = $pdo->query('SELECT id, name, phone, role, active, created_at FROM users ORDER BY id DESC LIMIT ' . (int)$limit)->fetchAll();
        } catch (Exception $e) { fail_ex($e, 'Query failed', 500); }
        $courier_by_uid = array();
        try {
            $crows = $pdo->query('SELECT user_id, courier_code, status, rating, total_deliveries FROM couriers')->fetchAll();
            foreach ($crows as $c) { $courier_by_uid[$c['user_id']] = $c; }
        } catch (Exception $e) {}
        $out = array();
        foreach ($rows as $r) {
            $courier = isset($courier_by_uid[$r['id']]) ? $courier_by_uid[$r['id']] : null;
            $out[] = array(
                'id'               => (int)$r['id'],
                'name'             => isset($r['name'])  ? $r['name']  : '',
                'phone'            => isset($r['phone']) ? $r['phone'] : '',
                'email'            => '',
                'role'             => isset($r['role'])  ? $r['role']  : '',
                'status'           => (isset($r['active']) && !$r['active']) ? 'suspended' : 'active',
                'courier_code'     => $courier ? $courier['courier_code']     : '',
                'courier_status'   => $courier ? $courier['status']           : '',
                'rating'           => $courier ? (float)$courier['rating']    : null,
                'total_deliveries' => $courier ? (int)$courier['total_deliveries'] : null,
                'created'          => isset($r['created_at']) ? $r['created_at'] : '',
            );
        }
        ok(array('members' => $out, 'total' => count($out)));
        break;
    }

    case 'etelid_member_detail': {
        $configured_key = etel_env('ETELID_SERVICE_KEY', 'CFG_ETELID_SERVICE_KEY', '');
        if (!$configured_key) fail('Service key not configured on Delivery.', 500);
        $provided = '';
        if (isset($_SERVER['HTTP_X_ETEL_SERVICE_KEY'])) {
            $provided = $_SERVER['HTTP_X_ETEL_SERVICE_KEY'];
        } elseif (function_exists('getallheaders')) {
            $hdrs = getallheaders();
            foreach ($hdrs as $hk => $hv) {
                if (strcasecmp($hk, 'X-Etel-Service-Key') === 0) { $provided = $hv; break; }
            }
        }
        if (!$provided && isset($_GET['key'])) { $provided = $_GET['key']; }
        $match = function_exists('hash_equals') ? hash_equals($configured_key, (string)trim($provided)) : (trim($provided) === $configured_key);
        if (!$match) fail('Invalid or missing service key.', 401);
        $uid = isset($_GET['id']) ? (int)$_GET['id'] : (isset($body['id']) ? (int)$body['id'] : 0);
        if (!$uid) fail('Missing id.', 400);
        $pdo = db();
        try {
            $uq = $pdo->prepare('SELECT id, name, phone, role, active, created_at FROM users WHERE id=?');
            $uq->execute(array($uid));
            $user = $uq->fetch();
            if (!$user) fail('User not found.', 404);
            $orders = $pdo->prepare(
                "SELECT id, order_code, from_zone, to_zone, package_type, fare_total, status, placed_at
                 FROM orders WHERE customer_id=? OR courier_id=? ORDER BY id DESC LIMIT 15"
            );
            $orders->execute(array($uid, $uid));
            $rides = $pdo->prepare(
                "SELECT id, pickup_address, dropoff_address, distance_km, fare_final, status, rating, created_at
                 FROM rides WHERE passenger_id=? OR driver_id=? ORDER BY id DESC LIMIT 15"
            );
            $rides->execute(array($uid, $uid));
            $courier = null;
            try {
                $c = $pdo->prepare('SELECT courier_code, status, average_rating, total_deliveries, completed_deliveries, total_earnings FROM service_givers WHERE user_id=?');
                $c->execute(array($uid)); $courier = $c->fetch();
            } catch (Exception $e) {}
            if (!$courier) {
                try {
                    $c2 = $pdo->prepare('SELECT courier_code, status, rating, total_deliveries FROM couriers WHERE user_id=?');
                    $c2->execute(array($uid)); $courier = $c2->fetch();
                } catch (Exception $e) {}
            }
            $driver = null;
            try {
                $d = $pdo->prepare('SELECT rating_sum, rating_count, online FROM ride_drivers WHERE user_id=?');
                $d->execute(array($uid)); $driver = $d->fetch();
                if ($driver) {
                    $driver['rating'] = $driver['rating_count'] > 0 ? round($driver['rating_sum'] / $driver['rating_count'], 1) : 0;
                }
            } catch (Exception $e) {}
        } catch (Exception $e) { fail_ex($e, 'Query failed', 500); }
        ok(array(
            'user'    => $user,
            'orders'  => $orders->fetchAll(),
            'rides'   => $rides->fetchAll(),
            'courier' => $courier ?: null,
            'driver'  => $driver  ?: null,
        ));
        break;
    }

    case 'sse_admin_delivery_system_test': {
        $pdo = db();
        require_admin($pdo, 'orders.manage');
        require_once __DIR__ . '/lib/delivery_order_core.php';
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');

        $emit = function ($label, $ok, $detail = null) {
            echo 'data: ' . json_encode(array('step' => $label, 'ok' => $ok, 'detail' => $detail)) . "\n\n";
            @ob_flush(); flush();
            usleep(400000);
        };

        // 1. Demo accounts - customer reused from the ride test (same
        // account, one identity), plus a dedicated demo service giver
        // and demo courier.
        $custId = null; $sgUserId = null; $sgId = null; $courierUserId = null; $courierId = null;
        try {
            $s = $pdo->prepare('SELECT id FROM users WHERE phone=?');
            $s->execute(array('0900000001'));
            $row = $s->fetch();
            if ($row) { $custId = $row['id']; }
            else {
                $pdo->prepare("INSERT INTO users (name, phone, role, password_hash, created_at) VALUES ('Demo Customer (System Test)', '0900000001', 'customer', ?, NOW())")
                    ->execute(array(password_hash('demotest1234', PASSWORD_DEFAULT)));
                $custId = $pdo->lastInsertId();
            }
            $s->execute(array('0900000003'));
            $row = $s->fetch();
            if ($row) { $sgUserId = $row['id']; }
            else {
                $pdo->prepare("INSERT INTO users (name, phone, role, password_hash, created_at) VALUES ('Demo Shop Owner (System Test)', '0900000003', 'service_giver', ?, NOW())")
                    ->execute(array(password_hash('demotest1234', PASSWORD_DEFAULT)));
                $sgUserId = $pdo->lastInsertId();
            }
            $s->execute(array('0900000004'));
            $row = $s->fetch();
            if ($row) { $courierUserId = $row['id']; }
            else {
                $pdo->prepare("INSERT INTO users (name, phone, role, password_hash, created_at) VALUES ('Demo Courier (System Test)', '0900000004', 'courier', ?, NOW())")
                    ->execute(array(password_hash('demotest1234', PASSWORD_DEFAULT)));
                $courierUserId = $pdo->lastInsertId();
            }
            $courier = get_or_create_courier_profile($pdo, $courierUserId);
            if (!$courier) throw new Exception('Courier profile could not be created');
            $courierId = $courier['id'];
            $emit('Demo accounts ready', true, "Customer #$custId - Shop owner #$sgUserId - Courier #$courierUserId");
        } catch (Exception $e) {
            $emit('Demo accounts ready', false, $e->getMessage());
            echo "event: close\ndata: {}\n\n"; @ob_flush(); flush(); exit;
        }

        // 2. Demo shop + item - self-healing, reused on every run.
        $itemId = null;
        try {
            $s = $pdo->prepare('SELECT id FROM service_givers WHERE user_id=?');
            $s->execute(array($sgUserId));
            $row = $s->fetch();
            if ($row) { $sgId = $row['id']; }
            else {
                $code = 'SG-TEST' . substr(md5($sgUserId), 0, 4);
                $pdo->prepare("INSERT INTO service_givers (user_id, service_giver_code, business_name, service_type, zone, status, created_at)
                               VALUES (?, ?, 'Demo Shop (System Test)', 'restaurant', 'Mehal Ketema', 'active', NOW())")
                    ->execute(array($sgUserId, $code));
                $sgId = $pdo->lastInsertId();
            }
            $s = $pdo->prepare('SELECT id FROM items WHERE service_giver_id=? AND item_code=?');
            $s->execute(array($sgId, 'DEMO-ITEM-001'));
            $row = $s->fetch();
            if ($row) { $itemId = $row['id']; }
            else {
                $pdo->prepare("INSERT INTO items (service_giver_id, item_code, name, category, price, stock_quantity, is_available)
                               VALUES (?, 'DEMO-ITEM-001', 'Demo Test Item', 'food', 85.00, 999, 1)")
                    ->execute(array($sgId));
                $itemId = $pdo->lastInsertId();
            }
            $emit('Demo shop + item ready', true, "Shop #$sgId (Mehal Ketema) - Item #$itemId, ETB 85.00");
        } catch (Exception $e) {
            $emit('Demo shop + item ready', false, $e->getMessage());
            echo "event: close\ndata: {}\n\n"; @ob_flush(); flush(); exit;
        }

        // 3. Create the order through the REAL shared function - same
        // code path a genuine customer order goes through, not a hand-
        // rolled INSERT that could silently drift from the real schema.
        $orderId = null; $orderResult = null;
        try {
            $custRow = array('id' => $custId, 'role' => 'customer', 'name' => 'Demo Customer (System Test)');
            $orderResult = delivery_execute_order_creation($pdo, $custRow, array(
                'items' => array(array('item_id' => $itemId, 'quantity' => 2)),
                'to_zone' => 'Tabor',
                'recipient_name' => 'Demo Recipient',
                'recipient_phone' => '0911223344',
                'notes' => '[SYSTEM TEST] auto-generated order',
                'payment_method' => 'cash',
                'delivery_tier' => 'moto',
            ));
            $orderId = $orderResult['order_id'];
            $emit('Order created', true, 'Order #' . $orderId . ' - 2x Demo Test Item - Total ETB ' . $orderResult['total']);
        } catch (Exception $e) {
            $emit('Order created', false, $e->getMessage());
            echo "event: close\ndata: {}\n\n"; @ob_flush(); flush(); exit;
        }

        try {
            $pdo->prepare('UPDATE orders SET courier_id=?, status="assigned" WHERE id=?')->execute(array($courierId, $orderId));
            $pdo->prepare('UPDATE order_assignments SET status="assigned" WHERE order_id=?')->execute(array($orderId));
            $emit('Courier accepted', true, "Demo Courier #$courierId assigned to order #$orderId");
        } catch (Exception $e) { $emit('Courier accepted', false, $e->getMessage()); }

        try {
            $pdo->prepare('UPDATE orders SET status="picked_up" WHERE id=?')->execute(array($orderId));
            $pdo->prepare('UPDATE order_assignments SET status="picked_up" WHERE order_id=?')->execute(array($orderId));
            $emit('Picked up from shop', true, 'Demo Shop confirmed handoff to courier');
        } catch (Exception $e) { $emit('Picked up from shop', false, $e->getMessage()); }

        try {
            $pdo->prepare('UPDATE orders SET status="in_transit" WHERE id=?')->execute(array($orderId));
            $emit('In transit', true, 'Courier en route to ' . 'Tabor');
        } catch (Exception $e) { $emit('In transit', false, $e->getMessage()); }

        try {
            $pdo->prepare('UPDATE orders SET status="delivered", delivered_at=NOW() WHERE id=?')->execute(array($orderId));
            $pdo->prepare('UPDATE order_assignments SET status="delivered" WHERE order_id=?')->execute(array($orderId));
            $emit('Delivered', true, 'Order #' . $orderId . ' marked delivered, ETB ' . $orderResult['total'] . ' (' . $orderResult['payment_method'] . ')');
        } catch (Exception $e) { $emit('Delivered', false, $e->getMessage()); }

        try {
            $pdo->prepare('DELETE FROM order_items WHERE order_id=?')->execute(array($orderId));
            $pdo->prepare('DELETE FROM order_assignments WHERE order_id=?')->execute(array($orderId));
            $pdo->prepare('DELETE FROM orders WHERE id=?')->execute(array($orderId));
            $emit('Test data cleaned up', true, "Order #$orderId and its line items removed - won't appear in real stats or revenue");
        } catch (Exception $e) { $emit('Test data cleaned up', false, $e->getMessage()); }

        echo 'data: ' . json_encode(array('final' => true)) . "\n\n";
        echo "event: close\ndata: {}\n\n";
        @ob_flush(); flush(); exit;
    }

    case 'sse_admin_system_test': {
        $pdo = db();
        require_admin($pdo, 'rides.manage');
        require_once __DIR__ . '/lib/ride_fare.php';
        rides_ensure_wait_columns($pdo);
        rides_ensure_goal_tiers_table($pdo);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');

        $emit = function ($label, $ok, $detail = null) {
            echo 'data: ' . json_encode(array('step' => $label, 'ok' => $ok, 'detail' => $detail)) . "\n\n";
            @ob_flush(); flush();
            usleep(400000); // small pacing so each step is actually visible landing, not a single instant flash
        };

        $custId = null; $drvId = null;
        try {
            $s = $pdo->prepare('SELECT id FROM users WHERE phone=?');
            $s->execute(array('0900000001'));
            $row = $s->fetch();
            if ($row) { $custId = $row['id']; }
            else {
                $pdo->prepare("INSERT INTO users (name, phone, role, password_hash, created_at) VALUES ('Demo Customer (System Test)', '0900000001', 'customer', ?, NOW())")
                    ->execute(array(password_hash('demotest1234', PASSWORD_DEFAULT)));
                $custId = $pdo->lastInsertId();
            }
            $s->execute(array('0900000002'));
            $row = $s->fetch();
            if ($row) { $drvId = $row['id']; }
            else {
                $pdo->prepare("INSERT INTO users (name, phone, role, password_hash, created_at) VALUES ('Demo Driver (System Test)', '0900000002', 'driver', ?, NOW())")
                    ->execute(array(password_hash('demotest1234', PASSWORD_DEFAULT)));
                $drvId = $pdo->lastInsertId();
            }
            $pdo->prepare("INSERT INTO ride_drivers (user_id, online, vehicle_type, vehicle_plate, vehicle_make, vehicle_color, last_seen)
                           VALUES (?, 1, 'bajaj', 'DEMO-001', 'Demo Bajaj', 'Gold', NOW())
                           ON DUPLICATE KEY UPDATE online=1, last_seen=NOW()")
                ->execute(array($drvId));
            $emit('Demo accounts ready', true, "Customer #$custId - Driver #$drvId");
        } catch (Exception $e) {
            $emit('Demo accounts ready', false, $e->getMessage());
            echo "event: close\ndata: {}\n\n"; @ob_flush(); flush(); exit;
        }

        $rideId = null;
        $plat = 7.0500; $plng = 38.4700; $dlat = 7.0650; $dlng = 38.4850;
        try {
            $pdo->prepare("INSERT INTO rides
                    (passenger_id, pickup_lat, pickup_lng, dropoff_lat, dropoff_lng,
                     pickup_address, dropoff_address, distance_km, fare_estimate, fare_final,
                     payment_method, status, open_trip, vehicle_type, expires_at, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?, 'searching', 1, 'bajaj', DATE_ADD(NOW(), INTERVAL 10 MINUTE), NOW())")
                ->execute(array($custId, $plat, $plng, $dlat, $dlng,
                    '[SYSTEM TEST] Pickup', '[SYSTEM TEST] Dropoff', 3.0, 150, 150, 'cash'));
            $rideId = $pdo->lastInsertId();
            $emit('Booking created', true, "Ride #$rideId - open trip - Bajaj");
        } catch (Exception $e) {
            $emit('Booking created', false, $e->getMessage());
            echo "event: close\ndata: {}\n\n"; @ob_flush(); flush(); exit;
        }

        try {
            $pdo->prepare("UPDATE rides SET status='accepted', driver_id=?, assigned_at=NOW() WHERE id=?")->execute(array($drvId, $rideId));
            $emit('Driver accepted', true, "Demo Driver #$drvId assigned to ride #$rideId");
        } catch (Exception $e) { $emit('Driver accepted', false, $e->getMessage()); }

        try {
            $pdo->prepare("UPDATE rides SET status='in_progress', started_at=NOW(), traveled_km=0, last_lat=?, last_lng=? WHERE id=?")
                ->execute(array($plat, $plng, $rideId));
            $emit('Trip started', true, 'GPS meter reset to 0.00 km');
        } catch (Exception $e) { $emit('Trip started', false, $e->getMessage()); }

        try {
            $pdo->prepare("UPDATE rides SET traveled_km=3.2, last_lat=?, last_lng=? WHERE id=?")->execute(array($dlat, $dlng, $rideId));
            $emit('GPS movement simulated', true, '3.20 km traveled (simulated, not real driving)');
        } catch (Exception $e) { $emit('GPS movement simulated', false, $e->getMessage()); }

        try {
            $pdo->prepare("UPDATE rides SET wait_active=1, wait_started_at=DATE_SUB(NOW(), INTERVAL 5 MINUTE) WHERE id=?")->execute(array($rideId));
            $pdo->prepare("UPDATE rides SET wait_active=0, wait_started_at=NULL, wait_seconds_total=300 WHERE id=?")->execute(array($rideId));
            $emit('Waiting fee simulated', true, '5 minutes (300s) recorded, same as a real Start/Stop Waiting cycle');
        } catch (Exception $e) { $emit('Waiting fee simulated', false, $e->getMessage()); }

        $breakdown = null;
        try {
            $fareCalc = rides_calc_fare($pdo, 3.2, 'bajaj', true, 5.0);
            $breakdown = rides_fare_breakdown_for_ui($fareCalc);
            $pdo->prepare("UPDATE rides SET status='arrived', arrived_at=NOW(), fare_final=? WHERE id=?")
                ->execute(array($fareCalc['fare'], $rideId));
            $emit('Arrival + fare calculated', true,
                'Base ETB ' . $breakdown['fare_base'] . ' + Distance ETB ' . $breakdown['fare_distance'] .
                ' + Wait ETB ' . $breakdown['fare_wait'] .
                ($breakdown['fare_surge_etb'] > 0 ? ' + Surge ETB ' . $breakdown['fare_surge_etb'] : '') .
                ' = ETB ' . $breakdown['fare_total']);
        } catch (Exception $e) { $emit('Arrival + fare calculated', false, $e->getMessage()); }

        try {
            $pdo->prepare("UPDATE rides SET status='completed', completed_at=NOW(), fare_paid=fare_final WHERE id=?")->execute(array($rideId));
            $emit('Payment confirmed', true, 'Ride marked completed, same as the customer tapping confirm');
        } catch (Exception $e) { $emit('Payment confirmed', false, $e->getMessage()); }

        try {
            $pdo->prepare("DELETE FROM rides WHERE id=?")->execute(array($rideId));
            $emit('Test data cleaned up', true, "Ride #$rideId removed - won't appear in real stats or revenue");
        } catch (Exception $e) { $emit('Test data cleaned up', false, $e->getMessage()); }

        echo 'data: ' . json_encode(array('final' => true, 'fare_breakdown' => $breakdown)) . "\n\n";
        echo "event: close\ndata: {}\n\n";
        @ob_flush(); flush(); exit;
    }

    case 'sse_ride_status': {
        $u   = require_auth(db());
        $rid = isset($_GET['id']) ? intval($_GET['id']) : 0;
        if (!$rid) fail('id required');
        $pdo = db();
        require_once __DIR__ . '/lib/ride_fare.php'; // not loaded on this code path otherwise - needed for rides_calc_fare()
        rides_ensure_wait_columns($pdo);
        $chk = $pdo->prepare('SELECT id FROM rides WHERE id=? AND passenger_id=? LIMIT 1');
        $chk->execute(array($rid, $u['id']));
        if (!$chk->fetch()) fail('Ride not found', 404);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');
        $ticks = 0;
        while ($ticks < 150) {
            if (connection_aborted()) break;
            try {
                $s = $pdo->prepare('SELECT r.status, r.fare_estimate, r.fare_final, r.traveled_km, r.open_trip,
                    r.pickup_address, r.dropoff_address, r.wait_active, r.wait_started_at, r.wait_seconds_total,
                    rd.lat AS dlat, rd.lng AS dlng, u.name AS dname, u.phone AS dphone,
                    rd.vehicle_type, rd.vehicle_make, rd.vehicle_color, rd.vehicle_plate,
                    rd.selfie_url,
                    CASE WHEN rd.rating_count > 0 THEN ROUND(rd.rating_sum/rd.rating_count,1) ELSE 0 END AS drating
                    FROM rides r
                    LEFT JOIN ride_drivers rd ON rd.user_id=r.driver_id
                    LEFT JOIN users u ON u.id=r.driver_id
                    WHERE r.id=?');
                $s->execute(array($rid));
                $row = $s->fetch();
            } catch (Exception $e) { break; }
            if ($row) {
                /* Same live-meter fix as the other ride-status endpoints -
                   prefer an actual GPS-distance-based fare over the static
                   pre-trip estimate whenever the trip is genuinely metered
                   and in progress, so the customer's live-tracking screen
                   doesn't show 30+ ETB "for free" before the driver moves.
                   wait_etb/wait_active/wait_minutes are broadcast the same
                   way for every in-progress ride (not just open-trip ones)
                   so a waiting-fee charge the driver starts is visible on
                   the customer's screen within seconds, not just at the
                   final bill - the "both interfaces, for evidence" part. */
                $liveFare = null;
                $breakdown = array('fare_base'=>0,'fare_distance'=>0,'fare_wait'=>0,'fare_subtotal'=>0,'fare_surge_mult'=>1.0,'fare_surge_etb'=>0,'fare_total'=>0);
                $waitMinutes = $row['status'] === 'in_progress' ? rides_current_wait_minutes($row) : 0;
                $vtype = !empty($row['vehicle_type']) ? $row['vehicle_type'] : 'bajaj';
                if ($row['status'] === 'in_progress') {
                    try {
                        $isOpenTrip = intval($row['open_trip']) === 1;
                        $liveFare = rides_calc_fare($pdo, floatval($row['traveled_km']), $vtype, $isOpenTrip, $waitMinutes);
                        $breakdown = rides_fare_breakdown_for_ui($liveFare);
                    } catch (Exception $e) { /* fall through to the old fare fields below */ }
                }
                echo 'data: ' . json_encode(array_merge(array(
                    'status'        => $row['status'],
                    'driver_lat'    => $row['dlat'] ? floatval($row['dlat']) : null,
                    'driver_lng'    => $row['dlng'] ? floatval($row['dlng']) : null,
                    'driver_name'   => $row['dname'],
                    'driver_phone'  => $row['dphone'],
                    'driver_photo'  => $row['selfie_url'] ?: null,
                    'driver_rating' => floatval($row['drating']),
                    'vehicle_type'  => $row['vehicle_type'],
                    'vehicle_plate' => $row['vehicle_plate'],
                    'pickup_address'  => $row['pickup_address'],
                    'dropoff_address' => $row['dropoff_address'],
                    'traveled_km'   => round(floatval($row['traveled_km']), 2),
                    'wait_active'   => (bool) $row['wait_active'],
                    'wait_minutes'  => round($waitMinutes, 2),
                    'fare'          => $liveFare !== null ? $liveFare['fare'] : floatval($row['fare_final'] ?: $row['fare_estimate']),
                    'surge'         => $liveFare !== null ? $liveFare['surge'] : false,
                ), $breakdown)) . "\n\n";
                if (in_array($row['status'], array('completed','cancelled'), true)) break;
            }
            @ob_flush(); flush(); sleep(2); $ticks++;
        }
        echo "event: close\ndata: {}\n\n";
        @ob_flush(); flush(); exit;
    }

    case 'sse_driver_stream': {
        $u      = require_auth(db());
        $pdo    = db();
        $radius = isset($_GET['radius_km']) ? floatval($_GET['radius_km']) : 8;
        if ($radius <= 0 || $radius > 50) $radius = 8;
        /* BUG FIX: this SSE stream is a second, separate implementation of
           "get nearby requests" (RideController.php's driver_get_nearby_requests
           is the other one, used only as a polling fallback if SSE fails) -
           and it never got the vehicle-type filter that endpoint has. Since
           SSE is the PRIMARY channel drivers actually use, every online
           driver was seeing every searching ride regardless of vehicle
           type - a Bajaj driver saw Car/Qute/Minibus requests too. Fetches
           the driver's own vehicle_type fresh every tick (see inside the
           loop below) and filters the query by it, same logic as the
           polling endpoint. */
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');
        $ticks = 0;
        while ($ticks < 300) {
            if (connection_aborted()) break;
            try {
                $pdo->exec("UPDATE rides SET status='cancelled', cancelled_at=NOW()
                            WHERE status='searching' AND expires_at IS NOT NULL AND expires_at < NOW()");
                /* STALE-VTYPE FIX: vehicle_type used to be fetched once before
                   the loop and held for the connection's full 15-minute
                   lifetime - so a driver's vehicle_type getting corrected
                   mid-connection (a DB fix, an admin-approved vehicle change)
                   had no effect until that SSE connection happened to die and
                   reopen. Needs to be re-read every tick to fix that - but
                   NOT as a separate query. This loop already runs on a shared-
                   hosting connection budget tight enough that one extra
                   query/tick previously caused a full reconnect-storm outage
                   (every tick erroring, EventSource retrying instantly,
                   compounding the same connection pressure that caused it).
                   Piggyback vehicle_type onto the $dp driver-lookup query
                   below instead, which already runs every tick anyway - zero
                   added round-trips, still always fresh. */
                /* Reverted from calling the full rides_get_active_for_driver()
                   here (which does a live fare recompute via rides_calc_fare)
                   back to a light, purpose-built query. This SSE loop holds
                   one DB connection open for up to 15 minutes per online
                   driver (300 ticks x 3s) - the heavier per-tick query was
                   almost certainly what pushed shared hosting's low MySQL
                   connection/query limits over the edge under just a couple
                   of concurrent drivers, causing multi-second-to-30s+ query
                   latency and cascading 500s across completely unrelated
                   requests (the admin panel, other drivers' polling) that
                   were simply waiting for a DB connection slot. Keeps the
                   passenger_name/passenger_phone field-name fix (the actual
                   bug this was chasing) and adds back coordinates that the
                   original narrow query dropped, but does NOT recompute the
                   live fare/breakdown on every tick - that's still correctly
                   handled by driver_get_profile's polling (RideController.php),
                   which runs far less often and doesn't hold a connection
                   open between calls.
                   NOTE: does NOT select r.ride_code - this column doesn't
                   exist on this table's actual live schema (confirmed via a
                   1054 Unknown column error that was silently swallowed by
                   the catch block below and surfaced as a bare {"error":"db"}
                   on every single tick, for as long as this line existed).
                   RideController.php treats ride/tracking-code columns as
                   optional/schema-variable elsewhere (checks ride_code,
                   request_code, code, tracking_code, order_code via SHOW
                   COLUMNS before ever relying on one) - this query should
                   follow the same rule rather than hardcoding one. */
                $ar = $pdo->prepare("SELECT r.id, r.status, r.pickup_address, r.dropoff_address,
                    r.pickup_lat, r.pickup_lng, r.dropoff_lat, r.dropoff_lng, r.open_trip,
                    r.distance_km, r.fare_final, u.name AS passenger_name, u.phone AS passenger_phone
                    FROM rides r JOIN users u ON u.id=r.passenger_id
                    WHERE r.driver_id=? AND r.status IN ('accepted','in_progress','arrived') LIMIT 1");
                $ar->execute(array($u['id']));
                $active = $ar->fetch();
                $dp = $pdo->prepare('SELECT lat, lng, vehicle_type FROM ride_drivers WHERE user_id=?');
                $dp->execute(array($u['id']));
                $driver = $dp->fetch();
                $myVtype = ($driver && !empty($driver['vehicle_type'])) ? $driver['vehicle_type'] : 'bajaj';
                $nr = $pdo->prepare('SELECT r.id, r.pickup_lat, r.pickup_lng, r.pickup_address,
                    r.dropoff_address, r.distance_km, r.fare_final, r.expires_at,
                    u.name AS pname FROM rides r JOIN users u ON u.id=r.passenger_id
                    WHERE r.status=\'searching\' AND r.passenger_id!=?
                          AND (LOWER(r.vehicle_type) = LOWER(?) OR r.vehicle_type IS NULL)
                    ORDER BY r.id DESC LIMIT 20');
                $nr->execute(array($u['id'], $myVtype));
                $requests = $nr->fetchAll();

                $nearby = array();
                foreach ($requests as $r) {
                    if ($driver && $driver['lat'] && isset($r['pickup_lat']) && $r['pickup_lat']) {
                        $dkm = 6371 * acos(min(1.0, cos(deg2rad(floatval($driver['lat'])))
                            * cos(deg2rad(floatval($r['pickup_lat'])))
                            * cos(deg2rad(floatval($r['pickup_lng'])) - deg2rad(floatval($driver['lng'])))
                            + sin(deg2rad(floatval($driver['lat']))) * sin(deg2rad(floatval($r['pickup_lat'])))));
                        if ($dkm > $radius) continue;
                        $r['distance_from_driver_km'] = round($dkm, 2);
                    }
                    $nearby[] = $r;
                }
                echo 'data: ' . json_encode(array('requests'=>$nearby,'active_ride'=>$active?:null)) . "\n\n";
            } catch (Exception $e) {
                /* Previously just {"error":"db"} with no detail - made a real
                   exception (e.g. a connection-limit error under load) totally
                   indistinguishable from "no requests right now" on the wire,
                   and impossible to diagnose without server-side log access.
                   Message is safe to expose here: this is an authenticated
                   driver's own stream, not a public endpoint. */
                echo 'data: ' . json_encode(array('error'=>'db','detail'=>$e->getMessage())) . "\n\n";
            }
            @ob_flush(); flush(); sleep(3); $ticks++;
        }
        echo "event: close\ndata: {}\n\n";
        @ob_flush(); flush(); exit;
    }

    /*
       PASSWORD RESET  3-step OTP flow (restored from the orphaned
       backups/2026-06-27_185609_api_r3.php draft, which was never wired
       into boot.php and had a schema bug - it updated a `password` column
       that doesn't exist; the live schema uses `password_hash`).

       Step 1: reset_request (alias: send_reset_otp)  { phone, channel? }
       Step 2: reset_verify  (alias: verify_reset_otp) { phone, otp } -> reset_token
       Step 3: reset_password { phone, new_password, and EITHER reset_token
                                (from step 2) OR otp again }

       reset_password accepts either credential because the two frontend
       flows in this codebase built the same feature independently before
       this was noticed: login.html's actually-wired-up "Forgot password?"
       button re-submits the raw OTP at the final step and never reads
       reset_token, while js/login.js (unreachable dead code as of this
       session - see the removal note there) was written against a
       reset_token-only contract. Rather than pick one and silently break
       whichever UI isn't in front of you right now, this endpoint checks
       whichever credential was actually sent.

       OTP channel, first configured wins: Telegram (TG_BOT_TOKEN) ->
       WhatsApp via CallMeBot (WA_APIKEY/WA_PHONE) -> SMS via Africa's
       Talking (AT_API_KEY) -> dev fallback (OTP returned in the response,
       only when no channel is configured  never in addition to one).
    */
    case 'reset_request': case 'send_reset_otp': {
        $phone = isset($body['phone']) ? preg_replace('/\s+/', '', trim($body['phone'])) : '';
        if (!$phone) fail('Phone number required');
        $phoneCheck = etel_validate_phone($phone);
        if ($phoneCheck === false) fail('Enter a valid phone number', 400);

        $pdo = db();

        $u = $pdo->prepare('SELECT id FROM users WHERE phone=? LIMIT 1');
        $u->execute(array($phone));
        if (!$u->fetch()) {
            // Same response either way  don't let this endpoint be used to
            // enumerate which phone numbers have accounts.
            ok(array('sent' => true, 'message' => 'If this number is registered, an OTP has been sent.'));
        }

        $rl = $pdo->prepare(
            'SELECT COUNT(*) FROM password_resets WHERE phone=? AND created_at > NOW() - INTERVAL 15 MINUTE'
        );
        $rl->execute(array($phone));
        if ((int)$rl->fetchColumn() >= 3) {
            fail('Too many reset requests. Please wait 15 minutes and try again.', 429);
        }

        $otp      = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        $otp_hash = hash('sha256', $otp);
        $expires  = date('Y-m-d H:i:s', time() + 600);

        $pdo->prepare('UPDATE password_resets SET used=1 WHERE phone=? AND used=0')
            ->execute(array($phone));
        $pdo->prepare('INSERT INTO password_resets (phone, otp_hash, expires_at) VALUES (?, ?, ?)')
            ->execute(array($phone, $otp_hash, $expires));

        $otp_msg  = "ETEL Delivery OTP: $otp\nPassword reset code - valid 10 minutes.\nDo not share this code.";
        $sent_via = '';

        // Channel 1: Telegram bot (free) - reuses the EtelID master table for
        // chat_id lookup when reachable, same convention as etel_pay_bridge.php.
        $tg_token = etel_env('TG_BOT_TOKEN', 'CFG_TG_BOT_TOKEN', '');
        if ($tg_token && !$sent_via) {
            $tg_chat_id = '';
            if (!function_exists('etelid_bridge_db')) {
                @require_once __DIR__ . '/etelid_bridge.php';
            }
            if (function_exists('etelid_bridge_db')) {
                try {
                    $eid_cfg = etelid_bridge_db();
                    if ($eid_cfg) {
                        $tq = $eid_cfg->prepare(
                            "SELECT tg_chat_id FROM Etelid_etelid_members
                             WHERE REPLACE(REPLACE(REPLACE(phone,'+',''),' ',''),'-','')
                                 = REPLACE(REPLACE(REPLACE(?,'+',''),' ',''),'-','') LIMIT 1"
                        );
                        $tq->execute(array($phone));
                        $trow = $tq->fetch(PDO::FETCH_ASSOC);
                        if ($trow && !empty($trow['tg_chat_id'])) $tg_chat_id = $trow['tg_chat_id'];
                    }
                } catch (Exception $e) { /* EtelID master not reachable  fall through */ }
            }
            if (!$tg_chat_id) {
                try {
                    $tq = $pdo->prepare('SELECT chat_id FROM tg_chat_ids WHERE phone=? LIMIT 1');
                    $tq->execute(array($phone));
                    $trow = $tq->fetch();
                    if ($trow && !empty($trow['chat_id'])) $tg_chat_id = $trow['chat_id'];
                } catch (Exception $e) { /* table may not exist yet */ }
            }
            if ($tg_chat_id) {
                $ch = curl_init('https://api.telegram.org/bot' . $tg_token . '/sendMessage');
                curl_setopt_array($ch, array(
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => json_encode(array('chat_id' => $tg_chat_id, 'text' => $otp_msg)),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 8,
                    CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
                ));
                $resp = curl_exec($ch); curl_close($ch);
                $tg_resp = $resp ? @json_decode($resp, true) : null;
                if ($tg_resp && !empty($tg_resp['ok'])) $sent_via = 'telegram';
            }
        }

        // Channel 2: WhatsApp via CallMeBot (free tier, sends to one linked number)
        $wa_key   = etel_env('WA_APIKEY', 'CFG_WA_APIKEY', '');
        $wa_phone = etel_env('WA_PHONE',  'CFG_WA_PHONE',  '');
        if ($wa_key && $wa_phone && !$sent_via) {
            $wa_text = urlencode('ETEL OTP for ' . $phone . ': ' . $otp . ' (10 min)');
            $ch = curl_init('https://api.callmebot.com/whatsapp.php?phone=' . urlencode($wa_phone)
                . '&text=' . $wa_text . '&apikey=' . urlencode($wa_key));
            curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8));
            $resp = curl_exec($ch); curl_close($ch);
            if ($resp && stripos($resp, 'Message queued') !== false) $sent_via = 'whatsapp';
        }

        // Channel 3: SMS via Africa's Talking (paid)
        $at_key  = etel_env('AT_API_KEY',  'CFG_AT_API_KEY',  '');
        $at_user = etel_env('AT_USERNAME', 'CFG_AT_USERNAME', 'sandbox');
        if ($at_key && $at_key !== 'your_at_api_key' && !$sent_via) {
            $ch = curl_init('https://api.africastalking.com/version1/messaging');
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_HTTPHEADER     => array(
                    'apiKey: ' . $at_key, 'Accept: application/json',
                    'Content-Type: application/x-www-form-urlencoded',
                ),
                CURLOPT_POSTFIELDS => http_build_query(array(
                    'username' => $at_user, 'to' => $phone, 'message' => $otp_msg, 'from' => 'ETEL',
                )),
            ));
            $resp    = curl_exec($ch); curl_close($ch);
            $at_resp = $resp ? @json_decode($resp, true) : null;
            if ($at_resp && isset($at_resp['SMSMessageData']['Recipients'][0]['status'])
                && $at_resp['SMSMessageData']['Recipients'][0]['status'] === 'Success') {
                $sent_via = 'sms';
            }
        }

        // Dev fallback: only reveal the OTP in the response when NO real
        // channel could deliver it, and only log it server-side otherwise.
        if (!$sent_via) {
            error_log('[ETEL OTP] phone=' . $phone . ' otp=' . $otp . ' (no channel configured)');
        }

        ok(array(
            'sent'    => true,
            'channel' => $sent_via ?: 'none',
            'message' => $sent_via
                ? 'OTP sent via ' . $sent_via . '. Check your messages.'
                : 'Could not send OTP via SMS/Telegram/WhatsApp. Please contact support.',
            'dev_otp' => (!$sent_via && (etel_env('APP_ENV', 'CFG_APP_ENV', 'production') !== 'production')) ? $otp : null,
        ));
        break;
    }

    case 'reset_verify': case 'verify_reset_otp': {
        $phone = isset($body['phone']) ? preg_replace('/\s+/', '', trim($body['phone'])) : '';
        $otp   = isset($body['otp'])   ? preg_replace('/\D/', '', trim($body['otp']))    : '';
        if (!$phone || !$otp) fail('Phone and OTP required');
        if (strlen($otp) !== 6) fail('OTP must be 6 digits');

        $pdo = db();
        $s = $pdo->prepare(
            'SELECT id, otp_hash, attempts FROM password_resets
             WHERE phone=? AND used=0 AND expires_at > NOW() ORDER BY id DESC LIMIT 1'
        );
        $s->execute(array($phone));
        $row = $s->fetch();
        if (!$row) fail('OTP expired or not found. Please request a new one.');

        if ((int)$row['attempts'] >= 5) {
            $pdo->prepare('UPDATE password_resets SET used=1 WHERE id=?')->execute(array($row['id']));
            fail('Too many wrong attempts. Please request a new OTP.');
        }

        if (!hash_equals($row['otp_hash'], hash('sha256', $otp))) {
            $pdo->prepare('UPDATE password_resets SET attempts=attempts+1 WHERE id=?')
                ->execute(array($row['id']));
            $left = 4 - (int)$row['attempts'];
            fail('Incorrect OTP. ' . max(0, $left) . ' attempt(s) remaining.');
        }

        // This step is intentionally read-only (no `used` flag set, no
        // rotation of otp_hash): the two frontends in this codebase reach
        // the final reset_password step differently - one resends the raw
        // OTP, the other is written to carry a reset_token forward instead
        // - so the actual OTP has to still be independently verifiable at
        // that step. reset_password is the single place the OTP row is
        // actually consumed (used=1).
        $reset_token = hash('sha256', 'RESET:' . $row['id'] . ':' . $row['otp_hash']);
        ok(array('reset_token' => $reset_token, 'verified' => true,
                 'message' => 'OTP verified. You may now set a new password.'));
        break;
    }

    case 'reset_password': {
        $phone       = isset($body['phone'])        ? preg_replace('/\s+/', '', trim($body['phone'])) : '';
        $otp         = isset($body['otp'])          ? preg_replace('/\D/', '', trim($body['otp']))     : '';
        $reset_token = isset($body['reset_token'])  ? trim($body['reset_token']) : '';
        $new_pw      = isset($body['new_password']) ? $body['new_password'] : '';

        if (!$phone || (!$otp && !$reset_token) || !$new_pw) {
            fail('Phone, OTP (or reset token), and new password required');
        }
        // Matches the 8-character minimum enforced on registration this
        // session - a reset shouldn't be allowed to set a weaker password
        // than a fresh signup would.
        if (strlen($new_pw) < 8) fail('Password must be at least 8 characters');

        $pdo = db();

        // Accept either credential produced by reset_verify above: the raw
        // OTP (what login.html's live "Forgot password?" flow resends) or
        // the derived reset_token (what a future/alternate frontend can
        // carry forward instead). Whichever the caller sent is checked
        // against the still-unconsumed password_resets row.
        $rows = $pdo->prepare(
            'SELECT id, otp_hash FROM password_resets
             WHERE phone=? AND used=0 AND expires_at > NOW() ORDER BY id DESC LIMIT 5'
        );
        $rows->execute(array($phone));
        $match = null;
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($otp && hash_equals($r['otp_hash'], hash('sha256', $otp))) { $match = $r; break; }
            if ($reset_token && hash_equals(
                hash('sha256', 'RESET:' . $r['id'] . ':' . $r['otp_hash']), $reset_token
            )) { $match = $r; break; }
        }
        if (!$match) fail('Reset session expired or invalid. Please start over.');

        // Bug fix from the orphaned draft: it updated a `password` column
        // that doesn't exist anywhere in the live schema  every reset would
        // have silently failed (rowCount 0) while reporting success up to
        // the point of the fail() below. The live column is `password_hash`.
        $new_hash = password_hash($new_pw, PASSWORD_BCRYPT, array('cost' => 10));
        $upd = $pdo->prepare('UPDATE users SET password_hash=? WHERE phone=? LIMIT 1');
        $upd->execute(array($new_hash, $phone));
        if ($upd->rowCount() === 0) fail('User not found');

        $pdo->prepare('UPDATE password_resets SET used=1 WHERE id=?')->execute(array($match['id']));

        // Also new vs. the orphaned draft: revoke every outstanding bearer
        // token for this account now that the password has changed, using
        // this session's token-revocation mechanism  otherwise a token
        // issued before the reset (e.g. from a device that triggered the
        // reset because it was stolen) would keep working for up to 7 days.
        try {
            $uid_row = $pdo->prepare('SELECT id FROM users WHERE phone=? LIMIT 1');
            $uid_row->execute(array($phone));
            $urow = $uid_row->fetch();
            if ($urow) etel_bump_token_version($pdo, $urow['id']);
        } catch (Exception $e) { /* non-fatal  password is already reset */ }

        try {
            $pdo->prepare('INSERT INTO login_attempts (identifier, attempted_at) VALUES (?, NOW())')
                ->execute(array('PWD_RESET:' . hash('sha256', $phone)));
        } catch (Exception $e) { /* log table may not exist  ignore */ }

        ok(array('message' => 'Password reset successfully. You can now sign in with your new password.'));
        break;
    }

    default:
        fail('Unknown action: ' . $action, 404);
}
