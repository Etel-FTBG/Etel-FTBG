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

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

/* ── Config ──────────────────────────────────────────────────── */
$_cfg = __DIR__ . '/config.php';
if (file_exists($_cfg)) require_once $_cfg;

$_env = __DIR__ . '/.env';
if (file_exists($_env)) {
    $ev = parse_ini_file($_env);
    if ($ev) foreach ($ev as $k => $v) { if (!getenv($k)) putenv("$k=$v"); }
}

// Allow .env overrides
if (!defined('EP_DB_HOST'))     define('EP_DB_HOST',     getenv('DB_HOST')     ?: 'localhost');
if (!defined('EP_DB_NAME'))     define('EP_DB_NAME',     getenv('DB_NAME')     ?: 'etelpay');
if (!defined('EP_DB_USER'))     define('EP_DB_USER',     getenv('DB_USER')     ?: 'root');
if (!defined('EP_DB_PASS'))     define('EP_DB_PASS',     getenv('DB_PASS')     ?: '');
if (!defined('EP_SECRET'))      define('EP_SECRET',      getenv('EP_SECRET')   ?: 'changeme');
if (!defined('EP_TBL_PREFIX'))  define('EP_TBL_PREFIX',  getenv('EP_TBL_PREFIX') ?: 'Etelpay_');
if (!defined('EP_SESSION_TTL')) define('EP_SESSION_TTL', 604800);
if (!defined('EP_DAILY_LIMIT')) define('EP_DAILY_LIMIT', 0);

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

