<?php
// ============================================================
// ETEL Delivery — Etel Pay Bridge  v1.0
// Server-to-server verification only. The actual money transfer
// happens client-side (rider authenticates directly to Etel Pay
// with their own PIN and sends the fare) -- this file just lets
// the delivery backend independently confirm that transfer really
// happened before marking a ride/order as paid. Never trust a
// client's bare claim of "I paid."
// ============================================================

if (!defined('ETEL_PAY_URL')) define('ETEL_PAY_URL', 'https://ethelmarket.com/Etel.Pay/wallet/api/v2_verify.php');
if (!defined('ETEL_PAY_SECRET')) {
    // NOTE: this used to call PHP's native parse_ini_file(), which is a
    // strict INI parser -- it can silently fail or mangle values on
    // special characters (?, !, ", etc.) commonly found in real secrets,
    // and one bad line anywhere in the whole .env file can break the
    // entire parse with no warning. Replaced with the same permissive,
    // hand-rolled line parser already used elsewhere in this codebase
    // (see updater.php, v2_verify.php) specifically because of this.
    $_ep_secret = '';
    $_ep_env_file = __DIR__ . '/.env';
    if (file_exists($_ep_env_file)) {
        $_ep_lines = @file($_ep_env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($_ep_lines) {
            foreach ($_ep_lines as $_ep_line) {
                $_ep_line = ltrim($_ep_line, "\xEF\xBB\xBF"); // strip BOM on first line
                $_ep_trimmed = ltrim($_ep_line);
                if ($_ep_trimmed === '' || $_ep_trimmed[0] === '#') continue;
                if (strpos($_ep_trimmed, '=') === false) continue;
                $_ep_parts = explode('=', $_ep_trimmed, 2);
                $_ep_k = trim($_ep_parts[0]);
                $_ep_v = isset($_ep_parts[1]) ? trim($_ep_parts[1]) : '';
                // Strip surrounding quotes if present
                if (strlen($_ep_v) >= 2 &&
                    (($_ep_v[0] === '"' && $_ep_v[strlen($_ep_v)-1] === '"') ||
                     ($_ep_v[0] === "'" && $_ep_v[strlen($_ep_v)-1] === "'"))) {
                    $_ep_v = substr($_ep_v, 1, strlen($_ep_v) - 2);
                }
                if ($_ep_k === 'ETEL_PAY_SECRET') { $_ep_secret = $_ep_v; break; }
            }
        }
    }
    define('ETEL_PAY_SECRET', $_ep_secret);
}

require_once __DIR__ . '/etelid_bridge.php';

/* Delivery's Etel Pay merchant account -- resolved dynamically from
   Etel-ID's etelid_companies table (whoever holds admin/owner/creator
   for company_slug='etel-delivery', the canonical slug defined in
   EtelID_Generator::companies()), instead of a hand-maintained .env
   value. Change who receives fares by editing that one row in Etel-ID
   (the same phpMyAdmin table already used to grant admin access across
   every project this session), not by redeploying every project's .env. */
function etel_pay_merchant_phone() {
    return etelid_bridge_company_admin_phone('etel-delivery');
}

/* Same phone can be written +251936533606, 0936533606, or with spaces/dashes
   depending on which project's table it came from. Compare the last 9
   digits only -- same convention as etelid_bridge.php -- instead of an
   exact string match, or a correctly-completed payment gets rejected here
   purely on formatting. */
function etel_pay_phones_match($a, $b) {
    $da = preg_replace('/[^0-9]/', '', (string) $a);
    $db = preg_replace('/[^0-9]/', '', (string) $b);
    if (!$da || !$db) return false;
    return substr($da, -9) === substr($db, -9);
}

/* Make the actual server-to-server call to Etel Pay. Curl first (far more
   reliable than PHP's http:// stream wrapper on this shared cPanel host --
   see etelid_bridge.php, which already made this switch), with the old
   stream-context approach only as a fallback if curl isn't compiled in.
   Returns the decoded response array, or null on any transport failure. */
function etel_pay_call($tx_ref) {
    $body = json_encode(array('action' => 'ep_verify_tx', 'tx_ref' => $tx_ref));
    $resp = false;
    if (function_exists('curl_init')) {
        $ch = curl_init(ETEL_PAY_URL . '?action=ep_verify_tx');
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => array('Content-Type: application/json',
                                            'X-Etel-Key: ' . ETEL_PAY_SECRET,
                                            'User-Agent: ETEL-Delivery-Bridge/1.0'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ));
        $resp = curl_exec($ch);
        if ($resp === false) {
            error_log('[ETEL][etel_pay_bridge] curl error verifying ' . $tx_ref . ': ' . curl_error($ch));
        }
        curl_close($ch);
    } else {
        $ctx = stream_context_create(array('http' => array(
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nX-Etel-Key: " . ETEL_PAY_SECRET . "\r\nUser-Agent: ETEL-Delivery-Bridge/1.0\r\n",
            'content'       => $body,
            'timeout'       => 10,
            'ignore_errors' => true,
        )));
        $resp = @file_get_contents(ETEL_PAY_URL . '?action=ep_verify_tx', false, $ctx);
    }
    if ($resp === false || $resp === '') return null;
    $data = json_decode($resp, true);
    return is_array($data) ? $data : null;
}

