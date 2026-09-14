<?php
/**
 * EtelPay - single member detail for the EtelID Members Controller
 * Returns transaction history + KYC document status/images for one
 * EtelPay wallet user. Pure PHP/MySQL . PHP 5.6+ . cPanel
 *
 * Place at: Etel.Pay/wallet/api/etelid_member_detail.php
 * Called as: GET https://ethelmarket.com/Etel.Pay/wallet/api/etelid_member_detail.php?id=123
 * Header:    X-Etel-Service-Key: <same key as config.php ETELID_SERVICE_KEY>
 *            (also accepted as ?key=... if the header gets stripped by the host)
 */

if (ob_get_level()) ob_end_clean();
ob_start();
error_reporting(0);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/config.php';

if (!defined('EP_TBL_TX'))  define('EP_TBL_TX',  EP_TBL_PREFIX . 'ep_transactions');
if (!defined('EP_TBL_KYC')) define('EP_TBL_KYC', EP_TBL_PREFIX . 'ep_kyc_docs');

function emd_header($name) {
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

function emd_err($message, $code = 400) {
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
    emd_err('Service key not configured on EtelPay.', 500);
}

$provided = emd_header('X-Etel-Service-Key');
if (!$provided && isset($_GET['key'])) { $provided = $_GET['key']; }
$expected = trim((string) ETELID_SERVICE_KEY);
$provided = trim((string) $provided);
$match = function_exists('hash_equals') ? hash_equals($expected, $provided) : ($provided === $expected);
if (!$match) {
    emd_err('Invalid or missing service key.', 401);
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if (!$id) emd_err('Missing id.', 400);

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
    emd_err('Database connection failed.', 503);
}

try {
    $u = $pdo->prepare('SELECT id, full_name, phone, email, status, kyc_status, balance_etb, balance_usd, is_admin, created_at FROM ' . EP_TBL_USERS . ' WHERE id = ?');
    $u->execute(array($id));
    $user = $u->fetch();
    if (!$user) emd_err('User not found.', 404);

    $tx = $pdo->prepare(
        'SELECT tx_ref, type, sender_ep_id, receiver_ep_id, amount, currency, note, status, created_at
         FROM ' . EP_TBL_TX . '
         WHERE sender_ep_id = ? OR receiver_ep_id = ?
         ORDER BY id DESC LIMIT 20'
    );
    $tx->execute(array($id, $id));
    $transactions = $tx->fetchAll();

    $kyc = null;
    try {
        $k = $pdo->prepare('SELECT id, doc_type, doc_front, doc_back, selfie, status, submitted_at, reviewer_note, reviewed_at FROM ' . EP_TBL_KYC . ' WHERE user_id = ? ORDER BY id DESC LIMIT 1');
        $k->execute(array($id));
        $kyc = $k->fetch();
    } catch (Exception $e) {
        $kyc = null;
    }
} catch (Exception $e) {
    emd_err('Query failed: ' . $e->getMessage(), 500);
}

while (ob_get_level()) { @ob_end_clean(); }
echo json_encode(array(
    'success'      => true,
    'user'         => $user,
    'transactions' => $transactions,
    'kyc'          => $kyc ?: null,
));
exit;
