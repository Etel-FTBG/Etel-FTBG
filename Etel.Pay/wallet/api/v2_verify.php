<?php
/**
 * ኢE Pay — Standalone Wallet API v1.0
 * Pure PHP/MySQL · PHP 5.6+ · cPanel shared hosting
 * Mirrors the WP REST endpoints so the same JS frontend works
 * against BOTH this file and the WP plugin.
 *
 * Usage:  POST api/api.php   { "action": "ep_login", ... }
 *         GET  api/api.php?action=ep_ping
 */

/* ── Fatal guard — always returns JSON ──────────────────────── */
if (ob_get_level()) ob_end_clean();
ob_start();
error_reporting(0);
ini_set('display_errors', '0');

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
            'data'    => array('message' => 'Server error: ' . $e['message'])
        ));
    } else {
        ob_end_flush();
    }
});

/* ── Headers ─────────────────────────────────────────────────── */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://ethelmarket.com');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('X-Content-Type-Options: nosniff');
header('X-App-Version: ' . (defined('EP_VER') ? EP_VER : '1.0.0'));

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

/* ── Config ──────────────────────────────────────────────────── */
$_cfg = __DIR__ . '/config.php';
if (file_exists($_cfg)) require_once $_cfg;

/* ── Hardened .env loader ────────────────────────────────────
   Handles: BOM, Amharic/Ethiopic comments, quoted values,
   inline comments, blank lines. Never relies on putenv/getenv
   round-trips (blocked on some cPanel php-fpm pools).
   Priority: .env array > getenv() > CFG_* constant > default.
   ─────────────────────────────────────────────────────────── */
$_ep_env      = array();
$_ep_env_file = __DIR__ . '/.env';
if (file_exists($_ep_env_file)) {
    $lines = @file($_ep_env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines) {
        foreach ($lines as $line) {
            $line    = ltrim($line, "\xEF\xBB\xBF");   // strip BOM on first line
            $trimmed = ltrim($line);
            if ($trimmed === '' || $trimmed[0] === '#') continue;  // skip comments (any charset)
            if (strpos($trimmed, '=') === false) continue;
            $parts = explode('=', $trimmed, 2);
            $k     = trim($parts[0]);
            $v     = isset($parts[1]) ? trim($parts[1]) : '';
            // Strip inline comments on unquoted values
            if ($v !== '' && $v[0] !== '"' && $v[0] !== "'") {
                $hash = strpos($v, ' #');
                if ($hash !== false) $v = rtrim(substr($v, 0, $hash));
            }
            // Strip surrounding quotes
            if (strlen($v) >= 2 &&
                (($v[0] === '"' && $v[strlen($v)-1] === '"') ||
                 ($v[0] === "'"  && $v[strlen($v)-1] === "'"))) {
                $v = substr($v, 1, strlen($v) - 2);
            }
            // Only store valid KEY names (uppercase + digits + underscore)
            if ($k !== '' && preg_match('/^[A-Z][A-Z0-9_]*$/', $k)) {
                $_ep_env[$k] = $v;
            }
        }
    }
}

/* Helper: .env array > getenv > CFG_ constant > default.
   Never relies on putenv/getenv round-trips. */
function ep_env($key, $cfg_const, $default = '') {
    global $_ep_env;
    if (isset($_ep_env[$key]) && $_ep_env[$key] !== '') return $_ep_env[$key];
    $g = getenv($key);
    if ($g !== false && $g !== '') return $g;
    if (defined($cfg_const) && constant($cfg_const) !== '') return constant($cfg_const);
    return $default;
}

/* Placeholder values (config.php ships these as literal defaults so a
   fresh checkout is at least syntactically valid) must never be treated
   as "configured" - they're truthy non-empty strings, so a bare !$value
   check silently accepts them. Every !EP_*_PASS/!EP_SECRET-style guard
   below must go through this instead of a plain empty check. */
function ep_is_placeholder($v) {
    if ($v === '' || $v === null) return true;
    $v = strtolower(trim((string)$v));
    return in_array($v, array('change_me', 'changeme', 'change-me'), true);
}