/* Claim a verified Etel Pay tx_ref for exactly ONE purpose (ride, order or
   driver top-up). The per-feature "already used?" SELECTs only look at their
   own table and are racy, so the same transfer could settle a ride AND an
   order AND a top-up. payment_claims.tx_ref is a PRIMARY KEY, so the INSERT is
   the atomic gate: the first caller wins, every other gets false.
   Returns true if this call claimed it, false if it was already claimed. */
function etel_pay_claim_tx($pdo, $tx_ref, $kind) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS payment_claims (
            tx_ref VARCHAR(64) NOT NULL,
            kind VARCHAR(20) NOT NULL,
            claimed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (tx_ref)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) { /* already exists or no DDL rights - the INSERT below decides */ }
    try {
        $pdo->prepare("INSERT INTO payment_claims (tx_ref, kind) VALUES (?, ?)")->execute(array($tx_ref, $kind));
        return true;
    } catch (Exception $e) {
        if ($e instanceof PDOException && $e->getCode() === '23000') return false; // duplicate key = already claimed
        error_log('[ETEL][etel_pay_bridge] claim failed: ' . $e->getMessage());
        return false; // fail closed: never settle money on an unrecorded claim
    }
}

/* Verify a tx_ref actually happened and matches the expected sender/receiver/amount.
   Returns the transaction array on success, or false on any mismatch/failure.
   Every false path is logged with which specific check failed, since this
   used to fail completely silently -- "could not verify" with no way to
   tell whether it was a network hiccup, a phone-format mismatch, or a real
   problem. Retries the network call once on transport failure before
   giving up, since a same-server round-trip occasionally times out
   transiently on shared hosting. */
