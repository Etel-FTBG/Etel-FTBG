<?php
/**
 * ETEL Gebeya -- Etel Pay bridge for vendor payouts.
 * Pure PHP/MySQL, cPanel compatible -- mirrors the same pattern already
 * proven in ETEL Delivery (driver_cashout) and Etel Pay checkout.
 *
 * Config (add to EtelGebeya/.env):
 *   EG_ETELPAY_SECRET=<same value as Etel.Pay/wallet/api/config.php's EP_SECRET>
 */
if (!defined('DB_HOST')) { http_response_code(403); exit; }

function etelpay_bridge_secret() {
    return $_ENV['EG_ETELPAY_SECRET'] ?? '';
}

function etelpay_bridge_api_url() {
    return $_ENV['EG_ETELPAY_API_URL'] ?? 'https://ethelmarket.com/Etel.Pay/wallet/api/v2_verify.php';
}

function etelpay_bridge_call($payload, $extraHeaders = []) {
    $ch = curl_init(etelpay_bridge_api_url());
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $extraHeaders),
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    if (!$resp) return null;
    $d = json_decode($resp, true);
    return is_array($d) ? $d : null;
}

/** Direct DB connection to the sibling EtelID WordPress install. */
function etelpay_bridge_etelid_db() {
    static $pdo = false;
    if ($pdo !== false) return $pdo ?: null;
    $pdo = null;

    $candidates = [
        ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/EtelId/wp-config.php',
        __DIR__ . '/../../EtelId/wp-config.php',
        __DIR__ . '/../../../EtelId/wp-config.php',
    ];
    foreach ($candidates as $cfg) {
        if (!$cfg || !@is_readable($cfg)) continue;
        $src = @file_get_contents($cfg);
        if (!$src) continue;
        $name = $user = $pass = $host = null;
        if (preg_match("/define\\(\\s*['\"]DB_NAME['\"]\\s*,\\s*['\"]([^'\"]*)['\"]/", $src, $m)) $name = $m[1];
        if (preg_match("/define\\(\\s*['\"]DB_USER['\"]\\s*,\\s*['\"]([^'\"]*)['\"]/", $src, $m)) $user = $m[1];
        if (preg_match("/define\\(\\s*['\"]DB_PASSWORD['\"]\\s*,\\s*['\"]([^'\"]*)['\"]/", $src, $m)) $pass = $m[1];
        if (preg_match("/define\\(\\s*['\"]DB_HOST['\"]\\s*,\\s*['\"]([^'\"]*)['\"]/", $src, $m)) $host = $m[1];
        $prefix = 'wp_';
        if (preg_match("/\\\$table_prefix\\s*=\\s*['\"]([^'\"]+)['\"]/", $src, $m)) $prefix = $m[1];
        if (!$name || !$user) continue;
        try {
            $p = new PDO('mysql:host=' . ($host ?: 'localhost') . ';dbname=' . $name . ';charset=utf8mb4',
                         $user, $pass,
                         [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            $p->eg_prefix = $prefix;
            $pdo = $p;
            return $pdo;
        } catch (Exception $e) {
            error_log('[EtelGebeya][EtelPay] EtelID DB connect: ' . $e->getMessage());
        }
    }
    return null;
}

/** Whoever holds admin/owner/creator for company_slug='gebeya' in EtelID. */
function etelpay_bridge_merchant_phone() {
    static $cache = false;
    if ($cache !== false) return $cache;
    $pdo = etelpay_bridge_etelid_db();
    if (!$pdo) return $cache = null;
    try {
        $prefix = $pdo->eg_prefix ?? 'wp_';
        $s = $pdo->prepare(
            "SELECT m.phone FROM {$prefix}etelid_companies c
             JOIN {$prefix}etelid_members m ON m.id = c.member_id
             WHERE c.company_slug = 'gebeya' AND LOWER(c.role_at) IN ('admin','owner','creator')
             ORDER BY c.linked_at ASC LIMIT 1"
        );
        $s->execute();
        $row = $s->fetch();
        return $cache = ($row ? $row['phone'] : null);
    } catch (Exception $e) {
        error_log('[EtelGebeya][EtelPay] merchant_phone: ' . $e->getMessage());
        return $cache = null;
    }
}

/**
 * Credit a seller's Etel Pay wallet for an approved payout.
 * partner_ref MUST be unique per payout (e.g. "eg_payout_<id>") -- Etel
 * Pay enforces this as a DB unique constraint, so a retried/duplicate
 * call is idempotent and returns the original result instead of paying
 * out twice. Returns ['ok'=>bool, 'tx_ref'=>..., 'message'=>...].
 */
function etelpay_bridge_partner_credit($phone, $amount, $partner_ref, $note = 'EtelGebeya vendor payout') {
    $secret = etelpay_bridge_secret();
    if (!$secret) return ['ok' => false, 'message' => 'EG_ETELPAY_SECRET not configured'];

    $resp = etelpay_bridge_call([
        'action'      => 'ep_partner_credit',
        'phone'       => $phone,
        'amount'      => $amount,
        'partner'     => 'gebeya',
        'partner_ref' => $partner_ref,
        'note'        => $note,
    ], ['X-Etel-Key: ' . $secret]);

    if (!$resp) return ['ok' => false, 'message' => 'No response from Etel Pay'];
    if (empty($resp['success'])) {
        return ['ok' => false, 'message' => $resp['data']['message'] ?? ($resp['message'] ?? 'Etel Pay credit failed')];
    }
    return ['ok' => true, 'tx_ref' => $resp['data']['tx_ref'] ?? null, 'message' => $resp['message'] ?? 'Credited'];
}

/**
 * Move real money from one Etel Pay wallet to another (real debit + credit,
 * unlike partner_credit above which mints new balance). Used for admin
 * dispute resolution -- releasing escrowed order funds to a seller, or
 * refunding a customer -- when a human decides the outcome instead of the
 * automatic customer-confirm / 5-day-timeout flow.
 * partner_ref MUST be unique per transfer attempt (idempotent retries).
 */
function etelpay_bridge_platform_transfer($fromPhone, $toPhone, $amount, $partner_ref, $note = 'EtelGebeya dispute resolution') {
    $secret = etelpay_bridge_secret();
    if (!$secret) return ['ok' => false, 'message' => 'EG_ETELPAY_SECRET not configured'];

    $resp = etelpay_bridge_call([
        'action'      => 'ep_platform_transfer',
        'from_phone'  => $fromPhone,
        'to_phone'    => $toPhone,
        'amount'      => $amount,
        'partner'     => 'etelgebeya',
        'partner_ref' => $partner_ref,
        'note'        => $note,
    ], ['X-Etel-Key: ' . $secret]);

    if (!$resp) return ['ok' => false, 'message' => 'No response from Etel Pay'];
    if (empty($resp['success'])) {
        return ['ok' => false, 'message' => $resp['data']['message'] ?? ($resp['message'] ?? 'Etel Pay transfer failed')];
    }
    return ['ok' => true, 'tx_ref' => $resp['data']['tx_ref'] ?? null, 'message' => $resp['message'] ?? 'Transferred'];
}