/* ── Runtime constants (priority: .env > getenv > CFG_ > default) ── */
if (!defined('EP_DB_HOST'))    define('EP_DB_HOST',    ep_env('DB_HOST',      'EP_CFG_DB_HOST',    'localhost'));
if (!defined('EP_DB_NAME'))    define('EP_DB_NAME',    ep_env('DB_NAME',      'EP_CFG_DB_NAME',    ''));
if (!defined('EP_DB_USER'))    define('EP_DB_USER',    ep_env('DB_USER',      'EP_CFG_DB_USER',    ''));
if (!defined('EP_DB_PASS'))    define('EP_DB_PASS',    ep_env('DB_PASS',      'EP_CFG_DB_PASS',    ''));
if (!defined('EP_SECRET'))     define('EP_SECRET',     ep_env('EP_SECRET',    'EP_CFG_SECRET',     ''));
if (!defined('EP_TBL_PREFIX')) define('EP_TBL_PREFIX', ep_env('EP_TBL_PREFIX','EP_CFG_TBL_PREFIX', 'Etelpay_'));

/* -- Guard: refuse to serve without a real DB password --------
   Prevents silent startup with empty OR still-placeholder credentials. */
if (ep_is_placeholder(EP_DB_PASS)) {
    while (ob_get_level()) { @ob_end_clean(); }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(array(
        'success' => false,
        'data'    => array('message' => 'ኢE Pay: Database not configured. Set DB_PASS in api/.env'),
        'diag'    => array(
            'env_file_present' => file_exists($_ep_env_file),
            'db_name'          => EP_DB_NAME  ?: '(empty)',
            'db_user'          => EP_DB_USER  ?: '(empty)',
        )
    ));
    exit;
}

/* ── Auto-generate EP_SECRET from server fingerprint if missing ──
   Allows the wallet to operate without explicit config while
   ensuring the secret is unique per server (not 'changeme').
   Also triggers when EP_SECRET is still the literal placeholder from
   config.php's shipped default - a known, guessable value is no safer
   than an empty one for something used in hash_equals() auth checks
   throughout ep_r1.php.
   ALWAYS set EP_SECRET explicitly in .env for production.   */
if (ep_is_placeholder(EP_SECRET)) {
    $auto = hash('sha256', php_uname('n') . EP_DB_NAME . EP_DB_USER . 'etelpay2026');
    define('EP_SECRET_FINAL', $auto);
} else {
    define('EP_SECRET_FINAL', EP_SECRET);
}
if (!defined('EP_SESSION_TTL')) define('EP_SESSION_TTL', 604800);
if (!defined('EP_DAILY_LIMIT')) define('EP_DAILY_LIMIT', 0);
if (!defined('EP_VER'))         define('EP_VER', '1.1.0');  /* bump on every deploy */

// Table names
if (!defined('EP_TBL_USERS'))    define('EP_TBL_USERS',    EP_TBL_PREFIX . 'ep_users');
if (!defined('EP_TBL_TX'))       define('EP_TBL_TX',       EP_TBL_PREFIX . 'ep_transactions');
if (!defined('EP_TBL_SESSIONS')) define('EP_TBL_SESSIONS', EP_TBL_PREFIX . 'ep_sessions');
if (!defined('EP_TBL_QR'))       define('EP_TBL_QR',       EP_TBL_PREFIX . 'ep_qr_codes');
if (!defined('EP_TBL_RATES'))    define('EP_TBL_RATES',    EP_TBL_PREFIX . 'ep_rates');
if (!defined('EP_TBL_UPDLOG'))   define('EP_TBL_UPDLOG',   EP_TBL_PREFIX . 'ep_updater_logs');
if (!defined('EP_TBL_SETTINGS'))  define('EP_TBL_SETTINGS',  EP_TBL_PREFIX . 'ep_settings');
if (!defined('EP_TBL_TOPUP'))     define('EP_TBL_TOPUP',     EP_TBL_PREFIX . 'ep_topup_requests');
if (!defined('EP_TBL_PAY_LINKS')) define('EP_TBL_PAY_LINKS', EP_TBL_PREFIX . 'ep_payment_links');
if (!defined('EP_TBL_API_KEYS'))  define('EP_TBL_API_KEYS',  EP_TBL_PREFIX . 'ep_api_keys');
if (!defined('EP_TBL_KYC'))       define('EP_TBL_KYC',       EP_TBL_PREFIX . 'ep_kyc_docs');
if (!defined('EP_TBL_DISPUTES'))  define('EP_TBL_DISPUTES',  EP_TBL_PREFIX . 'ep_disputes');