function etel_pay_verify_tx($tx_ref, $expected_sender_phone, $expected_amount) {
    if (!ETEL_PAY_SECRET) { error_log('[ETEL][etel_pay_bridge] ETEL_PAY_SECRET not configured'); return false; }
    $merchant_phone = etel_pay_merchant_phone();
    if (!$merchant_phone) { error_log('[ETEL][etel_pay_bridge] No admin linked to company_slug=etel-delivery in Etel-ID -- cannot verify merchant receiver'); return false; }
    if (!$tx_ref) { error_log('[ETEL][etel_pay_bridge] verify called with empty tx_ref'); return false; }

    $data = etel_pay_call($tx_ref);
    if ($data === null) {
        // One retry after a short pause -- covers a transient timeout/WAF
        // hiccup on the self-referential HTTPS call without masking a
        // genuinely broken connection (which will just fail again).
        usleep(400000);
        $data = etel_pay_call($tx_ref);
    }
    if ($data === null) {
        error_log('[ETEL][etel_pay_bridge] verify ' . $tx_ref . ': no usable response from Etel Pay after retry (network/WAF/timeout)');
        return false;
    }
    // Etel Pay's API uses {success:...}, not {ok:...} like the other Etel
    // projects' connectors -- do not "fix" this to $data['ok'].
    if (empty($data['success']) || empty($data['data'])) {
        error_log('[ETEL][etel_pay_bridge] verify ' . $tx_ref . ': Etel Pay responded but success=false or no data: ' . substr(json_encode($data), 0, 300));
        return false;
    }

    // The rider's app sends the wallet payment and asks us to verify it in
    // the very next request, with no delay at all. If Etel Pay's own
    // ledger takes even a moment to settle the transaction from
    // "pending"/"processing" to "completed", this call can legitimately
    // arrive before that flip happens -- the money is real and will
    // settle a beat later, but a one-shot check here would wrongly reject
    // it as unverifiable. Give a genuinely pending (not missing, not
    // failed) transaction a few short chances to settle before giving up.
    $tx = $data['data'];
    $settle_attempts = 0;
    while (($tx['status'] ?? '') !== 'completed' && in_array(($tx['status'] ?? ''), array('pending', 'processing'), true) && $settle_attempts < 3) {
        $settle_attempts++;
        usleep(600000); // 0.6s
        $retry_data = etel_pay_call($tx_ref);
        if ($retry_data && !empty($retry_data['success']) && !empty($retry_data['data'])) {
            $tx = $retry_data['data'];
        }
    }
    if ($settle_attempts > 0) {
        error_log('[ETEL][etel_pay_bridge] verify ' . $tx_ref . ': waited for settlement, ' . $settle_attempts . ' extra check(s), final status=' . ($tx['status'] ?? ''));
    }


    if (($tx['status'] ?? '') !== 'completed') {
        error_log('[ETEL][etel_pay_bridge] verify ' . $tx_ref . ': tx status is "' . ($tx['status'] ?? '') . '", not completed');
        return false;
    }
    if (($tx['type'] ?? '') !== 'send') {
        error_log('[ETEL][etel_pay_bridge] verify ' . $tx_ref . ': tx type is "' . ($tx['type'] ?? '') . '", not send');
        return false;
    }
    if (!etel_pay_phones_match($tx['receiver_phone'] ?? '', $merchant_phone)) {
        error_log('[ETEL][etel_pay_bridge] verify ' . $tx_ref . ': receiver phone mismatch (tx=' . ($tx['receiver_phone'] ?? '') . ', merchant=' . $merchant_phone . ')');
        return false;
    }
    if (!etel_pay_phones_match($tx['sender_phone'] ?? '', $expected_sender_phone)) {
        error_log('[ETEL][etel_pay_bridge] verify ' . $tx_ref . ': sender phone mismatch (tx=' . ($tx['sender_phone'] ?? '') . ', expected=' . $expected_sender_phone . ')');
        return false;
    }
    // The rider's app displays and charges the fare rounded to the nearest
    // whole Birr (JS .toFixed(0)) -- fare_final itself can carry a
    // fractional remainder from the distance*rate calculation (e.g.
    // 240.3). Comparing the raw unrounded fare here would reject a
    // genuinely correct, fully-paid transfer as "underpaid" by design.
    // Round the same way the frontend does before comparing.
    $expected_rounded = round($expected_amount);
    if (floatval($tx['amount'] ?? 0) < $expected_rounded - 0.01) {
        error_log('[ETEL][etel_pay_bridge] verify ' . $tx_ref . ': amount too low (tx=' . ($tx['amount'] ?? 0) . ', expected=' . $expected_rounded . ')');
        return false;
    }

    return $tx;
}

/* Credit a driver's Etel Pay wallet for a payout (e.g. cashing out ride
   earnings). $partner_ref MUST be unique per payout attempt -- Etel Pay
   enforces this with a DB constraint, so a network retry with the SAME
   $partner_ref safely returns the original result instead of double
   crediting. Returns ['tx_ref'=>..., 'amount'=>...] on success, or an
   array with an 'error' key on failure -- never silently swallow which
   one happened, since this moves real money. */
function etel_pay_partner_credit($phone, $amount, $partner_ref, $note = 'ETEL Delivery driver payout') {
    if (!ETEL_PAY_SECRET) return array('error' => 'ETEL_PAY_SECRET not configured');
    if (!$phone)          return array('error' => 'phone required');
    if ($amount <= 0)     return array('error' => 'amount must be > 0');
    if (!$partner_ref)    return array('error' => 'partner_ref required');

    $ctx = stream_context_create(array('http' => array(
        'method'        => 'POST',
        'header'        => "Content-Type: application/json\r\nX-Etel-Key: " . ETEL_PAY_SECRET . "\r\n",
        'content'       => json_encode(array(
            'action'      => 'ep_partner_credit',
            'phone'       => $phone,
            'amount'      => $amount,
            'partner'     => 'delivery',
            'partner_ref' => $partner_ref,
            'note'        => $note,
        )),
        'timeout'       => 15,
        'ignore_errors' => true,
    )));
    $resp = @file_get_contents(ETEL_PAY_URL . '?action=ep_partner_credit', false, $ctx);
    if ($resp === false) return array('error' => 'Etel Pay unreachable');
    $data = json_decode($resp, true);
    if (!$data) return array('error' => 'Etel Pay returned an invalid response');
    if (empty($data['success'])) {
        return array('error' => (isset($data['data']['message']) ? $data['data']['message'] : 'Credit failed'));
    }
    return array('tx_ref' => $data['data']['tx_ref'], 'amount' => $data['data']['amount']);
}