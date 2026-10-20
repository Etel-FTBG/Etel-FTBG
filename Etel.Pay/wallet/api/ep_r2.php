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
    // Otherwise require a real JPEG magic-byte signature rather than assuming
    // any non-PNG upload is a valid JPEG - cheap, consistent insurance
    // matching the rigor already applied to the PNG case.
    elseif (substr($data, 0, 3) !== "\xFF\xD8\xFF") ep_err('Unsupported image format (PNG or JPEG only)');
    $ext    = ($mime === 'image/png') ? 'png' : 'jpg';
    // The first bytes alone prove nothing (a script can start with them). It must decode as a real image of that type,
    // and is rebuilt from its pixels so nothing hidden inside it is kept (audit, Oct 2026: unrestricted upload).
    $info = function_exists('getimagesizefromstring') ? @getimagesizefromstring($data) : false;
    if (!$info || $info['mime'] !== $mime) ep_err('That file is not a valid image');
    if (preg_match('/<\?php|<\?=|<script[\s>]/i', $data)) ep_err('That file contains code and cannot be accepted');
    if (function_exists('imagecreatefromstring') && function_exists('imagecreatetruecolor')) {
        $src = @imagecreatefromstring($data);
        if (!$src) ep_err('That image could not be processed');
        $w = imagesx($src); $h = imagesy($src);
        $out = imagecreatetruecolor($w, $h);
        if ($mime === 'image/png') { imagealphablending($out, false); imagesavealpha($out, true); imagefilledrectangle($out, 0, 0, $w, $h, imagecolorallocatealpha($out, 0, 0, 0, 127)); }
        imagecopy($out, $src, 0, 0, 0, 0, $w, $h);
        ob_start();
        $okEnc = ($mime === 'image/png') ? imagepng($out, null, 6) : imagejpeg($out, null, 88);
        $clean = ob_get_clean();
        imagedestroy($src); imagedestroy($out);
        if (!$okEnc || !$clean) ep_err('That image could not be processed');
        $data = $clean;
    }
    $dir    = dirname(__DIR__) . '/uploads/avatars';
    if (!file_exists($dir)) @mkdir($dir, 0755, true);
    $guard = dirname(__DIR__) . '/uploads/.htaccess';
    if (!is_file($guard)) @file_put_contents($guard, "Options -Indexes\n<FilesMatch \"\\.(php|phtml|pht|phps|php[0-9]|phar|inc|pl|py|cgi|sh|jsp|asp|aspx|shtml|html?|svg|js)$\">\n    Require all denied\n</FilesMatch>\n");
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
        // Flip the status conditionally on it still being "completed" and
        // check the actual affected-row count before touching any balance -
        // two concurrent reverse clicks both reading the same completed row
        // above would otherwise both credit/debit balances, double-
        // processing the reversal even though the status flip looks
        // idempotent on its own. Only the request that wins this UPDATE
        // proceeds to move money.
        $flip = $pdo->prepare('UPDATE ' . EP_TBL_TX . ' SET status = "reversed" WHERE id = ? AND status = "completed"');
        $flip->execute(array((int)$tx['id']));
        if ($flip->rowCount() === 0) {
            $pdo->rollBack();
            ep_err('Transaction already reversed');
        }

        if ($tx['sender_ep_id']) {
            $pdo->prepare('UPDATE ' . EP_TBL_USERS . ' SET balance_etb = balance_etb + ? WHERE id = ?')->execute(array((float)$tx['amount'], (int)$tx['sender_ep_id']));
        }
        if ($tx['receiver_ep_id']) {
            $pdo->prepare('UPDATE ' . EP_TBL_USERS . ' SET balance_etb = GREATEST(0, balance_etb - ?) WHERE id = ?')->execute(array((float)$tx['amount'], (int)$tx['receiver_ep_id']));
        }
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

    if ($method === 'chapa' && !ep_chapa_enabled()) {
        $pdo->prepare('UPDATE ' . EP_TBL_TOPUP . ' SET status="failed" WHERE ref=?')->execute(array($ref));
        ep_err('Chapa is not configured yet', 503);
    }
    // ── Chapa hosted checkout (cards, Telebirr, CBE Birr, banks) ──
    if ($method === 'chapa' && ep_chapa_enabled()) {
        $base = defined('EP_BASE_URL') ? EP_BASE_URL : 'https://ethelmarket.com';
        $me   = ep_auth();
        $url  = ep_chapa_initialize(
            $ref, $amount, $me,
            rtrim($base, '/') . '/Etel.Pay/wallet/topup?chapa_ref=' . rawurlencode($ref),
            rtrim($base, '/') . '/Etel.Pay/wallet/api/v2_verify.php?action=ep_chapa_webhook'
        );
        if ($url) {
            $pdo->prepare('UPDATE ' . EP_TBL_TOPUP . ' SET status="processing", gateway_ref=? WHERE ref=?')->execute(array($ref, $ref));
            ep_ok(array('ref'=>$ref,'method'=>'chapa','redirect_url'=>$url,'amount'=>$amount), 'Redirecting to Chapa');
        }
        $pdo->prepare('UPDATE ' . EP_TBL_TOPUP . ' SET status="failed" WHERE ref=?')->execute(array($ref));
        ep_err('Chapa is unavailable right now. Please try another method.', 502);
    }

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
            'etelpay' => array(
                'en' => 'Hand ETB ' . $amount . ' cash to an ኢE Pay admin in person, reference: ' . $ref,
                'am' => 'ETB ' . $amount . ' ጥሬ ገንዘብ ለኢE Pay አስተዳዳሪ በአካል ያስረክቡ። ማጣቀሻ: ' . $ref,
            ),
        ),
        'confirm_url' => 'After payment, tap Confirm to notify us',
    ), 'Top-up request created');
}

