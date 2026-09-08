<?php
declare(strict_types=1);
namespace App\Core;

/**
 * ኢE Pay bridge for EtelGebeya checkout.
 *
 * The browser talks to Etel Pay's own API directly (phone+PIN login,
 * then a real transfer) -- this class never sees the customer's PIN.
 * Its only job is server-to-server verification: given the tx_ref the
 * browser got back, independently confirm with Etel Pay that the
 * transfer really happened, for the right amount, to the right
 * merchant, before an order is ever marked paid. Never trust the
 * client's claim alone (same pattern already proven in ETEL Delivery).
 */
final class EtelPayClient
{
    private string $apiUrl;
    private string $secret;

    public function __construct()
    {
        $cfg          = $GLOBALS['__config']['etel_pay'] ?? [];
        $this->apiUrl = $cfg['api_url'] ?? 'https://ethelmarket.com/Etel.Pay/wallet/api/v2_verify.php';
        $this->secret = $cfg['secret'] ?? '';
    }

    /**
     * Resolve the phone number of whoever holds admin/owner/creator for
     * company_slug='gebeya' in EtelID -- the receiving wallet for
     * checkout payments. Reads EtelID's own sibling WordPress database
     * directly (same server, same pattern already used by ETEL Delivery),
     * so the merchant phone can be changed by editing that one row in
     * EtelID instead of redeploying this project.
     */
    public function merchantPhone(): ?string
    {
        static $cache = null;
        if ($cache !== null) return $cache === '' ? null : $cache;

        // Preferred: official EtelID Service API (no raw DB credentials
        // needed on this side).
        try {
            $viaService = (new EtelIDClient())->companyAdminPhone('gebeya');
            if ($viaService) { $cache = $viaService; return $cache; }
        } catch (\Throwable $e) {
            error_log('[EtelGebeya][EtelPay] merchantPhone via service API: ' . $e->getMessage());
        }

        // Fallback: direct DB (older EtelID deploys without this route yet)
        $pdo = $this->etelIdDb();
        if (!$pdo) { $cache = ''; return null; }

        try {
            $prefix = $pdo->_eg_prefix ?? 'wp_';
            $stmt = $pdo->prepare(
                "SELECT m.phone FROM {$prefix}etelid_companies c
                 JOIN {$prefix}etelid_members m ON m.id = c.member_id
                 WHERE c.company_slug = 'gebeya' AND LOWER(c.role_at) IN ('admin','owner','creator')
                 ORDER BY c.linked_at ASC LIMIT 1"
            );
            $stmt->execute();
            $row = $stmt->fetch();
            $cache = $row ? $row['phone'] : '';
            return $cache === '' ? null : $cache;
        } catch (\Throwable $e) {
            error_log('[EtelGebeya][EtelPay] merchantPhone: ' . $e->getMessage());
            $cache = '';
            return null;
        }
    }

    /**
     * Independently verify a wallet transaction with Etel Pay before
     * trusting it. Returns the verified tx row on success, or null.
     * Checks sender, receiver (must be OUR merchant wallet), amount and
     * status -- a customer reporting a tx_ref for a payment sent to some
     * other account, or for the wrong amount, must not pass here.
     */
    public function verifyTx(string $txRef, string $expectedSenderPhone, float $expectedAmount): ?array
    {
        if (!$this->secret || !$txRef) return null;

        $merchantPhone = $this->merchantPhone();
        if (!$merchantPhone) return null;

        $resp = $this->post(['action' => 'ep_verify_tx', 'tx_ref' => $txRef], [
            'X-Etel-Key: ' . $this->secret,
        ]);
        if (!$resp || empty($resp['success']) || empty($resp['data'])) return null;

        $tx = $resp['data'];
        if (($tx['status'] ?? '') !== 'completed') return null;
        if (self::normalizePhone($tx['sender_phone'] ?? '') !== self::normalizePhone($expectedSenderPhone)) return null;
        if (self::normalizePhone($tx['receiver_phone'] ?? '') !== self::normalizePhone($merchantPhone)) return null;
        if (abs((float)($tx['amount'] ?? 0) - $expectedAmount) > 0.01) return null;

        return $tx;
    }

