<?php
/* ኢE Pay Route Functions - Part 3 */
function ep_route_part3($action) {
  global $_body;
  switch($action) {
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
    if (!$auth['ok'] || empty($auth['data']['ok']) || empty($auth['data']['member'])) {
        $msg = !empty($auth['data']['message']) ? $auth['data']['message'] : 'EtelID authentication failed';
        ep_err($msg, 401);
    }
    $mm = isset($auth['data']['member']) ? $auth['data']['member'] : array();
    /* Callers (ride.js, customer.js, the wallet's own login form) don't all
       send the same phone format for the same person -- +251936533606 vs
       0936533606 vs spaced/dashed variants. Match by the last 9 digits
       (same convention as etelid_bridge.php in every other project) so a
       formatting difference can never silently spin up a second, empty
       wallet account for someone who already has one. */
    $digits = preg_replace('/[^0-9]/', '', $phone);
    $last9  = strlen($digits) >= 9 ? substr($digits, -9) : $digits;
    $canonical_phone = !empty($mm['phone']) ? $mm['phone'] : $phone;

    $pdo   = ep_db();
    $stmt  = $pdo->prepare(
        'SELECT * FROM ' . EP_TBL_USERS . '
         WHERE RIGHT(REPLACE(REPLACE(REPLACE(phone,\'+\',\'\'),\' \',\'\'),\'-\',\'\'), 9) = ?
         LIMIT 1');
    $stmt->execute(array($last9));
    $user  = $stmt->fetch();
    if (!$user) {
        // Auto-create wallet for verified EtelID member -- store the
        // canonical phone EtelID has on file, not whatever format this
        // particular caller happened to send.
        $pdo->prepare('INSERT INTO ' . EP_TBL_USERS .
            ' (phone,full_name,pin_hash,kyc_status,status,created_at) VALUES (?,?,?,?,?,NOW())')
            ->execute(array($canonical_phone, isset($mm['full_name'])?$mm['full_name']:'', 'ETELID_SSO', 'verified', 'active'));
        $stmt->execute(array($last9));
        $user = $stmt->fetch();
    }
    if ($user['status'] !== 'active') ep_err('Account suspended', 403);
    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + EP_SESSION_TTL);
    $pdo->prepare('INSERT INTO ' . EP_TBL_SESSIONS . ' (ep_user_id,token,expires_at,created_at) VALUES (?,?,?,NOW())')
        ->execute(array((int)$user['id'], $token, $expires));
    ep_ok(array('token'=>$token,'expires_at'=>$expires,'phone'=>$user['phone'],'via'=>'etelid_sso'));
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

  return true;
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

