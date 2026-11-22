<?php
/* EtelPay Route Functions - Part 5: "how should Etel Pay check me when I pay" (the member's own choice, set in the app's profile).

   Three choices, stored per wallet account (table <prefix>ep_pay_prefs, created on first use):
     pin      PIN only (the default). After ONE full sign-in on a phone (Etel-ID password, and the code when the account has one), that phone is
              remembered with a long random device token (only its SHA-256 is stored, 90 days). Paying then needs no password and no code:
              ep_login_device swaps the token for a PAY-ONLY session (it can do nothing but ep_send for "ETEL ..." payments) and ep_send
              still checks the wallet PIN, with the usual lockout, every single time.
     password Etel-ID password and PIN (no code).
     code     Etel-ID password, the 6-digit code and PIN.
   Changing the choice needs the Etel-ID password (and the code when the account has two-step sign-in); EtelID is told through its existing
   service/pay_2fa_mode (pin and password = skip the code at payment, code = ask for it). Moving away from "pin" forgets every remembered phone.
   ASCII only, PHP 5.6 compatible. The helper functions take a PDO so they can be unit tested without the full wallet. */

if (!defined('EP_PD_DEVICE_DAYS')) define('EP_PD_DEVICE_DAYS', 90);
if (!defined('EP_PD_SESSION_SECONDS')) define('EP_PD_SESSION_SECONDS', 86400);

function ep_pd_dev_table()  { return EP_TBL_PREFIX . 'ep_pay_devices'; }
function ep_pd_pref_table() { return EP_TBL_PREFIX . 'ep_pay_prefs'; }

function ep_pd_valid_mode($m) { return in_array($m, array('pin', 'password', 'code'), true); }

function ep_pd_tables($pdo) {
    static $made = false;
    if ($made) return;
    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . ep_pd_dev_table() . ' (token_hash VARCHAR(64) NOT NULL PRIMARY KEY, ep_user_id BIGINT NOT NULL, created_at VARCHAR(19) NOT NULL, expires_at VARCHAR(19) NOT NULL, last_used_at VARCHAR(19) NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . ep_pd_pref_table() . ' (ep_user_id BIGINT NOT NULL PRIMARY KEY, mode VARCHAR(12) NOT NULL, updated_at VARCHAR(19) NOT NULL)');
    $made = true;
}

/* The member's choice; "pin" until they choose. */
function ep_pd_mode($pdo, $uid) {
    try {
        ep_pd_tables($pdo);
        $s = $pdo->prepare('SELECT mode FROM ' . ep_pd_pref_table() . ' WHERE ep_user_id = ?');
        $s->execute(array((int)$uid));
        $m = $s->fetchColumn();
        return ep_pd_valid_mode($m) ? $m : 'pin';
    } catch (Exception $e) { return 'pin'; }
}

function ep_pd_set_mode($pdo, $uid, $mode) {
    if (!ep_pd_valid_mode($mode)) return false;
    ep_pd_tables($pdo);
    $uid = (int)$uid; $now = date('Y-m-d H:i:s');
    $s = $pdo->prepare('SELECT COUNT(*) FROM ' . ep_pd_pref_table() . ' WHERE ep_user_id = ?');
    $s->execute(array($uid));
    if ((int)$s->fetchColumn() > 0) $pdo->prepare('UPDATE ' . ep_pd_pref_table() . ' SET mode = ?, updated_at = ? WHERE ep_user_id = ?')->execute(array($mode, $now, $uid));
    else $pdo->prepare('INSERT INTO ' . ep_pd_pref_table() . ' (ep_user_id, mode, updated_at) VALUES (?, ?, ?)')->execute(array($uid, $mode, $now));
    if ($mode !== 'pin') ep_pd_revoke_all($pdo, $uid);
    return true;
}

function ep_pd_revoke_all($pdo, $uid) {
    ep_pd_tables($pdo);
    $pdo->prepare('DELETE FROM ' . ep_pd_dev_table() . ' WHERE ep_user_id = ?')->execute(array((int)$uid));
}

