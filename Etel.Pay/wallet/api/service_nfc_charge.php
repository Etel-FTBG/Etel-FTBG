<?php
/**
 * EtelPay - service-to-service NFC tap-to-pay charge.
 *
 * Called by EtelID (class-etelid-nfc.php) after TWO separate proofs of
 * consent, neither of which this endpoint re-derives on its own:
 *   1. The receiver (person collecting payment) already verified THEIR
 *      OWN EtelID password, locally, on EtelID's side.
 *   2. This request also carries the PAYER's own EtelPay PIN, entered
 *      by the payer themselves on this same device, verified HERE
 *      against their real pin_hash before a single Birr moves.
 * A bare NFC tap alone can never move money - it only identifies whose
 * wallet is being charged. Same shared ETELID_SERVICE_KEY every other
 * EtelPay<->EtelID service call already uses.
 *
 * Place at: Etel.Pay/wallet/api/service_nfc_charge.php
 * Called as: POST https://ethelmarket.com/Etel.Pay/wallet/api/service_nfc_charge.php
 *            body: {"receiver_phone":"+251...","payer_phone":"+251...",
 *                    "payer_pin":"1234","amount":100,"note":"..."}
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

if (!defined('EP_TBL_TX')) define('EP_TBL_TX', EP_TBL_PREFIX . 'ep_transactions');

function snc_header($name) {
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

function snc_err($message, $code = 400) {
    while (ob_get_level()) { @ob_end_clean(); }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('success' => false, 'message' => $message));
    exit;
}

function snc_ok($data) {
    while (ob_get_level()) { @ob_end_clean(); }
    echo json_encode(array('success' => true, 'data' => $data));
    exit;
}

if (!defined('ETELID_SERVICE_KEY') || !ETELID_SERVICE_KEY || strcasecmp(trim((string) ETELID_SERVICE_KEY), 'CHANGE_ME') === 0) {
    snc_err('Service key not configured on EtelPay.', 500);
}
$provided = snc_header('X-Etel-Service-Key');
if (!$provided && isset($_GET['key'])) { $provided = $_GET['key']; }
$expected = trim((string) ETELID_SERVICE_KEY);
$provided = trim((string) $provided);
$match = function_exists('hash_equals') ? hash_equals($expected, $provided) : ($provided === $expected);
if (!$match) snc_err('Invalid or missing service key.', 401);

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) $body = $_POST;

$receiverPhone = isset($body['receiver_phone']) ? trim($body['receiver_phone']) : '';
$payerPhone    = isset($body['payer_phone'])    ? trim($body['payer_phone'])    : '';
$payerPin      = isset($body['payer_pin'])      ? (string) $body['payer_pin']   : '';
$amount        = isset($body['amount'])         ? (float) $body['amount']       : 0;
$note          = isset($body['note'])           ? trim((string) $body['note'])  : '';
$ip            = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';

if (!$receiverPhone || !$payerPhone || !$payerPin) snc_err('Missing required fields.', 400);
if ($amount <= 0) snc_err('Amount must be greater than zero.', 400);

$payerLast9    = substr(preg_replace('/\D/', '', $payerPhone), -9);
$receiverLast9 = substr(preg_replace('/\D/', '', $receiverPhone), -9);
if (!$payerLast9 || !$receiverLast9) snc_err('Invalid phone number.', 400);
if ($payerLast9 === $receiverLast9) snc_err('Cannot charge your own wallet.', 400);

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
    snc_err('Database connection failed.', 503);
}

// Same login_attempts table ep_login already uses (v2_verify.php) -
// reused here for PIN attempts, keyed by the PAYER's phone so a wrong
// guess against one wallet can't be retried indefinitely.
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

$rl = $pdo->prepare('SELECT COUNT(*) FROM ' . EP_TBL_PREFIX . 'ep_login_attempts WHERE phone = ? AND created_at > (NOW() - INTERVAL 15 MINUTE)');
$rl->execute(array($payerPhone));
if ((int) $rl->fetchColumn() >= 5) {
    snc_err('Too many incorrect PIN attempts. Please wait 15 minutes and try again.', 429);
}

try {
    $rs = $pdo->prepare('SELECT id, phone, pin_hash, status, balance_etb FROM ' . EP_TBL_USERS . ' WHERE RIGHT(phone, 9) = ? LIMIT 1');
    $rs->execute(array($receiverLast9));
    $receiver = $rs->fetch();

    $ps = $pdo->prepare('SELECT id, phone, pin_hash, status, balance_etb FROM ' . EP_TBL_USERS . ' WHERE RIGHT(phone, 9) = ? LIMIT 1');
    $ps->execute(array($payerLast9));
    $payer = $ps->fetch();
} catch (Exception $e) {
    snc_err('Query failed.', 500);
}

if (!$receiver) snc_err('The person collecting payment has no EtelPay wallet.', 404);
if ($receiver['status'] !== 'active') snc_err('The receiving wallet is not active.', 403);
if (!$payer) snc_err('This card has no EtelPay wallet.', 404);
if ($payer['status'] !== 'active') snc_err('This wallet is not active.', 403);

if (empty($payer['pin_hash']) || !password_verify($payerPin, $payer['pin_hash'])) {
    $pdo->prepare('INSERT INTO ' . EP_TBL_PREFIX . 'ep_login_attempts (phone, ip) VALUES (?, ?)')->execute(array($payerPhone, $ip));
    snc_err('Incorrect PIN.', 401);
}

if ((float) $payer['balance_etb'] < $amount) snc_err('Insufficient balance.', 402);

if (function_exists('random_bytes')) $tx_ref = 'NFC' . strtoupper(bin2hex(random_bytes(6)));
else $tx_ref = 'NFC' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 12));

$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE ' . EP_TBL_USERS . ' SET balance_etb = balance_etb - ? WHERE id = ? AND balance_etb >= ?')
        ->execute(array($amount, $payer['id'], $amount));
    // Same concurrent-debit guard ep_send/ep_qr_process already use -
    // without it, two near-simultaneous charges could both pass the
    // balance pre-check above.
    if ($pdo->query('SELECT ROW_COUNT()')->fetchColumn() == 0) {
        throw new Exception('Insufficient balance (concurrent change)');
    }
    $pdo->prepare('UPDATE ' . EP_TBL_USERS . ' SET balance_etb = balance_etb + ? WHERE id = ?')
        ->execute(array($amount, $receiver['id']));
    $pdo->prepare(
        'INSERT INTO ' . EP_TBL_TX . ' (tx_ref, type, sender_ep_id, receiver_ep_id, amount, currency, note, status, created_at)
         VALUES (?, "nfc_charge", ?, ?, ?, "ETB", ?, "completed", NOW())'
    )->execute(array($tx_ref, $payer['id'], $receiver['id'], $amount, $note !== '' ? $note : 'NFC tap-to-pay'));
    $pdo->commit();
} catch (Exception $ex) {
    $pdo->rollBack();
    snc_err('Payment failed: ' . $ex->getMessage(), 500);
}

snc_ok(array(
    'tx_ref'  => $tx_ref,
    'amount'  => $amount,
    'currency' => 'ETB',
));