    /**
     * Independently verify a pending escrow hold (created client-side via
     * ep_escrow_send) before trusting it at checkout: the money must
     * already be debited from the customer, held (status=pending, not
     * completed -- that would mean it somehow already reached someone),
     * sent to the RIGHT seller, for the RIGHT amount.
     */
    public function verifyEscrowTx(string $txRef, string $expectedSenderPhone, string $expectedReceiverPhone, float $expectedAmount): ?array
    {
        if (!$this->secret || !$txRef) return null;

        $resp = $this->post(['action' => 'ep_verify_tx', 'tx_ref' => $txRef], [
            'X-Etel-Key: ' . $this->secret,
        ]);
        if (!$resp || empty($resp['success']) || empty($resp['data'])) return null;

        $tx = $resp['data'];
        if (($tx['status'] ?? '') !== 'pending') return null;
        if (self::normalizePhone($tx['sender_phone'] ?? '') !== self::normalizePhone($expectedSenderPhone)) return null;
        if (self::normalizePhone($tx['receiver_phone'] ?? '') !== self::normalizePhone($expectedReceiverPhone)) return null;
        if (abs((float)($tx['amount'] ?? 0) - $expectedAmount) > 0.01) return null;

        return $tx;
    }

    /** Release a pending escrow hold to its receiver (the seller). Returns true on success. */
    public function escrowRelease(string $txRef): bool
    {
        return $this->escrowResolve('ep_escrow_release', $txRef);
    }

    /** Reverse a pending escrow hold back to its original sender (the customer). Returns true on success. */
    public function escrowReverse(string $txRef): bool
    {
        return $this->escrowResolve('ep_escrow_reverse', $txRef);
    }

    private function escrowResolve(string $action, string $txRef): bool
    {
        if (!$this->secret || !$txRef) return false;
        $resp = $this->post(['action' => $action, 'tx_ref' => $txRef], ['X-Etel-Key: ' . $this->secret]);
        if (!$resp || empty($resp['success'])) {
            error_log("[EtelGebeya][EtelPay] $action failed for $txRef: " . json_encode($resp));
            return false;
        }
        return true;
    }

    /**
     * Move real money from one Etel Pay wallet to another, server-to-server
     * -- used to release escrowed order funds to a seller, or reverse them
     * back to a customer on cancellation. $partnerRef must be unique per
     * transfer attempt (Etel Pay uses it to make retries safe -- calling
     * again with the same ref returns the original result instead of
     * moving money twice). Returns the Etel Pay tx_ref on success, or null.
     */
    public function transfer(string $fromPhone, string $toPhone, float $amount, string $partnerRef, string $note): ?string
    {
        if (!$this->secret || !$fromPhone || !$toPhone || $amount <= 0 || !$partnerRef) return null;

        $resp = $this->post([
            'action'      => 'ep_platform_transfer',
            'from_phone'  => $fromPhone,
            'to_phone'    => $toPhone,
            'amount'      => $amount,
            'partner'     => 'etelgebeya',
            'partner_ref' => $partnerRef,
            'note'        => $note,
        ], ['X-Etel-Key: ' . $this->secret]);

        if (!$resp || empty($resp['success']) || empty($resp['data']['tx_ref'])) {
            error_log('[EtelGebeya][EtelPay] transfer failed: ' . json_encode($resp));
            return null;
        }
        return (string)$resp['data']['tx_ref'];
    }

    private function post(array $payload, array $extraHeaders = []): ?array
    {
        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $extraHeaders),
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        if (!$resp) return null;
        $data = json_decode($resp, true);
        return is_array($data) ? $data : null;
    }

    private static function normalizePhone(string $p): string
    {
        $p = preg_replace('/\s+/', '', $p) ?? '';
        if (preg_match('/^09\d{8}$/', $p))   return '+251' . substr($p, 1);
        if (preg_match('/^2519\d{8}$/', $p)) return '+' . $p;
        if (preg_match('/^9\d{8}$/', $p))    return '+251' . $p;
        return $p;
    }

    /** Direct DB connection to the sibling EtelID WordPress install. */
    private function etelIdDb(): ?\PDO
    {
        static $pdo = false;
        if ($pdo !== false) return $pdo ?: null;
        $pdo = null;

        $candidates = [
            ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/EtelId/wp-config.php',
            __DIR__ . '/../../../EtelId/wp-config.php',
            __DIR__ . '/../../../../EtelId/wp-config.php',
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
                $p = new \PDO(
                    'mysql:host=' . ($host ?: 'localhost') . ';dbname=' . $name . ';charset=utf8mb4',
                    $user, $pass,
                    [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]
                );
                $p->_eg_prefix = $prefix;
                $pdo = $p;
                return $pdo;
            } catch (\Throwable $e) {
                error_log('[EtelGebeya][EtelPay] EtelID DB connect: ' . $e->getMessage());
            }
        }
        return null;
    }
}