/* Remember this phone. Returns the plain token (shown once, kept only in the phone) or '' when it may not be done. */
function ep_pd_register($pdo, $uid) {
    if (ep_pd_mode($pdo, $uid) !== 'pin') return '';
    ep_pd_tables($pdo);
    $plain = bin2hex(function_exists('random_bytes') ? random_bytes(32) : openssl_random_pseudo_bytes(32));
    $now = date('Y-m-d H:i:s'); $exp = date('Y-m-d H:i:s', time() + 86400 * EP_PD_DEVICE_DAYS);
    $pdo->prepare('DELETE FROM ' . ep_pd_dev_table() . ' WHERE expires_at < ?')->execute(array($now));
    $pdo->prepare('INSERT INTO ' . ep_pd_dev_table() . ' (token_hash, ep_user_id, created_at, expires_at, last_used_at) VALUES (?, ?, ?, ?, ?)')->execute(array(hash('sha256', $plain), (int)$uid, $now, $exp, $now));
    return $plain;
}

/* The wallet account a remembered phone belongs to (0 when unknown, expired, or the member no longer chose "pin"). */
function ep_pd_device_user($pdo, $plain) {
    $plain = (string)$plain;
    if (!preg_match('/^[0-9a-f]{64}$/', $plain)) return 0;
    try {
        ep_pd_tables($pdo);
        $h = hash('sha256', $plain);
        $s = $pdo->prepare('SELECT ep_user_id FROM ' . ep_pd_dev_table() . ' WHERE token_hash = ? AND expires_at > ?');
        $s->execute(array($h, date('Y-m-d H:i:s')));
        $uid = (int)$s->fetchColumn();
        if ($uid <= 0 || ep_pd_mode($pdo, $uid) !== 'pin') return 0;
        $pdo->prepare('UPDATE ' . ep_pd_dev_table() . ' SET last_used_at = ? WHERE token_hash = ?')->execute(array(date('Y-m-d H:i:s'), $h));
        return $uid;
    } catch (Exception $e) { return 0; }
}

/* A pay-only session may do exactly one thing: send money for an "ETEL ..." payment (ride, delivery ...). */
function ep_pd_scope_allows($action, $note) {
    return in_array($action, array('ep_send', 'etel_pay_send'), true) && strpos((string)$note, 'ETEL ') === 0;
}

function ep_pd_session_scope($pdo, $token) {
    try {
        $s = $pdo->prepare('SELECT scope FROM ' . EP_TBL_SESSIONS . ' WHERE token = ?');
        $s->execute(array($token));
        $v = $s->fetchColumn();
        return is_string($v) ? $v : '';
    } catch (Exception $e) { return ''; }   // no scope column yet: no pay-only session exists
}

/* Called from ep_auth() for every signed-in request. */
function ep_pd_scope_guard($pdo, $token) {
    global $action;
    if (ep_pd_session_scope($pdo, $token) !== 'pay') return;
    if (!ep_pd_scope_allows(isset($action) ? $action : '', in_str('note'))) ep_err('This sign-in can only pay for ETEL services. Please sign in with your Etel-ID password.', 403);
}

function ep_pd_new_session($pdo, $uid, $scope) {
    $token = bin2hex(function_exists('random_bytes') ? random_bytes(32) : openssl_random_pseudo_bytes(32));
    $ttl = ($scope === 'pay') ? EP_PD_SESSION_SECONDS : EP_SESSION_TTL;
    $expires = date('Y-m-d H:i:s', time() + $ttl);
    if ($scope === 'pay') {
        try { $pdo->query('SELECT scope FROM ' . EP_TBL_SESSIONS . ' LIMIT 0'); }
        catch (Exception $e) { $pdo->exec('ALTER TABLE ' . EP_TBL_SESSIONS . ' ADD COLUMN scope VARCHAR(12) NULL'); }
        $pdo->prepare('INSERT INTO ' . EP_TBL_SESSIONS . ' (ep_user_id, token, expires_at, created_at, scope) VALUES (?, ?, ?, ?, ?)')->execute(array((int)$uid, $token, $expires, date('Y-m-d H:i:s'), 'pay'));
    } else {
        $pdo->prepare('INSERT INTO ' . EP_TBL_SESSIONS . ' (ep_user_id, token, expires_at, created_at) VALUES (?, ?, ?, ?)')->execute(array((int)$uid, $token, $expires, date('Y-m-d H:i:s')));
    }
    return array($token, $expires);
}

