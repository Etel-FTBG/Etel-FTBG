<?php
/**
 * EtelPay - wallet PIN check with an account-level lockout.
 *
 * Every money-moving action calls this before it touches a balance.
 * Failed attempts are counted per ACCOUNT (not per IP) in the table
 * <prefix>ep_pin_lock, which is created on first use, so no manual SQL
 * is needed. The counter only resets on a correct PIN.
 *
 *   5..9 wrong PINs in a row  -> each further wrong PIN locks 15 minutes
 *   10 or more                -> each further wrong PIN locks 24 hours
 *
 * Loaded by v2_verify.php (main API) and by service_nfc_charge.php.
 * ASCII only, PHP 5.6 compatible.
 */

function ep_pin_table() {
    return (defined('EP_TBL_PREFIX') ? EP_TBL_PREFIX : 'Etelpay_') . 'ep_pin_lock';
}

/* Minutes to lock after this many consecutive wrong PINs (0 = no lock). */
function ep_pin_lock_minutes($fails) {
    $fails = (int)$fails;
    if ($fails >= 10) return 1440;
    if ($fails >= 5)  return 15;
    return 0;
}

/* True if the stored value is a real bcrypt hash. Single sign-on wallets
 * hold the marker ETELID_SSO until the owner sets a wallet PIN. */
function ep_pin_has_pin($hash) {
    return is_string($hash) && preg_match('/^\$2[aby]\$/', $hash) === 1;
}

function ep_pin_res($ok, $code, $http, $message) {
    return array('ok' => $ok, 'code' => $code, 'http' => $http, 'message' => $message);
}

function ep_pin_wait_text($seconds) {
    $m = (int)ceil($seconds / 60);
    if ($m >= 120) return (int)ceil($m / 60) . ' hours';
    if ($m <= 1)   return '1 minute';
    return $m . ' minutes';
}

/**
 * Check $pin for account $uid. Returns array(ok, code, http, message).
 * Codes: ok, pin_required, pin_not_set, pin_wrong, pin_locked, pin_error.
 * Fails closed: any database problem refuses the action.
 */
function ep_pin_check($pdo, $uid, $pin) {
    static $made = false;
    $uid = (int)$uid;
    $pin = (string)$pin;
    if ($uid <= 0) return ep_pin_res(false, 'pin_error', 403, 'Account not found');
    if ($pin === '') return ep_pin_res(false, 'pin_required', 400, 'Enter your PIN to continue.');

    $t   = ep_pin_table();
    $own = !$pdo->inTransaction();
    try {
        if ($own && !$made) {
            $pdo->exec('CREATE TABLE IF NOT EXISTS ' . $t . ' (' .
                'ep_user_id BIGINT UNSIGNED NOT NULL, ' .
                'fails INT NOT NULL DEFAULT 0, ' .
                'locked_until DATETIME NULL, ' .
                'updated_at DATETIME NOT NULL, ' .
                'PRIMARY KEY (ep_user_id)' .
                ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            $made = true;
        }
        if ($own) $pdo->beginTransaction();

        $pdo->prepare('INSERT IGNORE INTO ' . $t . ' (ep_user_id, fails, updated_at) VALUES (?, 0, NOW())')
            ->execute(array($uid));
        // Row lock: parallel guesses are counted one at a time.
        $s = $pdo->prepare('SELECT fails, COALESCE(TIMESTAMPDIFF(SECOND, NOW(), locked_until), 0) AS wait_s FROM ' . $t . ' WHERE ep_user_id = ? FOR UPDATE');
        $s->execute(array($uid));
        $row   = $s->fetch();
        $fails = $row ? (int)$row['fails'] : 0;
        $wait  = $row ? (int)$row['wait_s'] : 0;

        if ($wait > 0) {
            if ($own) $pdo->commit();
            return ep_pin_res(false, 'pin_locked', 423, 'Too many wrong PINs. Try again in ' . ep_pin_wait_text($wait) . '.');
        }

        $u = $pdo->prepare('SELECT pin_hash FROM ' . EP_TBL_USERS . ' WHERE id = ? LIMIT 1');
        $u->execute(array($uid));
        $urow = $u->fetch();
        if (!$urow) {
            if ($own) $pdo->commit();
            return ep_pin_res(false, 'pin_error', 403, 'Account not found');
        }
        if (!ep_pin_has_pin($urow['pin_hash'])) {
            if ($own) $pdo->commit();
            return ep_pin_res(false, 'pin_not_set', 403, 'Set a wallet PIN first.');
        }

        if (password_verify($pin, $urow['pin_hash'])) {
            $pdo->prepare('UPDATE ' . $t . ' SET fails = 0, locked_until = NULL, updated_at = NOW() WHERE ep_user_id = ?')
                ->execute(array($uid));
            if ($own) $pdo->commit();
            return ep_pin_res(true, 'ok', 200, '');
        }

        $fails++;
        $mins = ep_pin_lock_minutes($fails);
        $pdo->prepare('UPDATE ' . $t . ' SET fails = ?, locked_until = IF(? > 0, DATE_ADD(NOW(), INTERVAL ? MINUTE), NULL), updated_at = NOW() WHERE ep_user_id = ?')
            ->execute(array($fails, $mins, $mins, $uid));
        if ($own) $pdo->commit();
        if ($mins > 0) {
            return ep_pin_res(false, 'pin_wrong', 403, 'Incorrect PIN. Account locked for ' . ep_pin_wait_text($mins * 60) . '.');
        }
        $left = 5 - $fails;
        return ep_pin_res(false, 'pin_wrong', 403, 'Incorrect PIN. ' . $left . ' attempt' . ($left == 1 ? '' : 's') . ' left before a lock.');
    } catch (Exception $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        return ep_pin_res(false, 'pin_error', 500, 'PIN check failed. Try again.');
    }
}

/* Main API helper: refuse the request unless $pin is right for $uid. */
function ep_require_pin($uid, $pin) {
    $r = ep_pin_check(ep_db(), $uid, $pin);
    if ($r['ok']) return;
    while (ob_get_level()) { @ob_end_clean(); }
    http_response_code($r['http']);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        'success' => false,
        'data'    => array('message' => $r['message'], 'code' => $r['code']),
        'v'       => defined('EP_VER') ? EP_VER : '',
    ));
    exit;
}