/* ── Response helpers ────────────────────────────────────────── */
function ep_ok($data = null, $message = '') {
    echo json_encode(array('success' => true, 'data' => $data, 'message' => $message));
    exit;
}
function ep_err($message, $code = 400) {
    http_response_code($code);
    echo json_encode(array('success' => false, 'data' => array('message' => $message)));
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

function ep_auth() {
    $token = ep_get_bearer();
    if (!$token) ep_err('Unauthorized — no token', 401);
    $pdo  = ep_db();
    $stmt = $pdo->prepare('SELECT s.ep_user_id, u.phone, u.full_name, u.status, u.kyc_status, u.balance_etb, u.balance_usd, u.avatar_url, u.is_admin FROM ' . EP_TBL_SESSIONS . ' s JOIN ' . EP_TBL_USERS . ' u ON s.ep_user_id = u.id WHERE s.token = ? AND s.expires_at > NOW() LIMIT 1');
    $stmt->execute(array($token));
    $row = $stmt->fetch();
    if (!$row) ep_err('Session expired or invalid', 401);
    if ($row['status'] !== 'active') ep_err('Account suspended', 403);
    return $row;
}

function ep_auth_id() {
    $u = ep_auth();
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
switch ($action) {

/* ── Ping ─────────────────────────────────────────────────────── */
case 'ep_ping':
    ep_ok(array('version' => defined('EP_APP_VERSION') ? EP_APP_VERSION : '1.0.0', 'time' => date('Y-m-d H:i:s')), 'pong');

/* ── Login ────────────────────────────────────────────────────── */
case 'ep_login':
case 'etel_pay_login': {
    $phone = in_str('phone');
    $pin   = in_str('pin');
    if (!$phone || !$pin) ep_err('Phone and PIN required');

    $pdo  = ep_db();
    $stmt = $pdo->prepare('SELECT id, pin_hash, status FROM ' . EP_TBL_USERS . ' WHERE phone = ? LIMIT 1');
    $stmt->execute(array($phone));
    $user = $stmt->fetch();

    if (!$user) ep_err('Invalid phone or PIN');
    if ($user['status'] !== 'active') ep_err('Account suspended');
    if (!password_verify($pin, $user['pin_hash'])) ep_err('Invalid phone or PIN');

    // Create session
    $token      = bin2hex(openssl_random_pseudo_bytes(32));
    $expires_at = date('Y-m-d H:i:s', time() + EP_SESSION_TTL);
    $uid        = (int)$user['id'];

    // Clean old sessions
    $pdo->prepare('DELETE FROM ' . EP_TBL_SESSIONS . ' WHERE ep_user_id = ? AND expires_at < NOW()')->execute(array($uid));
    // Insert new
    $ins = $pdo->prepare('INSERT INTO ' . EP_TBL_SESSIONS . ' (ep_user_id, token, expires_at) VALUES (?, ?, ?)');
    $ins->execute(array($uid, $token, $expires_at));

    ep_ok(array('token' => $token, 'expires_at' => $expires_at), 'Login successful');
}

/* ── Logout ───────────────────────────────────────────────────── */
case 'ep_logout':
case 'etel_pay_logout': {
    $token = ep_get_bearer();
    if ($token) {
        ep_db()->prepare('DELETE FROM ' . EP_TBL_SESSIONS . ' WHERE token = ?')->execute(array($token));
    }
    ep_ok(null, 'Logged out');
}

/* ── Get balance ─────────────────────────────────────────────── */
case 'ep_get_balance':
case 'etel_pay_get_balance': {
    $user = ep_auth();
    ep_ok(array(
        'etb'         => (float)$user['balance_etb'],
        'usd'         => (float)$user['balance_usd'],
        'balance_etb' => (float)$user['balance_etb'],
        'balance_usd' => (float)$user['balance_usd'],
        'phone'       => $user['phone'],
        'kyc_status'  => $user['kyc_status'],
        'status'      => $user['status'],
        'is_admin'    => (bool)$user['is_admin'],
    ));
}

/* ── Get transaction history ─────────────────────────────────── */
case 'ep_get_history':
case 'etel_pay_get_history': {
    $uid    = ep_auth_id();
    $limit  = max(1, min(100, in_int('limit', 20)));
    $offset = max(0, in_int('offset', 0));

    $pdo  = ep_db();
    $stmt = $pdo->prepare(
        'SELECT t.*, ' .
        'su.phone AS sender_phone, su.full_name AS sender_name, ' .
        'ru.phone AS receiver_phone, ru.full_name AS receiver_name ' .
        'FROM ' . EP_TBL_TX . ' t ' .
        'LEFT JOIN ' . EP_TBL_USERS . ' su ON t.sender_ep_id   = su.id ' .
        'LEFT JOIN ' . EP_TBL_USERS . ' ru ON t.receiver_ep_id = ru.id ' .
        'WHERE (t.sender_ep_id = ? OR t.receiver_ep_id = ?) ' .
        'ORDER BY t.created_at DESC LIMIT ? OFFSET ?'
    );
    $stmt->execute(array($uid, $uid, $limit, $offset));
    $rows = $stmt->fetchAll();

    // Tag direction relative to current user
    foreach ($rows as &$row) {
        $row['type'] = ((int)$row['receiver_ep_id'] === $uid) ? 'receive' : $row['type'];
    }
    unset($row);

    ep_ok(array('transactions' => $rows, 'count' => count($rows)));
}

/* ── Get rates ───────────────────────────────────────────────── */
case 'ep_get_rates':
case 'etel_pay_get_rates': {
    $pdo  = ep_db();
    $rows = $pdo->query('SELECT currency, rate_etb AS rate, updated_at FROM ' . EP_TBL_RATES . ' ORDER BY currency')->fetchAll();
    ep_ok(array('rates' => $rows));
}

/* ── Send money ──────────────────────────────────────────────── */
case 'ep_send':
case 'etel_pay_send': {
    $sender_uid = ep_auth_id();
    $receiver   = in_str('receiver');
    $amount     = in_float('amount');
    $currency   = in_str('currency') ?: 'ETB';
    $note       = in_str('note');

    if (!$receiver)  ep_err('Receiver phone required');
    if ($amount <= 0) ep_err('Amount must be greater than 0');
    if ($amount < 1)  ep_err('Minimum transfer amount is 1 ETB');

    $pdo = ep_db();

    // Load sender
    $sstmt = $pdo->prepare('SELECT id, phone, balance_etb, balance_usd, status, full_name FROM ' . EP_TBL_USERS . ' WHERE id = ? LIMIT 1');
    $sstmt->execute(array($sender_uid));
    $sender = $sstmt->fetch();
    if (!$sender) ep_err('Sender not found', 404);
    if ($sender['status'] !== 'active') ep_err('Your account is suspended');

    // Load receiver
    $rstmt = $pdo->prepare('SELECT id, phone, balance_etb, balance_usd, status, full_name FROM ' . EP_TBL_USERS . ' WHERE phone = ? LIMIT 1');
    $rstmt->execute(array($receiver));
    $rcvr = $rstmt->fetch();
    if (!$rcvr) ep_err('Recipient phone not found on ኢE Pay');
    if ($rcvr['status'] !== 'active') ep_err('Recipient account is not active');
    if ((int)$rcvr['id'] === $sender_uid) ep_err('Cannot send money to yourself');

    // ETB-only for now
    if ($currency !== 'ETB') ep_err('Only ETB transfers supported currently');
    if ((float)$sender['balance_etb'] < $amount) ep_err('Insufficient balance');

    // Daily limit check
    if (EP_DAILY_LIMIT > 0) {
        $dlim = $pdo->prepare('SELECT COALESCE(SUM(amount),0) AS today FROM ' . EP_TBL_TX . ' WHERE sender_ep_id = ? AND currency = "ETB" AND created_at >= CURDATE()');
        $dlim->execute(array($sender_uid));
        $today = (float)$dlim->fetchColumn();
        if ($today + $amount > EP_DAILY_LIMIT) ep_err('Daily send limit reached (' . EP_DAILY_LIMIT . ' ETB)');
    }

    $tx_ref = ep_gen_ref();

    // Atomic transaction
    $pdo->beginTransaction();
    try {
        // Debit sender
        $pdo->prepare('UPDATE ' . EP_TBL_USERS . ' SET balance_etb = balance_etb - ? WHERE id = ? AND balance_etb >= ?')
            ->execute(array($amount, $sender_uid, $amount));

        // Credit receiver
        $pdo->prepare('UPDATE ' . EP_TBL_USERS . ' SET balance_etb = balance_etb + ? WHERE id = ?')
            ->execute(array($amount, (int)$rcvr['id']));

        // Log TX
        $pdo->prepare(
            'INSERT INTO ' . EP_TBL_TX . ' (tx_ref, type, sender_ep_id, receiver_ep_id, amount, currency, note, status, created_at) ' .
            'VALUES (?, "send", ?, ?, ?, ?, ?, "completed", NOW())'
        )->execute(array($tx_ref, $sender_uid, (int)$rcvr['id'], $amount, $currency, $note));

        $pdo->commit();
    } catch (Exception $ex) {
        $pdo->rollBack();
        ep_err('Transfer failed: ' . $ex->getMessage(), 500);
    }

    ep_ok(array(
        'tx_ref'          => $tx_ref,
        'amount'          => $amount,
        'currency'        => $currency,
        'receiver_phone'  => $rcvr['phone'],
        'receiver_name'   => $rcvr['full_name'],
        'note'            => $note,
    ), 'Transfer successful');
}

/* ── Generate QR ─────────────────────────────────────────────── */
case 'ep_qr_generate':
case 'etel_pay_qr_generate': {
    $uid      = ep_auth_id();
    $type     = in_str('type') ?: 'static';
    $amount   = in_float('amount');
    $currency = in_str('currency') ?: 'ETB';
    $label    = in_str('label');

    $pdo   = ep_db();
    $user  = $pdo->prepare('SELECT phone FROM ' . EP_TBL_USERS . ' WHERE id = ? LIMIT 1');
    $user->execute(array($uid));
    $urow  = $user->fetch();
    $phone = $urow ? $urow['phone'] : '';

    // Token (PHP 5.6 safe — random_bytes fallback)
    if (function_exists('random_bytes'))                 $token = bin2hex(random_bytes(16));
    elseif (function_exists('openssl_random_pseudo_bytes')) $token = bin2hex(openssl_random_pseudo_bytes(16));
    else                                                  $token = md5(uniqid(mt_rand(), true));

    $expires  = ($type === 'static') ? date('Y-m-d H:i:s', time() + 86400 * 365) : date('Y-m-d H:i:s', time() + 3600);
    $qr_data  = 'etel-pay://pay?token=' . $token . ($amount > 0 ? '&amount=' . $amount : '') . '&phone=' . urlencode($phone);

    // Detect which columns the qr_codes table actually has
    $qcols = array();
    try {
        $dr = $pdo->query('DESCRIBE ' . EP_TBL_QR);
        foreach ($dr->fetchAll(PDO::FETCH_COLUMN) as $cn) $qcols[$cn] = true;
    } catch (Exception $e) {}

    $uidCol = isset($qcols['owner_id']) ? 'owner_id' : (isset($qcols['user_id']) ? 'user_id' : (isset($qcols['ep_user_id']) ? 'ep_user_id' : 'owner_id'));

    // Build INSERT dynamically based on existing columns
    $fields = array($uidCol, 'token', 'type', 'currency', 'created_at');
    $places = array('?', '?', '?', '?', 'NOW()');
    $vals   = array($uid, $token, $type, $currency);

    if (isset($qcols['amount']))     { $fields[] = 'amount';     $places[] = '?'; array_splice($vals, 3, 0, array($amount ?: null)); }
    if (isset($qcols['label']))      { $fields[] = 'label';      $places[] = '?'; $vals[] = $label; }
    if (isset($qcols['expires_at'])) { $fields[] = 'expires_at'; $places[] = '?'; $vals[] = $expires; }
    if (isset($qcols['status']))     { $fields[] = 'status';     $places[] = '?'; $vals[] = 'active'; }

    // Rebuild value order to match field order safely
    $fields = array($uidCol, 'token', 'type');
    $places = array('?', '?', '?');
    $vals   = array($uid, $token, $type);
    if (isset($qcols['amount']))     { $fields[] = 'amount';     $places[] = '?'; $vals[] = $amount ?: null; }
    if (isset($qcols['currency']))   { $fields[] = 'currency';   $places[] = '?'; $vals[] = $currency; }
    if (isset($qcols['label']))      { $fields[] = 'label';      $places[] = '?'; $vals[] = $label; }
    if (isset($qcols['expires_at'])) { $fields[] = 'expires_at'; $places[] = '?'; $vals[] = $expires; }
    if (isset($qcols['status']))     { $fields[] = 'status';     $places[] = '?'; $vals[] = 'active'; }
    if (isset($qcols['used']))       { $fields[] = 'used';       $places[] = '?'; $vals[] = 0; }
    if (isset($qcols['created_at'])) { $fields[] = 'created_at'; $places[] = 'NOW()'; }

    $sql = 'INSERT INTO ' . EP_TBL_QR . ' (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $places) . ')';
    $pdo->prepare($sql)->execute($vals);

    ep_ok(array(
        'token'      => $token,
        'qr_data'    => $qr_data,
        'type'       => $type,
        'amount'     => $amount ?: null,
        'currency'   => $currency,
        'label'      => $label,
        'expires_at' => $expires,
    ));
}

/* ── Process QR payment ──────────────────────────────────────── */
case 'ep_qr_process':
case 'etel_pay_qr_process': {
    $payer_uid = ep_auth_id();
    $token     = in_str('token');
    $amount    = in_float('amount');
    $currency  = in_str('currency') ?: 'ETB';
    if (!$token) ep_err('QR token required');

    $pdo  = ep_db();
    // Adaptive: works whether table uses 'used' or 'status', 'user_id' or 'ep_user_id'
    $qr   = $pdo->prepare('SELECT * FROM ' . EP_TBL_QR . ' WHERE token = ? AND expires_at > NOW() LIMIT 1');
    $qr->execute(array($token));
    $qrow = $qr->fetch();
    if (!$qrow) ep_err('QR code expired or invalid');
    // Reject if already used
    if (isset($qrow['used']) && (int)$qrow['used'] === 1) ep_err('QR already used');
    if (isset($qrow['status']) && $qrow['status'] !== 'active') ep_err('QR already used');

    $owner_uid = (int)(isset($qrow['owner_id']) ? $qrow['owner_id'] : (isset($qrow['user_id']) ? $qrow['user_id'] : (isset($qrow['ep_user_id']) ? $qrow['ep_user_id'] : 0)));
    if ($owner_uid === $payer_uid) ep_err('Cannot pay your own QR code');

    $pay_amount = $qrow['amount'] ? (float)$qrow['amount'] : $amount;
    if (!$pay_amount || $pay_amount <= 0) ep_err('Amount required');

    // Load payer
    $payer = $pdo->prepare('SELECT balance_etb, status FROM ' . EP_TBL_USERS . ' WHERE id = ? LIMIT 1');
    $payer->execute(array($payer_uid));
    $prow = $payer->fetch();
    if (!$prow || $prow['status'] !== 'active') ep_err('Your account is not active');
    if ((float)$prow['balance_etb'] < $pay_amount) ep_err('Insufficient balance');

    $tx_ref = ep_gen_ref();

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE ' . EP_TBL_USERS . ' SET balance_etb = balance_etb - ? WHERE id = ? AND balance_etb >= ?')
            ->execute(array($pay_amount, $payer_uid, $pay_amount));
        $pdo->prepare('UPDATE ' . EP_TBL_USERS . ' SET balance_etb = balance_etb + ? WHERE id = ?')
            ->execute(array($pay_amount, $owner_uid));
        $pdo->prepare(
            'INSERT INTO ' . EP_TBL_TX . ' (tx_ref, type, sender_ep_id, receiver_ep_id, amount, currency, note, status, created_at) ' .
            'VALUES (?, "payment", ?, ?, ?, ?, ?, "completed", NOW())'
        )->execute(array($tx_ref, $payer_uid, $owner_uid, $pay_amount, $currency, 'QR: ' . $qrow['label']));

        // Mark dynamic QR as used; keep static active
        if ($qrow['type'] !== 'static') {
            if (isset($qrow['used']))        $pdo->prepare('UPDATE ' . EP_TBL_QR . ' SET `used` = 1 WHERE token = ?')->execute(array($token));
            elseif (isset($qrow['status']))  $pdo->prepare('UPDATE ' . EP_TBL_QR . ' SET status = "used" WHERE token = ?')->execute(array($token));
        }
        $pdo->commit();
    } catch (Exception $ex) {
        $pdo->rollBack();
        ep_err('Payment failed: ' . $ex->getMessage(), 500);
    }

    ep_ok(array('tx_ref' => $tx_ref, 'amount' => $pay_amount, 'currency' => $currency));
}

/* ── Get profile ─────────────────────────────────────────────── */
case 'ep_get_profile':
case 'ep_portal_get_profile': {
    $uid  = ep_auth_id();
    $pdo  = ep_db();
    $stmt = $pdo->prepare('SELECT id, phone, full_name, email, kyc_status, status, avatar_url, balance_etb, balance_usd, created_at FROM ' . EP_TBL_USERS . ' WHERE id = ? LIMIT 1');
    $stmt->execute(array($uid));
    $row  = $stmt->fetch();
    if (!$row) ep_err('User not found', 404);
    ep_ok($row);
}

/* ── Update profile ──────────────────────────────────────────── */
case 'ep_update_profile': {
    $uid      = ep_auth_id();
    $pdo      = ep_db();
    $sets     = array();
    $vals     = array();
    $allowed  = array('full_name', 'email');
    foreach ($allowed as $f) {
        $v = in_str($f);
        if ($v !== '') { $sets[] = "$f = ?"; $vals[] = $v; }
    }
    if (!$sets) ep_err('Nothing to update');
    $vals[] = $uid;
    $pdo->prepare('UPDATE ' . EP_TBL_USERS . ' SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
    ep_ok(null, 'Profile updated');
}

/* ── Change PIN ──────────────────────────────────────────────── */
case 'ep_change_pin': {
    $uid     = ep_auth_id();
    $old_pin = in_str('old_pin');
    $new_pin = in_str('new_pin');
    $con_pin = in_str('confirm_pin');
    if (!$old_pin || !$new_pin || !$con_pin) ep_err('All PIN fields required');
    if ($new_pin !== $con_pin) ep_err('PINs do not match');
    if (strlen($new_pin) < 4)  ep_err('PIN must be at least 4 digits');

    $pdo  = ep_db();
    $stmt = $pdo->prepare('SELECT pin_hash FROM ' . EP_TBL_USERS . ' WHERE id = ? LIMIT 1');
    $stmt->execute(array($uid));
    $row  = $stmt->fetch();
    if (!$row || !password_verify($old_pin, $row['pin_hash'])) ep_err('Current PIN is incorrect');

    $new_hash = password_hash($new_pin, PASSWORD_BCRYPT);
    $pdo->prepare('UPDATE ' . EP_TBL_USERS . ' SET pin_hash = ? WHERE id = ?')->execute(array($new_hash, $uid));
    // Revoke all sessions — force re-login
    $pdo->prepare('DELETE FROM ' . EP_TBL_SESSIONS . ' WHERE ep_user_id = ?')->execute(array($uid));
    ep_ok(null, 'PIN updated');
}

/* ── Upload avatar (base64) ──────────────────────────────────── */
case 'ep_upload_avatar': {
    $uid    = ep_auth_id();
    $b64    = in_str('avatar_b64');
    if (!$b64) ep_err('No image data');
    // Decode and validate
    $data   = base64_decode($b64, true);
    if (!$data || strlen($data) > 512000) ep_err('Image too large (max 500KB)');
    $mime   = 'image/jpeg';
    // Detect PNG
    if (substr($data, 0, 4) === "\x89PNG") $mime = 'image/png';
    $ext    = ($mime === 'image/png') ? 'png' : 'jpg';
    $dir    = dirname(__DIR__) . '/uploads/avatars';
    if (!file_exists($dir)) @mkdir($dir, 0755, true);
    $fname  = 'av_' . $uid . '_' . time() . '.' . $ext;
    $fpath  = $dir . '/' . $fname;
    if (!file_put_contents($fpath, $data)) ep_err('Could not save avatar', 500);
    $url    = 'https://ethelmarket.com/Etel.Pay/wallet/uploads/avatars/' . $fname;
    ep_db()->prepare('UPDATE ' . EP_TBL_USERS . ' SET avatar_url = ? WHERE id = ?')->execute(array($url, $uid));
    ep_ok(array('avatar_url' => $url), 'Avatar updated');
}

/* ── Admin: stats ─────────────────────────────────────────────── */
case 'ep_admin_stats': {
    $user = ep_auth();
    // Only proceed if admin (kyc_status='verified' + role check via settings)
    // For now: verified users with admin flag — check ep_settings for admin_phones
    _ep_require_admin();
    $pdo  = ep_db();
    $stats = array(
        'total_users'        => (int)$pdo->query('SELECT COUNT(*) FROM ' . EP_TBL_USERS)->fetchColumn(),
        'active_users'       => (int)$pdo->query('SELECT COUNT(*) FROM ' . EP_TBL_USERS . ' WHERE status="active"')->fetchColumn(),
        'total_transactions' => (int)$pdo->query('SELECT COUNT(*) FROM ' . EP_TBL_TX)->fetchColumn(),
        'total_volume'       => (float)$pdo->query('SELECT COALESCE(SUM(amount),0) FROM ' . EP_TBL_TX . ' WHERE status="completed" AND currency="ETB"')->fetchColumn(),
        'pending_tx'         => (int)$pdo->query('SELECT COUNT(*) FROM ' . EP_TBL_TX . ' WHERE status="pending"')->fetchColumn(),
    );
    ep_ok($stats);
}

/* ── Admin: users ─────────────────────────────────────────────── */
case 'ep_admin_users': {
    _ep_require_admin();
    $page = max(1, in_int('page', 1));
    $per  = max(1, min(50, in_int('per_page', 20)));
    $off  = ($page - 1) * $per;
    $pdo  = ep_db();
    $rows = $pdo->prepare('SELECT id, phone, full_name, email, balance_etb, balance_usd, kyc_status, status, avatar_url, created_at FROM ' . EP_TBL_USERS . ' ORDER BY created_at DESC LIMIT ? OFFSET ?');
    $rows->execute(array($per, $off));
    ep_ok(array('users' => $rows->fetchAll(), 'page' => $page));
}

/* ── Admin: get single user ───────────────────────────────────── */
case 'ep_admin_get_user': {
    _ep_require_admin();
    $uid  = in_int('user_id');
    $pdo  = ep_db();
    $stmt = $pdo->prepare('SELECT id, phone, full_name, email, balance_etb, balance_usd, kyc_status, status, avatar_url, created_at FROM ' . EP_TBL_USERS . ' WHERE id = ? LIMIT 1');
    $stmt->execute(array($uid));
    $row  = $stmt->fetch();
    if (!$row) ep_err('User not found', 404);
    ep_ok($row);
}

/* ── Admin: toggle user status ────────────────────────────────── */
case 'ep_admin_toggle_user': {
    _ep_require_admin();
    $uid = in_int('user_id');
    $pdo = ep_db();
    $cur = $pdo->prepare('SELECT status FROM ' . EP_TBL_USERS . ' WHERE id = ? LIMIT 1');
    $cur->execute(array($uid));
    $row = $cur->fetch();
    if (!$row) ep_err('User not found', 404);
    $new = ($row['status'] === 'active') ? 'suspended' : 'active';
    $pdo->prepare('UPDATE ' . EP_TBL_USERS . ' SET status = ? WHERE id = ?')->execute(array($new, $uid));
    ep_ok(array('new_status' => $new));
}

/* ── Admin: credit/debit ──────────────────────────────────────── */
case 'ep_admin_credit': {
    _ep_require_admin();
    $uid    = in_int('user_id');
    $amount = in_float('amount');
    $note   = in_str('note');
    if ($amount <= 0) ep_err('Amount must be > 0');
    $pdo    = ep_db();
    $pdo->prepare('UPDATE ' . EP_TBL_USERS . ' SET balance_etb = balance_etb + ? WHERE id = ?')->execute(array($amount, $uid));
    $pdo->prepare('INSERT INTO ' . EP_TBL_TX . ' (tx_ref, type, receiver_ep_id, amount, currency, note, status, created_at) VALUES (?, "deposit", ?, ?, "ETB", ?, "completed", NOW())')->execute(array(ep_gen_ref(), $uid, $amount, 'Admin credit: ' . $note));
    ep_ok(null, 'ETB ' . $amount . ' credited');
}

/* ── Admin: top up own balance ────────────────────────────────── */
case 'ep_admin_self_topup': {
    $admin_id = _ep_require_admin();   // returns admin's own user id
    $amount   = in_float('amount');
    $note     = in_str('note') ?: 'Self top-up';
    if ($amount <= 0) ep_err('Amount must be > 0');
    $pdo = ep_db();
    $pdo->prepare('UPDATE ' . EP_TBL_USERS . ' SET balance_etb = balance_etb + ? WHERE id = ?')->execute(array($amount, $admin_id));
    $pdo->prepare('INSERT INTO ' . EP_TBL_TX . ' (tx_ref, type, receiver_ep_id, amount, currency, note, status, created_at) VALUES (?, "deposit", ?, ?, "ETB", ?, "completed", NOW())')->execute(array(ep_gen_ref(), $admin_id, $amount, $note));
    // Return new balance
    $b = $pdo->prepare('SELECT balance_etb FROM ' . EP_TBL_USERS . ' WHERE id = ? LIMIT 1');
    $b->execute(array($admin_id));
    $row = $b->fetch();
    ep_ok(array('new_balance' => (float)($row ? $row['balance_etb'] : 0)), 'ETB ' . $amount . ' added to your wallet');
}
case 'ep_admin_debit': {
    _ep_require_admin();
    $uid    = in_int('user_id');
    $amount = in_float('amount');
    $note   = in_str('note');
    if ($amount <= 0) ep_err('Amount must be > 0');
    $pdo    = ep_db();
    $pdo->prepare('UPDATE ' . EP_TBL_USERS . ' SET balance_etb = GREATEST(0, balance_etb - ?) WHERE id = ?')->execute(array($amount, $uid));
    $pdo->prepare('INSERT INTO ' . EP_TBL_TX . ' (tx_ref, type, sender_ep_id, amount, currency, note, status, created_at) VALUES (?, "withdraw", ?, ?, "ETB", ?, "completed", NOW())')->execute(array(ep_gen_ref(), $uid, $amount, 'Admin debit: ' . $note));
    ep_ok(null, 'ETB ' . $amount . ' debited');
}

/* ── Admin: all transactions ──────────────────────────────────── */
case 'ep_admin_txs': {
    _ep_require_admin();
    $page = max(1, in_int('page', 1));
    $per  = max(1, min(50, in_int('per_page', 25)));
    $off  = ($page - 1) * $per;
    $pdo  = ep_db();
    $stmt = $pdo->prepare(
        'SELECT t.*, su.phone AS sender_phone, su.full_name AS sender_name, ru.phone AS receiver_phone, ru.full_name AS receiver_name ' .
        'FROM ' . EP_TBL_TX . ' t ' .
        'LEFT JOIN ' . EP_TBL_USERS . ' su ON t.sender_ep_id   = su.id ' .
        'LEFT JOIN ' . EP_TBL_USERS . ' ru ON t.receiver_ep_id = ru.id ' .
        'ORDER BY t.created_at DESC LIMIT ? OFFSET ?'
    );
    $stmt->execute(array($per, $off));
    ep_ok(array('transactions' => $stmt->fetchAll(), 'page' => $page));
}

/* ── Admin: reverse tx ────────────────────────────────────────── */
case 'ep_admin_reverse_tx': {
    _ep_require_admin();
    $tx_id = in_int('tx_id') ?: in_str('tx_id');
    $pdo   = ep_db();
    $stmt  = $pdo->prepare('SELECT * FROM ' . EP_TBL_TX . ' WHERE (id = ? OR tx_ref = ?) AND status = "completed" LIMIT 1');
    $stmt->execute(array($tx_id, $tx_id));
    $tx    = $stmt->fetch();
    if (!$tx) ep_err('Transaction not found or already reversed');

    $pdo->beginTransaction();
    try {
        if ($tx['sender_ep_id']) {
            $pdo->prepare('UPDATE ' . EP_TBL_USERS . ' SET balance_etb = balance_etb + ? WHERE id = ?')->execute(array((float)$tx['amount'], (int)$tx['sender_ep_id']));
        }
        if ($tx['receiver_ep_id']) {
            $pdo->prepare('UPDATE ' . EP_TBL_USERS . ' SET balance_etb = GREATEST(0, balance_etb - ?) WHERE id = ?')->execute(array((float)$tx['amount'], (int)$tx['receiver_ep_id']));
        }
        $pdo->prepare('UPDATE ' . EP_TBL_TX . ' SET status = "reversed" WHERE id = ?')->execute(array((int)$tx['id']));
        $pdo->commit();
    } catch (Exception $ex) {
        $pdo->rollBack();
        ep_err('Reverse failed: ' . $ex->getMessage(), 500);
    }
    ep_ok(null, 'Transaction reversed');
}

/* ── Admin: search ────────────────────────────────────────────── */
case 'ep_admin_search': {
    _ep_require_admin();
    $q    = '%' . in_str('query') . '%';
    $pdo  = ep_db();
    $stmt = $pdo->prepare('SELECT id, phone, full_name, email, balance_etb, status FROM ' . EP_TBL_USERS . ' WHERE phone LIKE ? OR full_name LIKE ? OR email LIKE ? LIMIT 30');
    $stmt->execute(array($q, $q, $q));
    ep_ok(array('users' => $stmt->fetchAll()));
}

/* ── Admin: rates ─────────────────────────────────────────────── */
case 'ep_admin_rates': {
    _ep_require_admin();
    $pdo  = ep_db();
    $rows = $pdo->query('SELECT id, currency, rate_etb AS rate, updated_at FROM ' . EP_TBL_RATES . ' ORDER BY currency')->fetchAll();
    ep_ok(array('rates' => $rows));
}

case 'ep_admin_update_rate': {
    _ep_require_admin();
    $cur  = strtoupper(in_str('currency'));
    $rate = in_float('rate');
    if (!$cur || $rate <= 0) ep_err('Currency and rate required');
    $pdo  = ep_db();
    $exist = $pdo->prepare('SELECT id FROM ' . EP_TBL_RATES . ' WHERE currency = ? LIMIT 1');
    $exist->execute(array($cur));
    if ($exist->fetch()) {
        $pdo->prepare('UPDATE ' . EP_TBL_RATES . ' SET rate_etb = ?, updated_at = NOW() WHERE currency = ?')->execute(array($rate, $cur));
    } else {
        $pdo->prepare('INSERT INTO ' . EP_TBL_RATES . ' (currency, rate_etb, updated_at) VALUES (?, ?, NOW())')->execute(array($cur, $rate));
    }
    ep_ok(null, $cur . ' rate updated to ' . $rate . ' ETB');
}

/* ── Admin: settings ──────────────────────────────────────────── */
case 'ep_admin_settings': {
    _ep_require_admin();
    ep_ok(_ep_get_settings());
}
case 'ep_admin_save_settings': {
    _ep_require_admin();
    $pdo  = ep_db();
    $keys = array('platform_name', 'support_phone', 'support_email', 'daily_limit', 'platform_fee');
    foreach ($keys as $k) {
        $v = in_str($k);
        if ($v === '') continue;
        _ep_set_setting($pdo, $k, $v);
    }
    ep_ok(null, 'Settings saved');
}

/* ── Admin: delete user ───────────────────────────────────────── */
case 'ep_admin_delete_user': {
    _ep_require_admin();
    $uid = in_int('user_id');
    $pdo = ep_db();
    $pdo->prepare('UPDATE ' . EP_TBL_USERS . ' SET status = "closed" WHERE id = ?')->execute(array($uid));
    $pdo->prepare('DELETE FROM ' . EP_TBL_SESSIONS . ' WHERE ep_user_id = ?')->execute(array($uid));
    ep_ok(null, 'User closed');
}


/* ═══════════════════════════════════════════════════════════════
   PLATFORM UPGRADE — v2.0
   ═══════════════════════════════════════════════════════════════ */

/* ── 1. TELEBIRR / CBE SELF TOP-UP ──────────────────────────── */
case 'ep_topup_initiate': {
    $uid      = ep_auth_id();
    $amount   = in_float('amount');
    $method   = in_str('method') ?: 'telebirr'; // telebirr|cbe|bank
    if ($amount < 10) ep_err('Minimum top-up is 10 ETB');

    $pdo = ep_db();
    $ref = 'TU-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
    $expires = date('Y-m-d H:i:s', time() + 3600); // 1hr to complete

    // Ensure table exists
    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . EP_TBL_TOPUP . ' (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id      INT UNSIGNED NOT NULL,
        ref          VARCHAR(40)  NOT NULL UNIQUE,
        amount       DECIMAL(18,2) NOT NULL,
        method       VARCHAR(20)  NOT NULL DEFAULT "telebirr",
        status       ENUM("pending","processing","completed","failed","expired") NOT NULL DEFAULT "pending",
        gateway_ref  VARCHAR(200) DEFAULT NULL,
        gateway_data TEXT DEFAULT NULL,
        expires_at   DATETIME NOT NULL,
        created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->prepare('INSERT INTO ' . EP_TBL_TOPUP . ' (user_id, ref, amount, method, expires_at) VALUES (?,?,?,?,?)')
        ->execute(array($uid, $ref, $amount, $method, $expires));

    // ── Telebirr integration (ready-to-wire) ──────────────────
    // Replace these with your live credentials from Ethio Telecom developer portal
    $TELEBIRR_APP_ID     = defined('TELEBIRR_APP_ID')     ? TELEBIRR_APP_ID     : (getenv('TELEBIRR_APP_ID')     ?: '');
    $TELEBIRR_APP_KEY    = defined('TELEBIRR_APP_KEY')     ? TELEBIRR_APP_KEY    : (getenv('TELEBIRR_APP_KEY')    ?: '');
    $TELEBIRR_SHORT_CODE = defined('TELEBIRR_SHORT_CODE')  ? TELEBIRR_SHORT_CODE : (getenv('TELEBIRR_SHORT_CODE') ?: '');
    $TELEBIRR_NOTIFY_URL = defined('TELEBIRR_NOTIFY_URL')  ? TELEBIRR_NOTIFY_URL : (getenv('TELEBIRR_NOTIFY_URL') ?: '');
    $TELEBIRR_RETURN_URL = defined('EP_BASE_URL')          ? EP_BASE_URL . '/Etel.Pay/wallet/topup-return'       : '';

    if ($TELEBIRR_APP_ID && $TELEBIRR_APP_KEY && $method === 'telebirr') {
        // Live Telebirr H5 payment flow
        $ts        = time();
        $nonce     = bin2hex(random_bytes(8));
        $sign_str  = 'appId=' . $TELEBIRR_APP_ID . '&appKey=' . $TELEBIRR_APP_KEY .
                     '&nonce=' . $nonce . '&notifyUrl=' . $TELEBIRR_NOTIFY_URL .
                     '&outTradeNo=' . $ref . '&returnUrl=' . $TELEBIRR_RETURN_URL .
                     '&shortCode=' . $TELEBIRR_SHORT_CODE . '&subject=ኢE Pay Top-up' .
                     '&timeoutExpress=60&timestamp=' . $ts . '&totalAmount=' . $amount;
        $sign = strtoupper(hash('sha256', $sign_str));
        $payload = array(
            'appId'=>$TELEBIRR_APP_ID, 'nonce'=>$nonce, 'notifyUrl'=>$TELEBIRR_NOTIFY_URL,
            'outTradeNo'=>$ref, 'returnUrl'=>$TELEBIRR_RETURN_URL, 'shortCode'=>$TELEBIRR_SHORT_CODE,
            'subject'=>'ኢE Pay Top-up', 'timeoutExpress'=>60, 'timestamp'=>$ts,
            'totalAmount'=>$amount, 'sign'=>$sign
        );
        $ch = curl_init('https://h5pay.trade.et/payment/v1/merchant/preOrder');
        curl_setopt_array($ch, array(
            CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>15,
            CURLOPT_HTTPHEADER=>array('Content-Type: application/json'),
            CURLOPT_POSTFIELDS=>json_encode($payload)
        ));
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        if (!$err && $resp) {
            $r = json_decode($resp, true);
            if (isset($r['toPayUrl'])) {
                $pdo->prepare('UPDATE ' . EP_TBL_TOPUP . ' SET status="processing", gateway_ref=?, gateway_data=? WHERE ref=?')
                    ->execute(array($r['toPayUrl'], $resp, $ref));
                ep_ok(array('ref'=>$ref,'method'=>'telebirr','redirect_url'=>$r['toPayUrl'],'amount'=>$amount), 'Redirecting to Telebirr');
            }
        }
        // Fall through to sandbox/manual if live fails
    }

    // ── Manual / sandbox mode ─────────────────────────────────
    ep_ok(array(
        'ref'        => $ref,
        'amount'     => $amount,
        'method'     => $method,
        'status'     => 'pending',
        'expires_at' => $expires,
        'instructions' => array(
            'telebirr' => array(
                'en' => 'Send ETB ' . $amount . ' to short code ' . (defined('TELEBIRR_SHORT_CODE')?TELEBIRR_SHORT_CODE:'*127*6*2#') . ' with reference: ' . $ref,
                'am' => 'ETB ' . $amount . ' ለ ' . (defined('TELEBIRR_SHORT_CODE')?TELEBIRR_SHORT_CODE:'*127*6*2#') . ' ቁጥር ላኩ። ማጣቀሻ: ' . $ref,
            ),
            'cbe' => array(
                'en' => 'Transfer ETB ' . $amount . ' to CBE account 1000123456789 ref: ' . $ref,
                'am' => 'ETB ' . $amount . ' ወደ CBE ሒሳብ 1000123456789 ያስተላልፉ። ማ: ' . $ref,
            ),
        ),
        'confirm_url' => 'After payment, tap Confirm to notify us',
    ), 'Top-up request created');
}

/* ── 1b. Confirm top-up (user submits proof) ─────────────────── */
case 'ep_topup_confirm': {
    $uid  = ep_auth_id();
    $ref  = in_str('ref');
    $screenshot_b64 = in_str('screenshot_b64'); // optional proof image
    if (!$ref) ep_err('Ref required');
    $pdo  = ep_db();
    $stmt = $pdo->prepare('SELECT * FROM ' . EP_TBL_TOPUP . ' WHERE ref=? AND user_id=? LIMIT 1');
    $stmt->execute(array($ref, $uid));
    $req  = $stmt->fetch();
    if (!$req) ep_err('Top-up request not found');
    if ($req['status'] === 'completed') ep_err('Already completed');
    if ($req['expires_at'] < date('Y-m-d H:i:s')) ep_err('Request expired');
    $pdo->prepare('UPDATE ' . EP_TBL_TOPUP . ' SET status="processing" WHERE ref=?')->execute(array($ref));
    ep_ok(array('ref'=>$ref,'status'=>'processing'), 'Confirmation received — admin will verify and credit within minutes');
}

/* ── 1c. Admin: approve top-up ───────────────────────────────── */
case 'ep_admin_topup_approve': {
    _ep_require_admin();
    $ref = in_str('ref');
    $pdo = ep_db();
    $stmt = $pdo->prepare('SELECT * FROM ' . EP_TBL_TOPUP . ' WHERE ref=? LIMIT 1');
    $stmt->execute(array($ref));
    $req  = $stmt->fetch();
    if (!$req) ep_err('Top-up not found');
    if ($req['status'] === 'completed') ep_err('Already completed');
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE ' . EP_TBL_USERS . ' SET balance_etb = balance_etb + ? WHERE id = ?')
            ->execute(array($req['amount'], $req['user_id']));
        $pdo->prepare('INSERT INTO ' . EP_TBL_TX . ' (tx_ref, type, receiver_ep_id, amount, currency, note, status, created_at) VALUES (?,?,?,?,?,?,?,NOW())')
            ->execute(array(ep_gen_ref(), 'deposit', $req['user_id'], $req['amount'], 'ETB', 'Top-up: '.$ref, 'completed'));
        $pdo->prepare('UPDATE ' . EP_TBL_TOPUP . ' SET status="completed" WHERE ref=?')->execute(array($ref));
        $pdo->commit();
        ep_ok(array('ref'=>$ref,'credited'=>$req['amount']), 'ETB '.$req['amount'].' credited');
    } catch (Exception $ex) { $pdo->rollBack(); ep_err('Failed: '.$ex->getMessage(), 500); }
}

/* ── 1d. Admin: list pending top-ups ────────────────────────── */
case 'ep_admin_topup_list': {
    _ep_require_admin();
    $pdo  = ep_db();
    $status = in_str('status') ?: 'processing';
    $rows = $pdo->prepare('SELECT t.*, u.phone, u.full_name FROM ' . EP_TBL_TOPUP . ' t LEFT JOIN ' . EP_TBL_USERS . ' u ON t.user_id=u.id WHERE t.status=? ORDER BY t.created_at DESC LIMIT 50');
    $rows->execute(array($status));
    ep_ok(array('requests'=>$rows->fetchAll()));
}

/* ── 2. PAYMENT LINKS ────────────────────────────────────────── */
case 'ep_paylink_create': {
    $uid    = ep_auth_id();
    $amount = in_float('amount');
    $label  = in_str('label') ?: 'Payment';
    $note   = in_str('note');
    $expiry = in_str('expires_hours') ? (int)in_str('expires_hours') : 72;
    $pdo    = ep_db();

    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . EP_TBL_PAY_LINKS . ' (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id     INT UNSIGNED NOT NULL,
        code        VARCHAR(32)  NOT NULL UNIQUE,
        label       VARCHAR(255) NOT NULL DEFAULT "",
        amount      DECIMAL(18,2) DEFAULT NULL,
        note        VARCHAR(500) DEFAULT NULL,
        times_used  INT UNSIGNED NOT NULL DEFAULT 0,
        max_uses    INT UNSIGNED DEFAULT NULL,
        status      ENUM("active","paused","expired") NOT NULL DEFAULT "active",
        expires_at  DATETIME DEFAULT NULL,
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $code    = strtolower(bin2hex(random_bytes(6)));
    $expires = $expiry > 0 ? date('Y-m-d H:i:s', time() + $expiry * 3600) : null;

    $pdo->prepare('INSERT INTO ' . EP_TBL_PAY_LINKS . ' (user_id, code, label, amount, note, expires_at) VALUES (?,?,?,?,?,?)')
        ->execute(array($uid, $code, $label, $amount ?: null, $note, $expires));

    $base_url = defined('EP_BASE_URL') ? EP_BASE_URL : 'https://ethelmarket.com';
    ep_ok(array(
        'code'       => $code,
        'label'      => $label,
        'amount'     => $amount ?: null,
        'pay_url'    => $base_url . '/Etel.Pay/pay/' . $code,
        'expires_at' => $expires,
    ), 'Payment link created');
}

case 'ep_paylink_info': {
    // Public — no auth needed
    $code = in_str('code') ?: (isset($_GET['code']) ? trim($_GET['code']) : '');
    if (!$code) ep_err('Code required');
    $pdo  = ep_db();
    $stmt = $pdo->prepare('SELECT p.*, u.phone, u.full_name, u.avatar_url FROM ' . EP_TBL_PAY_LINKS . ' p JOIN ' . EP_TBL_USERS . ' u ON p.user_id=u.id WHERE p.code=? AND p.status="active" LIMIT 1');
    $stmt->execute(array($code));
    $link = $stmt->fetch();
    if (!$link) ep_err('Payment link not found or expired', 404);
    if ($link['expires_at'] && $link['expires_at'] < date('Y-m-d H:i:s')) {
        $pdo->prepare('UPDATE ' . EP_TBL_PAY_LINKS . ' SET status="expired" WHERE code=?')->execute(array($code));
        ep_err('Payment link has expired', 410);
    }
    ep_ok(array('code'=>$code, 'label'=>$link['label'], 'amount'=>$link['amount'],
        'note'=>$link['note'], 'recipient'=>array('phone'=>$link['phone'],'name'=>$link['full_name'],'avatar'=>$link['avatar_url'])));
}

case 'ep_paylink_pay': {
    $payer_uid = ep_auth_id();
    $code      = in_str('code');
    $amount    = in_float('amount');
    if (!$code) ep_err('Code required');
    $pdo  = ep_db();
    $stmt = $pdo->prepare('SELECT * FROM ' . EP_TBL_PAY_LINKS . ' WHERE code=? AND status="active" LIMIT 1');
    $stmt->execute(array($code));
    $link = $stmt->fetch();
    if (!$link) ep_err('Payment link not found', 404);
    if ($link['expires_at'] && $link['expires_at'] < date('Y-m-d H:i:s')) ep_err('Link expired', 410);
    if ((int)$link['user_id'] === $payer_uid) ep_err('Cannot pay your own link');
    $pay_amount = $link['amount'] ?: $amount;
    if (!$pay_amount || $pay_amount <= 0) ep_err('Amount required');
    $payer = $pdo->prepare('SELECT balance_etb, status FROM ' . EP_TBL_USERS . ' WHERE id=? LIMIT 1');
    $payer->execute(array($payer_uid));
    $pr = $payer->fetch();
    if (!$pr || $pr['status'] !== 'active') ep_err('Account not active');
    if ((float)$pr['balance_etb'] < $pay_amount) ep_err('Insufficient balance');
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE ' . EP_TBL_USERS . ' SET balance_etb=balance_etb-? WHERE id=? AND balance_etb>=?')->execute(array($pay_amount,$payer_uid,$pay_amount));
        $pdo->prepare('UPDATE ' . EP_TBL_USERS . ' SET balance_etb=balance_etb+? WHERE id=?')->execute(array($pay_amount,$link['user_id']));
        $ref = ep_gen_ref();
        $pdo->prepare('INSERT INTO ' . EP_TBL_TX . ' (tx_ref,type,sender_ep_id,receiver_ep_id,amount,currency,note,status,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())')
            ->execute(array($ref,'payment',$payer_uid,$link['user_id'],$pay_amount,'ETB','Link: '.$link['label'],'completed'));
        $pdo->prepare('UPDATE ' . EP_TBL_PAY_LINKS . ' SET times_used=times_used+1 WHERE code=?')->execute(array($code));
        $pdo->commit();
        ep_ok(array('ref'=>$ref,'amount'=>$pay_amount,'code'=>$code), 'Payment sent');
    } catch (Exception $ex) { $pdo->rollBack(); ep_err('Payment failed: '.$ex->getMessage(), 500); }
}

