<?php
/* ኢE Pay Route Functions - Part 1 */
function ep_route_part1($action) {
  global $_body;
  switch($action) {

/* ── Ping ─────────────────────────────────────────────────────── */
case 'ep_ping':
    ep_ok(array('version' => defined('EP_APP_VERSION') ? EP_APP_VERSION : EP_VER, 'time' => date('Y-m-d H:i:s')), 'pong');

/* ── Telegram/WhatsApp OTP sender (shared group-wide) ────────── */
case 'ep_send_tg_otp': {
    /* Send an OTP to a phone via Telegram (looks up chat_id from EtelID).
       Used by Pay for password reset and large transfer confirmation. */
    $phone = in_str('phone');
    $otp   = in_str('otp');  /* pre-generated 6-digit OTP from caller */
    if (!$phone || !$otp) ep_err('phone and otp required');

    $tg_token = ep_env('TG_BOT_TOKEN','CFG_TG_BOT_TOKEN','');
    if (!$tg_token) ep_err('Telegram not configured',503);

    /* Look up chat_id from EtelID Service API */
    $chat_id = '';
    $eid_url = ep_env('ETELID_SERVICE_URL','ETELID_SERVICE_URL','');
    $eid_key = ep_env('ETELID_SERVICE_KEY','EP_CFG_SECRET','');
    if ($eid_url && $eid_key) {
        $ch = curl_init(rtrim($eid_url,'/').'/service/resolve');
        curl_setopt_array($ch,array(
            CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>6,
            CURLOPT_POSTFIELDS=>json_encode(array('identifier'=>$phone,'type'=>'phone')),
            CURLOPT_HTTPHEADER=>array('Content-Type: application/json','X-Etel-Service-Key: '.$eid_key),
        ));
        $resp = curl_exec($ch); curl_close($ch);
        $d = $resp ? @json_decode($resp,true) : null;
        if ($d && !empty($d['ok']) && !empty($d['member']['tg_chat_id']))
            $chat_id = $d['member']['tg_chat_id'];
    }

    if (!$chat_id) ep_err('Telegram not linked for this phone. Open @EtelDeliveryRideBot and send /start '.$phone, 404);

    $msg = "💳 <b>ኢE Pay OTP</b>

Code: <code>".$otp."</code>
Valid for 10 minutes.
Do not share.
— ኢትኤል ቡድን";
    $ch = curl_init('https://api.telegram.org/bot'.$tg_token.'/sendMessage');
    curl_setopt_array($ch,array(
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode(array('chat_id'=>$chat_id,'text'=>$msg,'parse_mode'=>'HTML')),
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>8,
        CURLOPT_HTTPHEADER=>array('Content-Type: application/json'),
    ));
    $resp = curl_exec($ch); curl_close($ch);
    $tg_resp = $resp ? @json_decode($resp,true) : null;
    if (!$tg_resp || empty($tg_resp['ok'])) ep_err('Failed to send Telegram message',502);
    ep_ok(array('sent'=>true,'channel'=>'telegram'));
}

/* ── Check if phone has Telegram linked (for UI hint) ────────── */
case 'ep_check_tg_link': {
    $phone = in_str('phone');
    if (!$phone) ep_err('phone required');
    $eid_url = ep_env('ETELID_SERVICE_URL','ETELID_SERVICE_URL','');
    $eid_key = ep_env('ETELID_SERVICE_KEY','EP_CFG_SECRET','');
    $linked = false; $tg_bot = ep_env('TG_BOT_NAME','CFG_TG_BOT_NAME','EtelDeliveryRideBot');
    if ($eid_url && $eid_key) {
        $ch = curl_init(rtrim($eid_url,'/').'/service/resolve');
        curl_setopt_array($ch,array(
            CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>6,
            CURLOPT_POSTFIELDS=>json_encode(array('identifier'=>$phone,'type'=>'phone')),
            CURLOPT_HTTPHEADER=>array('Content-Type: application/json','X-Etel-Service-Key: '.$eid_key),
        ));
        $resp = curl_exec($ch); curl_close($ch);
        $d = $resp ? @json_decode($resp,true) : null;
        if ($d && !empty($d['ok']) && !empty($d['member']['tg_chat_id'])) $linked=true;
    }
    ep_ok(array('linked'=>$linked,'tg_bot'=>$tg_bot,'link_url'=>'https://t.me/'.$tg_bot.'?start='.urlencode($phone)));
}

/* ── Diagnostics (admin-only in production — IP-gated here for dev) ── */
case 'ep_diag_db': {
    $pdo    = ep_db();
    $tables = array(
        EP_TBL_USERS, EP_TBL_TX, EP_TBL_SESSIONS,
        EP_TBL_QR, EP_TBL_TOPUP, EP_TBL_PAY_LINKS,
        EP_TBL_KYC, EP_TBL_DISPUTES, EP_TBL_API_KEYS,
    );
    $result = array();
    $users  = 0; $txs = 0;
    foreach ($tables as $tbl) {
        try {
            $cnt = (int) $pdo->query("SELECT COUNT(*) FROM `{$tbl}`")->fetchColumn();
            $result[] = array('name' => $tbl, 'exists' => true, 'rows' => $cnt);
            if ($tbl === EP_TBL_USERS) $users = $cnt;
            if ($tbl === EP_TBL_TX)    $txs   = $cnt;
        } catch (Exception $e) {
            $result[] = array('name' => $tbl, 'exists' => false, 'rows' => 0);
        }
    }
    ep_ok(array('tables' => $result, 'users' => $users, 'transactions' => $txs,
                'db_host' => EP_DB_HOST, 'db_name' => EP_DB_NAME));
}

case 'ep_diag_sessions': {
    $pdo = ep_db();
    try {
        $active  = (int) $pdo->query("SELECT COUNT(*) FROM ".EP_TBL_SESSIONS." WHERE expires_at > NOW()")->fetchColumn();
        $expired = (int) $pdo->query("SELECT COUNT(*) FROM ".EP_TBL_SESSIONS." WHERE expires_at <= NOW()")->fetchColumn();
        ep_ok(array('active' => $active, 'expired' => $expired));
    } catch (Exception $e) {
        ep_err('Sessions table error: '.$e->getMessage());
    }
}

case 'ep_diag_telebirr': {
    $app_id  = defined('TELEBIRR_APP_ID')     ? TELEBIRR_APP_ID     : (getenv('TELEBIRR_APP_ID') ?: '');
    $app_key = defined('TELEBIRR_APP_KEY')    ? TELEBIRR_APP_KEY    : (getenv('TELEBIRR_APP_KEY') ?: '');
    $configured = ($app_id && $app_key && $app_id !== 'your_app_id');
    ep_ok(array(
        'configured'  => $configured,
        'app_id_set'  => !empty($app_id),
        'app_key_set' => !empty($app_key),
    ));
}

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

    default: return false;
  }
  return true;
}