/* ── Timezone ────────────────────────────────────────────────── */
date_default_timezone_set(defined('EP_TIMEZONE') ? EP_TIMEZONE : 'Africa/Addis_Ababa');

/* ── DB connection ───────────────────────────────────────────── */
function ep_db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    try {
        $pdo = new PDO(
            'mysql:host=' . EP_DB_HOST . ';dbname=' . EP_DB_NAME . ';charset=' . (defined('EP_DB_CHARSET') ? EP_DB_CHARSET : 'utf8mb4'),
            EP_DB_USER, EP_DB_PASS,
            array(
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            )
        );
    } catch (Exception $ex) {
        ep_err('Database connection failed', 503);
    }
    return $pdo;
}

/* -- Login brute-force lockout ---------------------------------
   ep_login (a 4-digit PIN, per the audit) had zero attempt tracking
   or lockout - brute-forceable in minutes. Self-healing log table,
   same convention as EP_TBL_UPDLOG above. */
function ep_ensure_login_attempts_table($pdo) {
    static $done = false;
    if ($done) return;
    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . EP_TBL_PREFIX . 'ep_login_attempts (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        phone VARCHAR(32) NOT NULL DEFAULT \'\',
        ip VARCHAR(45) NOT NULL DEFAULT \'\',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_phone (phone),
        KEY idx_ip (ip),
        KEY idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $done = true;
}
function ep_record_failed_login($phone, $ip) {
    $pdo = ep_db();
    ep_ensure_login_attempts_table($pdo);
    $pdo->prepare('INSERT INTO ' . EP_TBL_PREFIX . 'ep_login_attempts (phone, ip) VALUES (?, ?)')
        ->execute(array($phone, $ip));
}
function ep_login_rate_limited($phone, $ip) {
    $pdo = ep_db();
    ep_ensure_login_attempts_table($pdo);
    // Per-account: 5 failed attempts in 15 minutes. Per-IP: 20/hour, since
    // one IP hammering many different phone numbers should also be capped.
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM ' . EP_TBL_PREFIX . 'ep_login_attempts
         WHERE phone = ? AND created_at > (NOW() - INTERVAL 15 MINUTE)'
    );
    $stmt->execute(array($phone));
    if ((int)$stmt->fetchColumn() >= 5) return true;

    if ($ip !== '') {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM ' . EP_TBL_PREFIX . 'ep_login_attempts
             WHERE ip = ? AND created_at > (NOW() - INTERVAL 1 HOUR)'
        );
        $stmt->execute(array($ip));
        if ((int)$stmt->fetchColumn() >= 20) return true;
    }
    return false;
}

/* ── Response helpers ────────────────────────────────────────── */
function ep_ok($data = null, $message = '') {
    echo json_encode(array('success' => true, 'data' => $data, 'message' => $message, 'v' => EP_VER));
    exit;
}
function ep_err($message, $code = 400) {
    http_response_code($code);
    echo json_encode(array('success' => false, 'data' => array('message' => $message), 'v' => EP_VER));
    exit;
}

/* ── Input ───────────────────────────────────────────────────── */
$_raw    = file_get_contents('php://input');
$_body   = $_raw ? json_decode($_raw, true) : array();
if (!is_array($_body)) $_body = array();