case 'ep_paylink_list': {
    $uid  = ep_auth_id();
    $pdo  = ep_db();
    $rows = $pdo->prepare('SELECT * FROM ' . EP_TBL_PAY_LINKS . ' WHERE user_id=? ORDER BY created_at DESC LIMIT 20');
    $rows->execute(array($uid));
    ep_ok(array('links'=>$rows->fetchAll()));
}

/* ── 3. API KEYS (for merchants) ────────────────────────────── */
case 'ep_apikey_create': {
    $uid = ep_auth_id();
    $pdo = ep_db();
    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . EP_TBL_API_KEYS . ' (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id     INT UNSIGNED NOT NULL,
        label       VARCHAR(100) NOT NULL DEFAULT "Default",
        pub_key     VARCHAR(64)  NOT NULL UNIQUE,
        secret_key  VARCHAR(128) NOT NULL,
        webhook_url VARCHAR(500) DEFAULT NULL,
        permissions VARCHAR(255) NOT NULL DEFAULT "pay,receive",
        status      ENUM("active","revoked") NOT NULL DEFAULT "active",
        last_used   DATETIME DEFAULT NULL,
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pub    = "ep_pub_"  . bin2hex(random_bytes(16));
    $secret = "ep_sec_"  . bin2hex(random_bytes(24));
    $label  = in_str('label') ?: 'Default';
    $webhook = in_str('webhook_url');
    $pdo->prepare('INSERT INTO ' . EP_TBL_API_KEYS . ' (user_id, label, pub_key, secret_key, webhook_url) VALUES (?,?,?,?,?)')
        ->execute(array($uid, $label, $pub, $secret, $webhook));
    ep_ok(array('pub_key'=>$pub, 'secret_key'=>$secret, 'label'=>$label,
        'warning'=>'Save your secret key — it will not be shown again'), 'API key created');
}

