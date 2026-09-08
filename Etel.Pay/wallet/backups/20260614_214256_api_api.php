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
if (!defined('EP_TBL_SETTINGS')) define('EP_TBL_SETTINGS', EP_TBL_PREFIX . 'ep_settings');

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