function in_str($key, $default = '') {
    global $_body;
    $v = isset($_body[$key]) ? $_body[$key] : (isset($_GET[$key]) ? $_GET[$key] : $default);
    return trim((string)$v);
}
function in_float($key, $default = 0.0) {
    global $_body;
    $v = isset($_body[$key]) ? $_body[$key] : (isset($_GET[$key]) ? $_GET[$key] : $default);
    return (float)$v;
}
function in_int($key, $default = 0) {
    global $_body;
    $v = isset($_body[$key]) ? $_body[$key] : (isset($_GET[$key]) ? $_GET[$key] : $default);
    return (int)$v;
}

/* Ethiopian phone normalization -- all stored phones use +251XXXXXXXXX.
   Every lookup-by-phone (login, send receiver, forgot-pin) must run
   input through this first, or a locally-formatted "09..." number
   silently fails to match the "+251..." row already in the DB. */
function ep_normalize_phone($p) {
    $p = preg_replace('/\s+/', '', (string)$p);
    if (preg_match('/^09\d{8}$/', $p))   return '+251' . substr($p, 1);
    if (preg_match('/^2519\d{8}$/', $p)) return '+' . $p;
    if (preg_match('/^9\d{8}$/', $p))    return '+251' . $p;
    return $p;
}

$action = in_str('action');
if (!$action) $action = in_str('ep_action');
if (!$action) $action = isset($_GET['action']) ? trim($_GET['action']) : '';

/* ── Token auth ──────────────────────────────────────────────── */
function ep_get_bearer() {
    $h = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    if (!$h && function_exists('getallheaders')) {
        $hh = getallheaders();
        $h  = isset($hh['Authorization']) ? $hh['Authorization'] : '';
    }
    // Also accept token in body
    global $_body;
    if (!$h && isset($_body['token'])) return trim($_body['token']);
    return (strpos($h, 'Bearer ') === 0) ? trim(substr($h, 7)) : '';
}

// Idle timeout in addition to (not instead of) the 7-day hard EP_SESSION_TTL
// cap: a session unused for this long is treated as expired even if it's
// still within its 7-day window, matching EtelID's own 8-hour SSO idle
// cutoff for a wallet app that moves real money.
define('EP_SESSION_IDLE_SECONDS', 28800);

function ep_ensure_session_activity_column($pdo) {
    // Same lazy check-and-add pattern already used elsewhere in this
    // codebase (see etelid_kyc.php's etelid_fin/etelid_kyc_level columns)
    // rather than a migration file, since this system has no migration
    // runner.
    try {
        $pdo->query('SELECT last_active_at FROM ' . EP_TBL_SESSIONS . ' LIMIT 0');
    } catch (Exception $e) {
        $pdo->exec('ALTER TABLE ' . EP_TBL_SESSIONS . ' ADD COLUMN last_active_at DATETIME DEFAULT CURRENT_TIMESTAMP');
    }
}

function ep_auth() {
    $token = ep_get_bearer();
    if (!$token) ep_err('Unauthorized — no token', 401);
    $pdo  = ep_db();
    ep_ensure_session_activity_column($pdo);
    $stmt = $pdo->prepare('SELECT s.ep_user_id, s.last_active_at, u.phone, u.full_name, u.status, u.kyc_status, u.balance_etb, u.balance_usd, u.avatar_url, u.is_admin FROM ' . EP_TBL_SESSIONS . ' s JOIN ' . EP_TBL_USERS . ' u ON s.ep_user_id = u.id WHERE s.token = ? AND s.expires_at > NOW() LIMIT 1');
    $stmt->execute(array($token));
    $row = $stmt->fetch();
    if (!$row) ep_err('Session expired or invalid', 401);
    if ($row['status'] !== 'active') ep_err('Account suspended', 403);
    $lastActive = $row['last_active_at'] ? strtotime($row['last_active_at']) : 0;
    if ($lastActive && (time() - $lastActive) > EP_SESSION_IDLE_SECONDS) {
        // Idle too long - revoke this session outright (not just reject
        // this one request) so the next login issues a fresh token rather
        // than leaving a stale, still-technically-unexpired row behind.
        $pdo->prepare('DELETE FROM ' . EP_TBL_SESSIONS . ' WHERE token = ?')->execute(array($token));
        ep_err('Session expired due to inactivity - please log in again', 401);
    }
    $pdo->prepare('UPDATE ' . EP_TBL_SESSIONS . ' SET last_active_at = NOW() WHERE token = ?')->execute(array($token));
    return $row;
}

