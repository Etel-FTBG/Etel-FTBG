<?php
/* ኢE Pay Route Functions - Part 2 */
function ep_route_part2($action) {
  global $_body;
  switch($action) {
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
    default: return false;
  }
  return true;
}
