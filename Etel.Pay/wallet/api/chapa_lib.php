<?php
/* Chapa (https://developer.chapa.co) helpers for wallet top-ups.
   Keys come from api/.env (CHAPA_SECRET_KEY) via ep_env(), never from the
   browser. A top-up is credited ONLY after Chapa's verify API confirms
   status, currency and amount. */

function ep_chapa_secret() {
    $k = ep_env('CHAPA_SECRET_KEY', 'EP_CFG_CHAPA_SECRET_KEY', '');
    if ($k === '' || strpos($k, 'CHASECK_TEST-xxxx') === 0 || ep_is_placeholder($k)) return '';
    return $k;
}

function ep_chapa_enabled() {
    return ep_chapa_secret() !== '';
}

function ep_chapa_request($method, $path, $body = null) {
    $ch = curl_init('https://api.chapa.co/v1' . $path);
    $headers = array('Authorization: Bearer ' . ep_chapa_secret(), 'Accept: application/json');
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
    if ($raw === false) { error_log('[EP-Chapa] ' . $path . ' curl error: ' . $err); return array(); }
    $json = json_decode($raw, true);
    if (!is_array($json)) { error_log('[EP-Chapa] ' . $path . ' HTTP ' . $code . ' non-JSON'); return array(); }
    if ($code >= 400) error_log('[EP-Chapa] ' . $path . ' HTTP ' . $code . ': ' . substr($raw, 0, 300));
    return $json;
}

/* Returns checkout URL or null. */
function ep_chapa_initialize($ref, $amount, $user, $return_url, $callback_url) {
    $name  = trim(isset($user['full_name']) ? $user['full_name'] : '');
    $parts = preg_split('/\s+/', $name, 2);
    $res = ep_chapa_request('POST', '/transaction/initialize', array(
        'amount'       => number_format((float)$amount, 2, '.', ''),
        'currency'     => 'ETB',
        'first_name'   => $parts[0] !== '' ? $parts[0] : 'Etel',
        'last_name'    => isset($parts[1]) ? $parts[1] : 'Pay',
        'phone_number' => isset($user['phone']) ? $user['phone'] : '',
        'tx_ref'       => $ref,
        'callback_url' => $callback_url,
        'return_url'   => $return_url,
        'customization' => array('title' => 'Etel Pay', 'description' => 'Wallet top-up'),
    ));
    if (isset($res['status']) && $res['status'] === 'success' && !empty($res['data']['checkout_url'])) {
        return $res['data']['checkout_url'];
    }
    return null;
}

/* Returns array(status, amount, currency) or null. */
function ep_chapa_verify($ref) {
    $res = ep_chapa_request('GET', '/transaction/verify/' . rawurlencode($ref));
    if (!isset($res['status']) || $res['status'] !== 'success' || !isset($res['data'])) return null;
    return array(
        'status'   => strtolower((string)(isset($res['data']['status']) ? $res['data']['status'] : '')),
        'amount'   => (float)(isset($res['data']['amount']) ? $res['data']['amount'] : 0),
        'currency' => strtoupper((string)(isset($res['data']['currency']) ? $res['data']['currency'] : '')),
    );
}

/* Verify with Chapa, then credit the wallet exactly once.
   Returns 'credited' | 'already' | 'pending' | 'failed' | 'unknown'. */
function ep_chapa_settle($ref) {
    $pdo  = ep_db();
    $stmt = $pdo->prepare('SELECT * FROM ' . EP_TBL_TOPUP . ' WHERE ref=? AND method="chapa" LIMIT 1');
    $stmt->execute(array($ref));
    $req = $stmt->fetch();
    if (!$req) return 'unknown';
    if ($req['status'] === 'completed') return 'already';

    $v = ep_chapa_verify($ref);
    if (!$v || $v['status'] === 'pending') return 'pending';
    if ($v['status'] !== 'success') {
        $pdo->prepare('UPDATE ' . EP_TBL_TOPUP . ' SET status="failed" WHERE ref=? AND status != "completed"')->execute(array($ref));
        return 'failed';
    }
    // Chapa's own figures must match what we asked for.
    if ($v['currency'] !== 'ETB' || abs($v['amount'] - (float)$req['amount']) > 0.01) {
        error_log('[EP-Chapa] amount/currency mismatch for ' . $ref);
        return 'failed';
    }

    $pdo->beginTransaction();
    try {
        // Row-count-gated flip: only one concurrent caller (webhook vs
        // return page) can win, so the credit below happens once.
        $flip = $pdo->prepare('UPDATE ' . EP_TBL_TOPUP . ' SET status="completed" WHERE ref=? AND status != "completed"');
        $flip->execute(array($ref));
        if ($flip->rowCount() === 0) { $pdo->rollBack(); return 'already'; }
        $pdo->prepare('UPDATE ' . EP_TBL_USERS . ' SET balance_etb = balance_etb + ? WHERE id = ?')
            ->execute(array($req['amount'], $req['user_id']));
        $pdo->prepare('INSERT INTO ' . EP_TBL_TX . ' (tx_ref, type, receiver_ep_id, amount, currency, note, status, created_at) VALUES (?,?,?,?,?,?,?,NOW())')
            ->execute(array(ep_gen_ref(), 'deposit', $req['user_id'], $req['amount'], 'ETB', 'Chapa top-up: ' . $ref, 'completed'));
        $pdo->commit();
        return 'credited';
    } catch (Exception $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[EP-Chapa] credit failed for ' . $ref . ': ' . $ex->getMessage());
        return 'failed';
    }
}