case 'ep_apikey_list': {
    $uid  = ep_auth_id();
    $pdo  = ep_db();
    $rows = $pdo->prepare('SELECT id, label, pub_key, webhook_url, permissions, status, last_used, created_at FROM ' . EP_TBL_API_KEYS . ' WHERE user_id=? AND status="active" ORDER BY created_at DESC');
    $rows->execute(array($uid));
    ep_ok(array('keys'=>$rows->fetchAll()));
}

case 'ep_apikey_revoke': {
    $uid = ep_auth_id();
    $key_id = in_int('key_id');
    $pdo = ep_db();
    $pdo->prepare('UPDATE ' . EP_TBL_API_KEYS . ' SET status="revoked" WHERE id=? AND user_id=?')->execute(array($key_id, $uid));
    ep_ok(null, 'API key revoked');
}

/* ── 4. KYC DOCUMENT UPLOAD ──────────────────────────────────── */
case 'ep_kyc_submit': {
    $uid      = ep_auth_id();
    $doc_type = in_str('doc_type'); // national_id|passport|driving_license
    $doc_b64  = in_str('doc_front_b64');
    $back_b64 = in_str('doc_back_b64');
    $selfie   = in_str('selfie_b64');
    if (!$doc_type || !$doc_b64) ep_err('Document type and front image required');
    $pdo = ep_db();
    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . EP_TBL_KYC . ' (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id     INT UNSIGNED NOT NULL UNIQUE,
        doc_type    VARCHAR(30)  NOT NULL,
        doc_front   LONGTEXT     DEFAULT NULL,
        doc_back    LONGTEXT     DEFAULT NULL,
        selfie      LONGTEXT     DEFAULT NULL,
        status      ENUM("submitted","reviewing","approved","rejected") NOT NULL DEFAULT "submitted",
        reviewer_note VARCHAR(500) DEFAULT NULL,
        submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        reviewed_at  DATETIME DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    // Validate base64 size (max 3MB each)
    if (strlen($doc_b64) > 4194304) ep_err('Document image too large (max 3MB)');
    $pdo->prepare('INSERT INTO ' . EP_TBL_KYC . ' (user_id, doc_type, doc_front, doc_back, selfie) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE doc_type=VALUES(doc_type), doc_front=VALUES(doc_front), doc_back=VALUES(doc_back), selfie=VALUES(selfie), status="submitted", submitted_at=NOW()')
        ->execute(array($uid, $doc_type, $doc_b64, $back_b64, $selfie));
    $pdo->prepare('UPDATE ' . EP_TBL_USERS . ' SET kyc_status="pending" WHERE id=?')->execute(array($uid));
    ep_ok(null, 'KYC documents submitted — under review within 24hrs');
}