function ep_auth_id() {
    $u = ep_auth();
    return (int)$u['ep_user_id'];
}

/* -- Merchant API key auth --------------------------------------
   ep_apikey_create/list/revoke (ep_r3.php) generate and store
   pub_key/secret_key/permissions, but nothing previously read or
   checked them anywhere - a merchant restricting or revoking a key
   got no actual behavior change. This wires real auth + permission
   enforcement for actions that opt into accepting it as an
   alternative to a logged-in session token. */
function ep_apikey_lookup($required_permission = null) {
    $pub = isset($_SERVER['HTTP_X_API_KEY'])    ? trim($_SERVER['HTTP_X_API_KEY'])    : (isset($_GET['api_key'])    ? trim($_GET['api_key'])    : '');
    $sec = isset($_SERVER['HTTP_X_API_SECRET']) ? trim($_SERVER['HTTP_X_API_SECRET']) : (isset($_GET['api_secret']) ? trim($_GET['api_secret']) : '');
    if ($pub === '' || $sec === '') return null;
    $pdo  = ep_db();
    $stmt = $pdo->prepare('SELECT * FROM ' . EP_TBL_API_KEYS . ' WHERE pub_key = ? AND status = "active" LIMIT 1');
    $stmt->execute(array($pub));
    $row = $stmt->fetch();
    if (!$row || !hash_equals((string)$row['secret_key'], $sec)) return null;
    if ($required_permission !== null) {
        $perms = ',' . str_replace(' ', '', (string)$row['permissions']) . ',';
        if (strpos($perms, ',' . $required_permission . ',') === false) return null;
    }
    $pdo->prepare('UPDATE ' . EP_TBL_API_KEYS . ' SET last_used = NOW() WHERE id = ?')->execute(array($row['id']));
    return (int)$row['user_id'];
}
function ep_auth_or_apikey($required_permission = null) {
    $uid = ep_apikey_lookup($required_permission);
    if ($uid !== null) {
        $pdo  = ep_db();
        $stmt = $pdo->prepare('SELECT id AS ep_user_id, phone, full_name, status, kyc_status, balance_etb, balance_usd, avatar_url, is_admin FROM ' . EP_TBL_USERS . ' WHERE id = ? LIMIT 1');
        $stmt->execute(array($uid));
        $row = $stmt->fetch();
        if (!$row) ep_err('API key owner not found', 401);
        if ($row['status'] !== 'active') ep_err('Account suspended', 403);
        return $row;
    }
    return ep_auth();
}
function ep_auth_or_apikey_id($required_permission = null) {
    $u = ep_auth_or_apikey($required_permission);
    return (int)$u['ep_user_id'];
}

/* ── Generate TX ref ─────────────────────────────────────────── */
function ep_gen_ref() {
    $pdo = ep_db();
    do {
        $ref = 'EP-' . strtoupper(substr(bin2hex(openssl_random_pseudo_bytes(6)), 0, 12));
        $s   = $pdo->prepare('SELECT id FROM ' . EP_TBL_TX . ' WHERE tx_ref = ? LIMIT 1');
        $s->execute(array($ref));
    } while ($s->fetch());
    return $ref;
}

/* ══════════════════════════════════════════════════════════════
   ACTION ROUTER
   ══════════════════════════════════════════════════════════════ */

/* ── Load route handlers ─────────────────────────────────────── */
require_once __DIR__ . '/etelid_kyc.php';   // EtelID bridge (must load before ep_r3)
require_once __DIR__ . '/chapa_lib.php';
require_once __DIR__ . '/ep_r1.php';
require_once __DIR__ . '/ep_r2.php';
require_once __DIR__ . '/ep_r3.php';

/* ── Dispatch action ─────────────────────────────────────────── */
if (!ep_route_part1($action) && !ep_route_part2($action) && !ep_route_part3($action)) {
    ep_err('Unknown action: ' . htmlspecialchars($action), 404);
}