/* After a good ep_login_etelid: add the member's choice and, for "pin" with remember_device=1 and a wallet PIN set, a device token. */
function ep_pd_after_login($pdo, $user, $out) {
    $mode = ep_pd_mode($pdo, (int)$user['id']);
    $out['pay_mode'] = $mode;
    if ($mode === 'pin' && in_str('remember_device') === '1' && ep_pin_has_pin(isset($user['pin_hash']) ? $user['pin_hash'] : '')) {
        $t = ep_pd_register($pdo, (int)$user['id']);
        if ($t !== '') $out['device_token'] = $t;
    }
    return $out;
}

function ep_pd_wallet_user($pdo, $phone) {
    $digits = preg_replace('/[^0-9]/', '', (string)$phone);
    $last9  = strlen($digits) >= 9 ? substr($digits, -9) : $digits;
    if ($last9 === '') return false;
    $s = $pdo->prepare('SELECT * FROM ' . EP_TBL_USERS . ' WHERE RIGHT(REPLACE(REPLACE(REPLACE(phone,\'+\',\'\'),\' \',\'\'),\'-\',\'\'), 9) = ? LIMIT 1');
    $s->execute(array($last9));
    return $s->fetch();
}

function ep_route_part5($action) {
  switch ($action) {

/* Swap a remembered phone for a pay-only session. No password, no code: the wallet PIN is still asked by ep_send. */
case 'ep_login_device': {
    $pdo = ep_db();
    $uid = ep_pd_device_user($pdo, in_str('device_token'));
    if ($uid <= 0) { http_response_code(401); echo json_encode(array('success' => false, 'data' => array('message' => 'Sign in with your Etel-ID password once on this phone.', 'code' => 'device_unknown'), 'v' => EP_VER)); exit; }
    $u = $pdo->prepare('SELECT id, phone, status FROM ' . EP_TBL_USERS . ' WHERE id = ? LIMIT 1');
    $u->execute(array($uid));
    $row = $u->fetch();
    if (!$row || $row['status'] !== 'active') ep_err('Account suspended', 403);
    $t = ep_pd_new_session($pdo, $uid, 'pay');
    ep_ok(array('token' => $t[0], 'expires_at' => $t[1], 'phone' => $row['phone'], 'via' => 'device', 'pay_mode' => 'pin'));
}

/* The member's choice. Same inputs as ep_pay_code_mode: phone, the Etel-ID password in "pin", the code in totp_code; plus mode pin|password|code. */
case 'ep_pay_auth_mode': {
    $phone = in_str('phone'); $pw = in_str('pin'); $mode = strtolower(in_str('mode'));
    if (!$phone || !$pw) ep_err('Phone and password required');
    if (!ep_pd_valid_mode($mode)) ep_err('mode must be pin, password or code', 400);
    if (!function_exists('etelid_pay_code_mode')) ep_err('EtelID bridge not installed', 503);
    $pdo = ep_db();
    $user = ep_pd_wallet_user($pdo, $phone);
    if (!$user) ep_err('No Etel Pay wallet for this phone yet. Pay once with your Etel-ID password first.', 404);
    if ($user['status'] !== 'active') ep_err('Account suspended', 403);
    if ($mode === 'pin' && !ep_pin_has_pin(isset($user['pin_hash']) ? $user['pin_hash'] : '')) {
        http_response_code(400);
        echo json_encode(array('success' => false, 'data' => array('message' => 'Create your wallet PIN first (tap "I have no PIN yet" when you pay), then choose PIN only.', 'code' => 'no_pin'), 'v' => EP_VER));
        exit;
    }
    $res = etelid_pay_code_mode($phone, $pw, ($mode === 'code') ? 'ask' : 'skip', in_str('totp_code'));
    if ((int)$res[0] !== 200) { http_response_code($res[0]); $o = $res[1]; $o['v'] = EP_VER; echo json_encode($o); exit; }
    ep_pd_set_mode($pdo, (int)$user['id'], $mode);
    $out = array('mode' => $mode, 'twofa_enabled' => !empty($res[1]['data']['twofa_enabled']));
    if ($mode === 'pin') { $t = ep_pd_register($pdo, (int)$user['id']); if ($t !== '') $out['device_token'] = $t; }
    ep_ok($out, 'Saved');
}

    default: return false;
  }
  return true;
}