case 'ep_admin_kyc_list': {
    _ep_require_admin();
    $pdo = ep_db();
    $status = in_str('status') ?: 'submitted';
    $rows = $pdo->prepare('SELECT k.id, k.user_id, k.doc_type, k.status, k.submitted_at, u.phone, u.full_name FROM ' . EP_TBL_KYC . ' k JOIN ' . EP_TBL_USERS . ' u ON k.user_id=u.id WHERE k.status=? ORDER BY k.submitted_at ASC LIMIT 50');
    $rows->execute(array($status));
    ep_ok(array('submissions'=>$rows->fetchAll()));
}

case 'ep_admin_kyc_review': {
    _ep_require_admin();
    $kyc_id = in_int('kyc_id');
    $action = in_str('action'); // approve|reject
    $note   = in_str('note');
    if (!in_array($action, array('approve','reject'), true)) ep_err('Invalid action');
    $pdo    = ep_db();
    $stmt   = $pdo->prepare('SELECT user_id FROM ' . EP_TBL_KYC . ' WHERE id=? LIMIT 1');
    $stmt->execute(array($kyc_id));
    $kyc    = $stmt->fetch();
    if (!$kyc) ep_err('KYC not found');
    $new_status = $action === 'approve' ? 'approved' : 'rejected';
    $kyc_status = $action === 'approve' ? 'verified' : 'none';
    $pdo->prepare('UPDATE ' . EP_TBL_KYC . ' SET status=?, reviewer_note=?, reviewed_at=NOW() WHERE id=?')->execute(array($new_status,$note,$kyc_id));
    $pdo->prepare('UPDATE ' . EP_TBL_USERS . ' SET kyc_status=? WHERE id=?')->execute(array($kyc_status, $kyc['user_id']));
    ep_ok(null, 'KYC ' . $new_status);
}

