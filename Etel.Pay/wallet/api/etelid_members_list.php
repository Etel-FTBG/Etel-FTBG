<?php
/**
 * EtelPay - members list for the EtelID Members Controller
 * Pure PHP/MySQL . PHP 5.6+ . cPanel
 *
 * Mirrors the auth pattern already used in etelid_kyc.php (outgoing
 * calls to EtelID use X-Etel-Service-Key). This file is the reverse
 * direction: EtelID calls IN here with that same shared key to pull
 * a read-only directory of EtelPay wallet users.
 *
 * Place at: Etel.Pay/wallet/api/etelid_members_list.php
 * Called as: GET/POST https://ethelmarket.com/Etel.Pay/wallet/api/etelid_members_list.php
 * Header:    X-Etel-Service-Key: <same key as config.php ETELID_SERVICE_KEY>
 */

if (ob_get_level()) ob_end_clean();
ob_start();
error_reporting(0);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/config.php';

function eml_header($name) {
    $key = 'HTTP_' . str_replace('-', '_', strtoupper($name));
    if (isset($_SERVER[$key])) return $_SERVER[$key];
    if (function_exists('getallheaders')) {
        $hdrs = getallheaders();
        foreach ($hdrs as $k => $v) {
            if (strcasecmp($k, $name) === 0) return $v;
        }
    }
    return '';
}

function eml_err($message, $code = 400) {
    while (ob_get_level()) { @ob_end_clean(); }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('success' => false, 'message' => $message));
    exit;
}

// config.php ships ETELID_SERVICE_KEY as the literal string 'CHANGE_ME' by
// default - a truthy value, so a plain !ETELID_SERVICE_KEY check silently
// accepts a forgotten override and then compares against a known,
// guessable key below. Reject the placeholder explicitly, same as empty.
if (!defined('ETELID_SERVICE_KEY') || !ETELID_SERVICE_KEY || strcasecmp(trim((string) ETELID_SERVICE_KEY), 'CHANGE_ME') === 0) {
    eml_err('Service key not configured on EtelPay.', 500);
}

$provided = eml_header('X-Etel-Service-Key');
if ( ! $provided && isset( $_GET['key'] ) ) {
    /* Fallback: some shared-hosting FastCGI configs strip custom
       X-* headers before PHP ever sees them. A URL parameter always
       survives regardless of host header-passthrough quirks. */
    $provided = $_GET['key'];
}
$expected = trim( (string) ETELID_SERVICE_KEY );
$provided = trim( (string) $provided );
$match = function_exists('hash_equals') ? hash_equals($expected, $provided) : ($provided === $expected);
if (!$match) {
    /* Temporary debug fingerprint so we can see WITHOUT exposing the
       actual key whether the two sides differ in length/prefix, e.g.
       a stray newline or truncated copy-paste. Safe to remove later. */
    $dbg = array(
        'expected_len'    => strlen( $expected ),
        'provided_len'    => strlen( $provided ),
        'expected_prefix' => substr( $expected, 0, 6 ),
        'provided_prefix' => substr( $provided, 0, 6 ),
        'expected_suffix' => substr( $expected, -6 ),
        'provided_suffix' => substr( $provided, -6 ),
    );
    eml_err('Invalid or missing service key. debug=' . json_encode($dbg), 401);
}

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
    eml_err('Database connection failed.', 503);
}

/* Only select columns that are guaranteed to exist; the etelid_* bridge
   columns are added lazily by etelid_kyc.php on first sync, so probe first. */
$has_etelid_cols = false;
try {
    $pdo->query('SELECT etelid_fin FROM ' . EP_TBL_USERS . ' LIMIT 0');
    $has_etelid_cols = true;
} catch (Exception $e) {
    $has_etelid_cols = false;
}

$cols = 'id, full_name, phone, email, status, kyc_status, balance_etb, balance_usd, is_admin, created_at';
if ($has_etelid_cols) {
    $cols .= ', etelid_fin, etelid_kyc_level';
}

$limit = isset($_GET['limit']) ? max(1, min(500, (int) $_GET['limit'])) : 200;

try {
    $stmt = $pdo->prepare('SELECT ' . $cols . ' FROM ' . EP_TBL_USERS . ' ORDER BY id DESC LIMIT ' . (int) $limit);
    $stmt->execute();
    $rows = $stmt->fetchAll();
} catch (Exception $e) {
    eml_err('Query failed: ' . $e->getMessage(), 500);
}

$out = array();
foreach ($rows as $r) {
    $out[] = array(
        'id'         => (int) $r['id'],
        'name'       => isset($r['full_name']) ? $r['full_name'] : '',
        'phone'      => isset($r['phone']) ? $r['phone'] : '',
        'email'      => isset($r['email']) ? $r['email'] : '',
        'status'     => isset($r['status']) ? $r['status'] : '',
        'kyc_status' => isset($r['kyc_status']) ? $r['kyc_status'] : '',
        'balance_etb'=> isset($r['balance_etb']) ? (float) $r['balance_etb'] : 0,
        'balance_usd'=> isset($r['balance_usd']) ? (float) $r['balance_usd'] : 0,
        'is_admin'   => !empty($r['is_admin']),
        'fayda_fin'  => isset($r['etelid_fin']) ? $r['etelid_fin'] : '',
        'kyc_level'  => isset($r['etelid_kyc_level']) ? (int) $r['etelid_kyc_level'] : 0,
        'created'    => isset($r['created_at']) ? $r['created_at'] : '',
    );
}

while (ob_get_level()) { @ob_end_clean(); }
echo json_encode(array('success' => true, 'members' => $out, 'total' => count($out)));
exit;