/* ── 1a. Chapa: webhook (no session; trust comes from HMAC + verify API) ── */
case 'ep_chapa_webhook': {
    $secret = ep_chapa_secret();
    $raw    = file_get_contents('php://input') ?: '';
    $sig    = isset($_SERVER['HTTP_CHAPA_SIGNATURE']) ? $_SERVER['HTTP_CHAPA_SIGNATURE']
            : (isset($_SERVER['HTTP_X_CHAPA_SIGNATURE']) ? $_SERVER['HTTP_X_CHAPA_SIGNATURE'] : '');
    if ($secret === '' || $sig === '') ep_err('Chapa webhook not configured', 503);
    if (!hash_equals(hash_hmac('sha256', $raw, $secret), (string)$sig)) ep_err('Invalid signature', 401);
    $evt = json_decode($raw, true);
    $ref = is_array($evt) && isset($evt['tx_ref']) ? (string)$evt['tx_ref'] : '';
    if ($ref === '') ep_err('Missing tx_ref', 422);
    ep_ok(array('result' => ep_chapa_settle($ref)));
}

/* ── 1a'. Chapa: customer came back -- verify now, don't wait for webhook ── */
case 'ep_topup_chapa_status': {
    $uid = ep_auth_id();
    $ref = in_str('ref');
    if (!$ref) ep_err('Ref required');
    $stmt = ep_db()->prepare('SELECT user_id FROM ' . EP_TBL_TOPUP . ' WHERE ref=? LIMIT 1');
    $stmt->execute(array($ref));
    $row = $stmt->fetch();
    if (!$row || (int)$row['user_id'] !== $uid) ep_err('Top-up request not found', 404);
    $result = ep_chapa_settle($ref);
    ep_ok(array('ref' => $ref, 'result' => $result));
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
        // Same double-processing risk as ep_admin_reverse_tx above: gate on
        // the actual row-count of a status flip that can only succeed once,
        // before crediting any balance, instead of trusting the plain
        // SELECT above (which two concurrent approve clicks could both
        // pass while the request is still "pending").
        $flip = $pdo->prepare('UPDATE ' . EP_TBL_TOPUP . ' SET status="completed" WHERE ref=? AND status != "completed"');
        $flip->execute(array($ref));
        if ($flip->rowCount() === 0) {
            $pdo->rollBack();
            ep_err('Already completed');
        }

        $pdo->prepare('UPDATE ' . EP_TBL_USERS . ' SET balance_etb = balance_etb + ? WHERE id = ?')
            ->execute(array($req['amount'], $req['user_id']));
        $pdo->prepare('INSERT INTO ' . EP_TBL_TX . ' (tx_ref, type, receiver_ep_id, amount, currency, note, status, created_at) VALUES (?,?,?,?,?,?,?,NOW())')
            ->execute(array(ep_gen_ref(), 'deposit', $req['user_id'], $req['amount'], 'ETB', 'Top-up: '.$ref, 'completed'));
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