/* ── 5. ANALYTICS ────────────────────────────────────────────── */
case 'ep_analytics': {
    _ep_require_admin();
    $period = in_str('period') ?: '30d'; // 7d|30d|90d|all
    $pdo    = ep_db();
    $since  = $period === 'all' ? '2020-01-01' : date('Y-m-d', strtotime('-' . intval($period) . ' days'));
    // Total users
    $users  = (int)$pdo->query('SELECT COUNT(*) FROM ' . EP_TBL_USERS)->fetchColumn();
    $new_u  = (int)$pdo->prepare('SELECT COUNT(*) FROM ' . EP_TBL_USERS . ' WHERE created_at>=?')->execute(array($since)) ? $pdo->query('SELECT COUNT(*) FROM ' . EP_TBL_USERS . ' WHERE created_at>="'.$since.'"')->fetchColumn() : 0;
    // Transaction volume
    $vol    = $pdo->query('SELECT COALESCE(SUM(amount),0) FROM ' . EP_TBL_TX . ' WHERE status="completed" AND created_at>="'.$since.'"')->fetchColumn();
    $count  = $pdo->query('SELECT COUNT(*) FROM ' . EP_TBL_TX . ' WHERE status="completed" AND created_at>="'.$since.'"')->fetchColumn();
    // By type
    $by_type = $pdo->query('SELECT type, COUNT(*) as cnt, COALESCE(SUM(amount),0) as vol FROM ' . EP_TBL_TX . ' WHERE created_at>="'.$since.'" GROUP BY type')->fetchAll();
    // Daily volumes (last 14 days)
    $daily  = $pdo->query('SELECT DATE(created_at) as d, COUNT(*) as cnt, COALESCE(SUM(amount),0) as vol FROM ' . EP_TBL_TX . ' WHERE created_at>=DATE_SUB(NOW(),INTERVAL 14 DAY) AND status="completed" GROUP BY DATE(created_at) ORDER BY d ASC')->fetchAll();
    // Total wallet balance
    $total_bal = $pdo->query('SELECT COALESCE(SUM(balance_etb),0) FROM ' . EP_TBL_USERS)->fetchColumn();
    // KYC stats
    $kyc_v = $pdo->query('SELECT COUNT(*) FROM ' . EP_TBL_USERS . ' WHERE kyc_status="verified"')->fetchColumn();
    ep_ok(array(
        'period'       => $period,
        'since'        => $since,
        'users'        => array('total'=>(int)$users, 'new_in_period'=>(int)$new_u, 'kyc_verified'=>(int)$kyc_v),
        'transactions' => array('count'=>(int)$count, 'volume_etb'=>(float)$vol, 'by_type'=>$by_type),
        'daily'        => $daily,
        'total_wallet_balance_etb' => (float)$total_bal,
    ));
}

