<?php
// ============================================================
// ETEL TAXI - Chapa hosted checkout for association dues
// A dues payment is marked verified ONLY after Chapa's verify API
// confirms status, ETB currency and the exact amount owed. Secret key
// comes from api/.env (CHAPA_SECRET_KEY), never the browser.
// ============================================================

if (!function_exists('taxi_chapa_secret')) {

function taxi_chapa_secret() {
    $k = defined('CHAPA_SECRET_KEY') ? CHAPA_SECRET_KEY : '';
    if ($k === '' || strpos($k, 'CHASECK_TEST-xxxx') === 0) return '';
    return $k;
}

function taxi_chapa_enabled() { return taxi_chapa_secret() !== ''; }

function taxi_chapa_request($method, $path, $body = null) {
    $ch = curl_init('https://api.chapa.co/v1' . $path);
    $headers = array('Authorization: Bearer ' . taxi_chapa_secret(), 'Accept: application/json');
    $opts = array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => $method);
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($raw === false) { error_log('[Taxi-Chapa] ' . $path . ' curl error: ' . $err); return array(); }
    $json = json_decode($raw, true);
    if (!is_array($json)) { error_log('[Taxi-Chapa] ' . $path . ' HTTP ' . $code . ' non-JSON'); return array(); }
    if ($code >= 400) error_log('[Taxi-Chapa] ' . $path . ' HTTP ' . $code . ': ' . substr($raw, 0, 300));
    return $json;
}

// Returns the hosted checkout URL, or null on failure.
function taxi_chapa_initialize($txRef, $amount, $driver, $returnUrl, $callbackUrl) {
    $parts = preg_split('/\s+/', trim(isset($driver['name']) ? $driver['name'] : ''), 2);
    $res = taxi_chapa_request('POST', '/transaction/initialize', array(
        'amount'       => number_format((float)$amount, 2, '.', ''),
        'currency'     => 'ETB',
        'first_name'   => $parts[0] !== '' ? $parts[0] : 'Driver',
        'last_name'    => isset($parts[1]) ? $parts[1] : 'Taxi',
        'phone_number' => isset($driver['phone']) ? $driver['phone'] : '',
        'tx_ref'       => $txRef,
        'callback_url' => $callbackUrl,
        'return_url'   => $returnUrl,
        'customization' => array('title' => 'Etel Taxi', 'description' => 'Association dues'),
    ));
    if (isset($res['status']) && $res['status'] === 'success' && !empty($res['data']['checkout_url'])) {
        return $res['data']['checkout_url'];
    }
    return null;
}

// Returns array(status, amount, currency) or null.
function taxi_chapa_verify($txRef) {
    $res = taxi_chapa_request('GET', '/transaction/verify/' . rawurlencode($txRef));
    if (!isset($res['status']) || $res['status'] !== 'success' || !isset($res['data'])) return null;
    return array(
        'status'   => strtolower((string)(isset($res['data']['status']) ? $res['data']['status'] : '')),
        'amount'   => (float)(isset($res['data']['amount']) ? $res['data']['amount'] : 0),
        'currency' => strtoupper((string)(isset($res['data']['currency']) ? $res['data']['currency'] : '')),
    );
}

// Verify with Chapa, then mark the dues payment verified exactly once.
// Returns 'verified' | 'already' | 'pending' | 'failed' | 'unknown'.
function taxi_chapa_settle($pdo, $txRef) {
    $table = DB_PREFIX . 'taxi_dues_payments';
    $s = $pdo->prepare('SELECT id, amount, penalty_amount, status FROM ' . $table . ' WHERE reference_number = ? AND payment_method = "chapa" LIMIT 1');
    $s->execute(array($txRef));
    $row = $s->fetch();
    if (!$row) return 'unknown';
    if ($row['status'] === 'verified') return 'already';

    $v = taxi_chapa_verify($txRef);
    if (!$v || $v['status'] === 'pending') return 'pending';
    if ($v['status'] !== 'success') return 'failed';
    $expected = (float)$row['amount'] + (float)$row['penalty_amount'];
    if ($v['currency'] !== 'ETB' || abs($v['amount'] - $expected) > 0.01) {
        error_log('[Taxi-Chapa] amount/currency mismatch for ' . $txRef);
        return 'failed';
    }
    // The status flip is the claim: only one of webhook/return wins.
    $u = $pdo->prepare('UPDATE ' . $table . ' SET status = "verified", verified_at = NOW(), notes = "Paid online via Chapa"
                        WHERE id = ? AND status IN ("awaiting_chapa","pending_verification")');
    $u->execute(array($row['id']));
    return $u->rowCount() > 0 ? 'verified' : 'already';
}

}
