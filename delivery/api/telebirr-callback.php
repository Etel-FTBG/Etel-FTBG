<?php
/**
 * ETEL Delivery  Telebirr Webhook Callback
 * Telebirr calls this URL when payment status changes
 */

/* Load credentials: keys saved in Admin first, then .env (same bulletproof reader as api.php) */
if (!defined('APP_VER')) define('APP_VER', 'telebirr-callback'); // satisfies the lib/ direct-access guard
require_once __DIR__ . '/config-etb.php';
$_cb_env = array();
$_cb_env_file = __DIR__ . '/.env';
if (file_exists($_cb_env_file)) {
    $lines = @file($_cb_env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines) foreach ($lines as $line) {
        $line = ltrim($line, "\xEF\xBB\xBF");
        $t = ltrim($line);
        if ($t === '' || $t[0] === '#' || strpos($t,'=') === false) continue;
        $p = explode('=', $t, 2);
        $k = trim($p[0]); $v = isset($p[1]) ? trim($p[1]) : '';
        if ($k !== '' && preg_match('/^[A-Z][A-Z0-9_]*$/', $k)) $_cb_env[$k] = $v;
    }
}
function cb_env($key, $default = '') {
    global $_cb_env;
    if (isset($_cb_env[$key]) && $_cb_env[$key] !== '') return $_cb_env[$key];
    $g = getenv($key); if ($g !== false && $g !== '') return $g;
    return $default;
}
if (!defined('DB_HOST')) define('DB_HOST', cb_env('DB_HOST', 'localhost'));
if (!defined('DB_NAME')) define('DB_NAME', cb_env('DB_NAME', 'etel_delivery'));
if (!defined('DB_USER')) define('DB_USER', cb_env('DB_USER', ''));
if (!defined('DB_PASS')) define('DB_PASS', cb_env('DB_PASS', ''));

header('Content-Type: application/json');

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
              PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(array('error' => 'DB connection failed'));
    exit;
}

$payload = file_get_contents('php://input');
$data    = json_decode($payload, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(array('error' => 'Invalid payload'));
    exit;
}

// With no secret configured the HMAC below would be computed over an empty key,
// which anyone can reproduce - refuse to process callbacks until it is set.
if (!defined('TELEBIRR_SECRET_KEY') || (string)TELEBIRR_SECRET_KEY === '' || TELEBIRR_SECRET_KEY === 'CHANGE_ME') {
    http_response_code(503);
    error_log('Telebirr callback: rejected, TELEBIRR_SECRET_KEY is not configured');
    echo json_encode(array('error' => 'Not configured'));
    exit;
}

// Verify HMAC signature - REQUIRED. A missing header must be rejected, not skipped.
$signature    = isset($_SERVER['HTTP_X_TELEBIRR_SIGNATURE']) ? $_SERVER['HTTP_X_TELEBIRR_SIGNATURE'] : '';
$expectedSig  = hash_hmac('sha256', $payload, TELEBIRR_SECRET_KEY);
if ($signature === '' || !hash_equals($expectedSig, $signature)) {
    http_response_code(401);
    error_log('Telebirr callback: rejected, missing/invalid signature. Payload: ' . $payload);
    echo json_encode(array('error' => 'Invalid signature'));
    exit;
}

error_log('Telebirr callback: ' . json_encode($data));

$merchantTxId   = isset($data['merchant_transaction_id']) ? $data['merchant_transaction_id'] : '';
$telebirrTxId   = isset($data['transaction_id'])          ? $data['transaction_id']          : '';
$status         = strtoupper(isset($data['status'])       ? $data['status']                  : '');

if ($status === 'COMPLETED') {
    // Look up the pending payment record first - never trust the callback's own amount blindly.
    $s = $pdo->prepare('SELECT order_id, amount, status FROM telebirr_payments WHERE merchant_transaction_id=?');
    $s->execute(array($merchantTxId));
    $payment = $s->fetch();

    if (!$payment) {
        http_response_code(404);
        error_log('Telebirr callback: unknown merchant_transaction_id ' . $merchantTxId);
        echo json_encode(array('error' => 'Unknown transaction'));
        exit;
    }

    if ($payment['status'] === 'completed') {
        // Already processed - idempotent no-op, avoid double-crediting.
        http_response_code(200);
        echo json_encode(array('success' => true, 'note' => 'already processed'));
        exit;
    }

    // Cross-check the amount Telebirr reports against what we expected for this transaction.
    $callbackAmount = isset($data['amount']) ? (float)$data['amount'] : null;
    // A callback with no amount cannot be cross-checked, so it is rejected too.
    if ($callbackAmount === null || abs($callbackAmount - (float)$payment['amount']) > 0.01) {
        http_response_code(409);
        error_log('Telebirr callback: amount mismatch for ' . $merchantTxId .
                   ' expected=' . $payment['amount'] . ' got=' . ($callbackAmount === null ? 'missing' : $callbackAmount));
        echo json_encode(array('error' => 'Amount mismatch'));
        exit;
    }

    $pdo->prepare('UPDATE telebirr_payments
                   SET status=?, telebirr_transaction_id=?, completed_at=NOW()
                   WHERE merchant_transaction_id=?')
        ->execute(array('completed', $telebirrTxId, $merchantTxId));

    if ($payment['order_id']) {
        $pdo->prepare("UPDATE orders SET status='paid', payment_status='paid', payment_method='telebirr' WHERE id=?")
            ->execute(array($payment['order_id']));
    }

    http_response_code(200);
    echo json_encode(array('success' => true));

} elseif ($status === 'FAILED' || $status === 'CANCELLED') {
    $pdo->prepare('UPDATE telebirr_payments
                   SET status=?, error_message=?
                   WHERE merchant_transaction_id=?')
        ->execute(array(strtolower($status), isset($data['message']) ? $data['message'] : '', $merchantTxId));

    http_response_code(200);
    echo json_encode(array('success' => true));

} else {
    http_response_code(200);
    echo json_encode(array('received' => true, 'status' => $status));
}