/* ── 5b. User own analytics ──────────────────────────────────── */
case 'ep_my_analytics': {
    $uid = ep_auth_id();
    $pdo = ep_db();
    $since = date('Y-m-d', strtotime('-30 days'));
    $sent     = $pdo->prepare('SELECT COALESCE(SUM(amount),0),COUNT(*) FROM ' . EP_TBL_TX . ' WHERE sender_ep_id=? AND status="completed" AND created_at>=?');
    $sent->execute(array($uid,$since)); $sr = $sent->fetch(PDO::FETCH_NUM);
    $received = $pdo->prepare('SELECT COALESCE(SUM(amount),0),COUNT(*) FROM ' . EP_TBL_TX . ' WHERE receiver_ep_id=? AND status="completed" AND created_at>=?');
    $received->execute(array($uid,$since)); $rr = $received->fetch(PDO::FETCH_NUM);
    $daily    = $pdo->prepare('SELECT DATE(created_at) as d, type, COALESCE(SUM(amount),0) as vol FROM ' . EP_TBL_TX . ' WHERE (sender_ep_id=? OR receiver_ep_id=?) AND created_at>=? GROUP BY DATE(created_at),type ORDER BY d ASC');
    $daily->execute(array($uid,$uid,$since));
    ep_ok(array('period'=>'30d','sent'=>array('amount'=>(float)$sr[0],'count'=>(int)$sr[1]),'received'=>array('amount'=>(float)$rr[0],'count'=>(int)$rr[1]),'daily'=>$daily->fetchAll()));
}

/* ── 6. DISPUTES / REFUNDS ───────────────────────────────────── */
case 'ep_dispute_open': {
    $uid    = ep_auth_id();
    $tx_ref = in_str('tx_ref');
    $reason = in_str('reason');
    if (!$tx_ref || !$reason) ep_err('TX ref and reason required');
    $pdo = ep_db();
    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . EP_TBL_DISPUTES . ' (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        tx_ref VARCHAR(40) NOT NULL,
        reason TEXT NOT NULL,
        status ENUM("open","investigating","resolved","rejected") NOT NULL DEFAULT "open",
        resolution TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        resolved_at DATETIME DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->prepare('INSERT INTO ' . EP_TBL_DISPUTES . ' (user_id, tx_ref, reason) VALUES (?,?,?)')->execute(array($uid, $tx_ref, $reason));
    ep_ok(null, 'Dispute opened — we will review within 48hrs');
}

case 'ep_admin_dispute_list': {
    _ep_require_admin();
    $pdo  = ep_db();
    $rows = $pdo->prepare('SELECT d.*, u.phone, u.full_name FROM ' . EP_TBL_DISPUTES . ' d JOIN ' . EP_TBL_USERS . ' u ON d.user_id=u.id WHERE d.status=? ORDER BY d.created_at ASC LIMIT 50');
    $rows->execute(array(in_str('status') ?: 'open'));
    ep_ok(array('disputes'=>$rows->fetchAll()));
}

