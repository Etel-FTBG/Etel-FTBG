<?php
// ============================================================
// ETEL Delivery & Ride - Chapa hosted checkout  v1.0
//
// Pays a ride fare or a delivery order through Chapa
// (https://developer.chapa.co). Money is only ever treated as received
// after Chapa's verify API confirms status, ETB currency and the exact
// amount THIS server expects (never a client-supplied figure).
// Secret key: CHAPA_SECRET_KEY in api/.env.
//
// Shared by controllers/ChapaController.php (chapa_init / chapa_status)
// and chapa-callback.php (Chapa webhook) - both settle through
// chapa_settle(), whose status flip makes it safe to run twice.
// ============================================================

require_once __DIR__ . '/lib/schema_helpers.php';
require_once __DIR__ . '/lib/ride_fare.php';

function chapa_secret() {
    $k = '';
    if (function_exists('etel_env')) $k = etel_env('CHAPA_SECRET_KEY', 'CFG_CHAPA_SECRET_KEY', '');
    elseif (defined('CHAPA_SECRET_KEY')) $k = CHAPA_SECRET_KEY;
    if ($k === '' || strpos($k, 'CHASECK_TEST-xxxx') === 0 || strtolower($k) === 'change_me') return '';
    return $k;
}
function chapa_enabled() { return chapa_secret() !== ''; }

function chapa_base_url() {
    $b = function_exists('etel_env') ? etel_env('ETEL_BASE_URL', 'CFG_ETEL_BASE_URL', '') : '';
    return rtrim($b !== '' ? $b : 'https://ethelmarket.com/delivery', '/');
}

function chapa_request($method, $path, $body = null) {
    $ch = curl_init('https://api.chapa.co/v1' . $path);
    $headers = array('Authorization: Bearer ' . chapa_secret(), 'Accept: application/json');
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
    if ($raw === false) { error_log('[Delivery-Chapa] ' . $path . ' curl error: ' . $err); return array(); }
    $json = json_decode($raw, true);
    if (!is_array($json)) { error_log('[Delivery-Chapa] ' . $path . ' HTTP ' . $code . ' non-JSON'); return array(); }
    if ($code >= 400) error_log('[Delivery-Chapa] ' . $path . ' HTTP ' . $code . ': ' . substr($raw, 0, 300));
    return $json;
}

function chapa_ensure_table($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS chapa_payments (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        tx_ref VARCHAR(60) NOT NULL,
        kind VARCHAR(10) NOT NULL,
        target_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        completed_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uk_tx_ref (tx_ref),
        KEY idx_target (kind, target_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// Returns the hosted checkout URL, or null on failure.
function chapa_initialize($txRef, $amount, $user, $returnUrl, $callbackUrl) {
    $parts = preg_split('/\s+/', trim(isset($user['name']) ? $user['name'] : ''), 2);
    $res = chapa_request('POST', '/transaction/initialize', array(
        'amount'       => number_format((float)$amount, 2, '.', ''),
        'currency'     => 'ETB',
        'first_name'   => $parts[0] !== '' ? $parts[0] : 'Etel',
        'last_name'    => isset($parts[1]) ? $parts[1] : 'Customer',
        'phone_number' => isset($user['phone']) ? $user['phone'] : '',
        'tx_ref'       => $txRef,
        'callback_url' => $callbackUrl,
        'return_url'   => $returnUrl,
        'customization' => array('title' => 'Etel Delivery', 'description' => 'Ride / order payment'),
    ));
    if (isset($res['status']) && $res['status'] === 'success' && !empty($res['data']['checkout_url'])) {
        return $res['data']['checkout_url'];
    }
    return null;
}

// Returns array(status, amount, currency) or null.
function chapa_verify($txRef) {
    $res = chapa_request('GET', '/transaction/verify/' . rawurlencode($txRef));
    if (!isset($res['status']) || $res['status'] !== 'success' || !isset($res['data'])) return null;
    return array(
        'status'   => strtolower((string)(isset($res['data']['status']) ? $res['data']['status'] : '')),
        'amount'   => (float)(isset($res['data']['amount']) ? $res['data']['amount'] : 0),
        'currency' => strtoupper((string)(isset($res['data']['currency']) ? $res['data']['currency'] : '')),
    );
}

// Verify with Chapa, then settle the ride/order exactly once.
// Returns 'completed' | 'already' | 'pending' | 'failed' | 'unknown'.
function chapa_settle($pdo, $txRef) {
    chapa_ensure_table($pdo);
    $s = $pdo->prepare('SELECT * FROM chapa_payments WHERE tx_ref = ? LIMIT 1');
    $s->execute(array($txRef));
    $p = $s->fetch();
    if (!$p) return 'unknown';
    if ($p['status'] === 'completed') return 'already';

    $v = chapa_verify($txRef);
    if (!$v || $v['status'] === 'pending') return 'pending';
    if ($v['status'] !== 'success') {
        $pdo->prepare("UPDATE chapa_payments SET status='failed' WHERE id=? AND status='pending'")->execute(array($p['id']));
        return 'failed';
    }
    if ($v['currency'] !== 'ETB' || abs($v['amount'] - (float)$p['amount']) > 0.01) {
        error_log('[Delivery-Chapa] amount/currency mismatch for ' . $txRef);
        return 'failed';
    }

    // Claim first: only one of webhook/return proceeds past this point.
    $claim = $pdo->prepare("UPDATE chapa_payments SET status='completed', completed_at=NOW() WHERE id=? AND status <> 'completed'");
    $claim->execute(array($p['id']));
    if ($claim->rowCount() < 1) return 'already';

    $target = intval($p['target_id']);
    try {
        if ($p['kind'] === 'ride') {
            try { $pdo->exec("ALTER TABLE rides ADD COLUMN wallet_tx_ref VARCHAR(40) NULL"); } catch (Exception $e) {}
            $r = $pdo->prepare("SELECT id, driver_id, fare_final FROM rides WHERE id=?");
            $r->execute(array($target));
            $ride = $r->fetch();
            if ($ride) {
                // 'chapa' + a verified tx_ref is what makes rides_complete_and_pay
                // credit the driver as a platform-collected (non-cash) fare.
                rides_complete_and_pay($pdo, $target, $ride['driver_id'], (float)$p['amount'], 'chapa', substr($txRef, 0, 40));
            }
        } else {
            $pdo->prepare("UPDATE orders SET payment_status='paid' WHERE id=? AND payment_status <> 'paid'")->execute(array($target));
        }
        try {
            $pdo->prepare("UPDATE customer_referral_rewards SET status='paid', paid_at=NOW() WHERE referred_user_id=? AND status='pending'")
                ->execute(array($p['user_id']));
        } catch (Exception $e) { /* table may not exist on an unmigrated install */ }
    } catch (Exception $e) {
        // Money is confirmed but fulfilment failed: release the claim so a retry can finish it.
        $pdo->prepare("UPDATE chapa_payments SET status='pending', completed_at=NULL WHERE id=?")->execute(array($p['id']));
        error_log('[Delivery-Chapa] settle failed for ' . $txRef . ': ' . $e->getMessage());
        return 'failed';
    }
    return 'completed';
}