case 'ep_admin_dispute_resolve': {
    _ep_require_admin();
    $id  = in_int('dispute_id');
    $act = in_str('action'); // resolve|reject|refund
    $res = in_str('resolution');
    $pdo = ep_db();
    if ($act === 'refund') {
        $disp = $pdo->prepare('SELECT * FROM ' . EP_TBL_DISPUTES . ' WHERE id=? LIMIT 1');
        $disp->execute(array($id)); $d = $disp->fetch();
        if ($d) {
            $tx = $pdo->prepare('SELECT * FROM ' . EP_TBL_TX . ' WHERE tx_ref=? LIMIT 1');
            $tx->execute(array($d['tx_ref'])); $t = $tx->fetch();
            if ($t && $t['sender_ep_id']) {
                $pdo->prepare('UPDATE ' . EP_TBL_USERS . ' SET balance_etb=balance_etb+? WHERE id=?')->execute(array($t['amount'],$t['sender_ep_id']));
                $pdo->prepare('UPDATE ' . EP_TBL_USERS . ' SET balance_etb=GREATEST(0,balance_etb-?) WHERE id=?')->execute(array($t['amount'],$t['receiver_ep_id']));
                $pdo->prepare('INSERT INTO ' . EP_TBL_TX . ' (tx_ref,type,sender_ep_id,receiver_ep_id,amount,currency,note,status,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())')
                    ->execute(array(ep_gen_ref(),'refund',$t['receiver_ep_id'],$t['sender_ep_id'],$t['amount'],'ETB','Refund: '.$d['tx_ref'],'completed'));
            }
        }
    }
    $pdo->prepare('UPDATE ' . EP_TBL_DISPUTES . ' SET status=?,resolution=?,resolved_at=NOW() WHERE id=?')->execute(array($act==='reject'?'rejected':'resolved',$res,$id));
    ep_ok(null, 'Dispute ' . ($act==='reject'?'rejected':'resolved'));
}


/* ── EtelID: sync KYC to wallet ─────────────────────────────── */
case 'ep_etelid_kyc_sync':
case 'etel_pay_etelid_kyc_sync': {
    $user   = ep_auth();
    $uid    = (int)$user['ep_user_id'];
    $phone  = $user['phone'];
    if (!function_exists('ep_sync_etelid_kyc')) ep_err('EtelID bridge not installed', 503);
    $result = ep_sync_etelid_kyc($uid, $phone);
    if (!$result['success']) ep_err($result['message'], 400);
    ep_ok($result, $result['message']);
}

/* ── EtelID: resolve by FIN ─────────────────────────────────── */
case 'ep_etelid_resolve': {
    ep_auth();
    $fin = in_str('fin');
    if (!$fin) ep_err('FIN required');
    if (!function_exists('etelid_resolve')) ep_err('EtelID bridge not installed', 503);
    $r = etelid_resolve($fin, 'fin');
    if (!$r['ok'] || empty($r['data']['member'])) ep_err('EtelID not found', 404);
    $m = $r['data']['member'];
    ep_ok(array(
        'fin'       => isset($m['fin'])       ? $m['fin']       : '',
        'full_name' => isset($m['full_name']) ? $m['full_name'] : '',
        'phone'     => isset($m['phone'])     ? $m['phone']     : '',
        'kyc_level' => (int)(isset($m['kyc_level']) ? $m['kyc_level'] : 0),
        'status'    => isset($m['status'])    ? $m['status']    : '',
        'photo_url' => isset($m['photo_url']) ? $m['photo_url'] : null,
    ));
}

/* ── EtelID: SSO login for EtelPay ─────────────────────────── */
case 'ep_login_etelid': {
    $phone = in_str('phone');
    $pin   = in_str('pin');
    if (!$phone || !$pin) ep_err('Phone and PIN required');
    if (!function_exists('etelid_authenticate')) ep_err('EtelID bridge not installed', 503);
    $auth  = etelid_authenticate($phone, $pin);
    if (!$auth['ok'] || empty($auth['data']['authenticated'])) ep_err('EtelID authentication failed', 401);
    $pdo   = ep_db();
    $stmt  = $pdo->prepare('SELECT * FROM ' . EP_TBL_USERS . ' WHERE phone=? LIMIT 1');
    $stmt->execute(array($phone));
    $user  = $stmt->fetch();
    if (!$user) {
        // Auto-create wallet for verified EtelID member
        $mm = isset($auth['data']['member']) ? $auth['data']['member'] : array();
        $pdo->prepare('INSERT INTO ' . EP_TBL_USERS .
            ' (phone,full_name,pin_hash,kyc_status,status,created_at) VALUES (?,?,?,?,?,NOW())')
            ->execute(array($phone, isset($mm['full_name'])?$mm['full_name']:'', 'ETELID_SSO', 'verified', 'active'));
        $stmt->execute(array($phone));
        $user = $stmt->fetch();
    }
    if ($user['status'] !== 'active') ep_err('Account suspended', 403);
    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + EP_SESSION_TTL);
    $pdo->prepare('INSERT INTO ' . EP_TBL_SESSIONS . ' (ep_user_id,token,expires_at,created_at) VALUES (?,?,?,NOW())')
        ->execute(array((int)$user['id'], $token, $expires));
    ep_ok(array('token'=>$token,'expires_at'=>$expires,'phone'=>$phone,'via'=>'etelid_sso'));
}

/* ── Nonce (standalone PHP — return a session-based token) ───── */
case 'ep_nonce':
case 'etel_pay_nonce': {
    // Standalone API doesn't use WP nonces.
    // Return a time-based token the JS can send; api.php ignores it.
    ep_ok(array('nonce' => bin2hex(random_bytes(16))));
}

default:
    ep_err('Unknown action: ' . htmlspecialchars($action), 404);
}

/* ══════════════════════════════════════════════════════════════
   HELPER FUNCTIONS
   ══════════════════════════════════════════════════════════════ */

/**
 * Require admin — checks phone against admin_phones setting.
 * If admin_phones not set, any verified KYC user is admin (bootstrap).
 */
function _ep_require_admin() {
    $token = ep_get_bearer();
    if (!$token) ep_err('Unauthorized', 401);
    $pdo   = ep_db();
    $stmt  = $pdo->prepare('SELECT u.id, u.phone, u.kyc_status, u.is_admin FROM ' . EP_TBL_SESSIONS . ' s JOIN ' . EP_TBL_USERS . ' u ON s.ep_user_id = u.id WHERE s.token = ? AND s.expires_at > NOW() LIMIT 1');
    $stmt->execute(array($token));
    $u     = $stmt->fetch();
    if (!$u) ep_err('Session expired', 401);

    // Primary check: is_admin column
    if (isset($u['is_admin']) && (int)$u['is_admin'] === 1) return (int)$u['id'];

    // Fallback: admin_phones setting
    $settings     = _ep_get_settings();
    $admin_phones = isset($settings['admin_phones']) ? $settings['admin_phones'] : '';
    if ($admin_phones) {
        $phones = array_map('trim', explode(',', $admin_phones));
        if (in_array($u['phone'], $phones, true)) return (int)$u['id'];
    }
    ep_err('Admin access required', 403);
}

function _ep_get_settings() {
    try {
        $pdo  = ep_db();
        // Try ep_settings table first (WP options table fallback)
        try {
            $rows = $pdo->query('SELECT option_name, option_value FROM ' . EP_TBL_PREFIX . 'options WHERE option_name LIKE "etel_pay_%" LIMIT 50')->fetchAll();
            $out  = array();
            foreach ($rows as $r) {
                $key       = str_replace('etel_pay_', '', $r['option_name']);
                $out[$key] = $r['option_value'];
            }
            return $out;
        } catch (Exception $e) {
            return array();
        }
    } catch (Exception $e) {
        return array();
    }
}

function _ep_set_setting($pdo, $key, $value) {
    try {
        $option = 'etel_pay_' . $key;
        $exist  = $pdo->prepare('SELECT option_id FROM ' . EP_TBL_PREFIX . 'options WHERE option_name = ? LIMIT 1');
        $exist->execute(array($option));
        if ($exist->fetch()) {
            $pdo->prepare('UPDATE ' . EP_TBL_PREFIX . 'options SET option_value = ? WHERE option_name = ?')->execute(array($value, $option));
        } else {
            $pdo->prepare('INSERT INTO ' . EP_TBL_PREFIX . 'options (option_name, option_value) VALUES (?, ?)')->execute(array($option, $value));
        }
    } catch (Exception $e) {}
}
