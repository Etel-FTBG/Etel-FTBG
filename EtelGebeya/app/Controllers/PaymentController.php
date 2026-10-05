<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Database;

/**
 * PaymentController -- return/notify endpoints for the three gateway-style
 * checkout options (Telebirr, CBE, Chapa/bank_transfer). Routes registered
 * in public/index.php under /payments/*.
 *
 * None of these three are wired up as live automated gateways from the
 * checkout side today (see config/app.php's payments.telebirr/cbe/chapa
 * comments) -- Telebirr and CBE are explicitly "customer sees pay-by-
 * reference instructions, an admin confirms manually" by product design
 * (checkout_success.php's own copy says as much), and Chapa's config is
 * scaffolded but CheckoutController never calls Chapa's API to open a
 * hosted checkout page. That's why these classes never existed: the
 * routes were pre-registered for a later integration.
 *
 * What actually needed fixing right now was that hitting any of these six
 * URLs 500'd with "Class not found" -- including Telebirr/CBE/Chapa's
 * real payment-gateway callbacks, if a live one is ever pointed at this
 * host before the corresponding checkout-side integration is finished.
 * These handlers make that safe: they never crash, never mark an order
 * paid without verifying who's asking, and never trust a client-supplied
 * amount over what the order actually charged.
 *
 * - Chapa's real webhook signature scheme (HMAC-SHA256 of the raw body
 *   using the account's secret key, sent as the `Chapa-Signature` header)
 *   is implemented for real, since Chapa is a live, documented Ethiopian
 *   aggregator and this is their public integration contract.
 * - Telebirr/CBE have no such public webhook spec to implement against
 *   (their real merchant APIs require a signed business agreement this
 *   codebase doesn't have credentials for) -- their notify endpoints
 *   accept a manually-configured shared secret instead (config's
 *   payments.telebirr/cbe.webhook_secret) and are disabled (503) until
 *   one is set, rather than pretending to verify a protocol that was
 *   never actually integrated.
 */
final class PaymentController
{
    private Database $db;
    private array $config;

    public function __construct()
    {
        $this->db     = Database::getInstance();
        $this->config = $GLOBALS['__config']['payments'] ?? [];
    }

    /* -- GET /payments/telebirr/return -- */
    public function telebirrReturn(): void
    {
        $this->genericReturn();
    }

    /* -- GET /payments/cbe/return -- */
    public function cbeReturn(): void
    {
        $this->genericReturn();
    }

    /* -- GET /payments/chapa/return -- */
    public function chapaReturn(): void
    {
        // Chapa's own hosted-checkout return redirect appends tx_ref.
        // The redirect itself proves nothing, but the webhook can lag the
        // customer's browser -- so ask Chapa directly (verify API, using
        // the secret key) and settle here too. Idempotent with the
        // webhook via settleOrder()'s payment_status guard.
        $ref = trim((string)($_GET['tx_ref'] ?? ''));
        $chapa = new \App\Core\ChapaClient();
        if ($ref !== '' && $chapa->isConfigured()) {
            $v = $chapa->verify($ref);
            if ($v && $v['status'] === 'success' && $v['currency'] === 'ETB') {
                $this->applySettlement('chapa', $ref, $v['amount'], true);
            }
        }
        $this->genericReturn(['tx_ref', 'order_ref', 'ref', 'code']);
    }

    /**
     * A gateway return is just a browser redirect after the customer
     * finishes on the gateway's own hosted page -- nothing here should be
     * trusted as proof of payment (that's what notify/webhook is for).
     * This only decides where to send the customer's browser next.
     */
    private function genericReturn(array $refKeys = ['order_ref', 'ref', 'code', 'tx_ref']): void
    {
        $ref = '';
        foreach ($refKeys as $key) {
            if (!empty($_GET[$key])) { $ref = trim((string)$_GET[$key]); break; }
        }
        if ($ref !== '') {
            $order = $this->db->fetch("SELECT order_ref FROM orders WHERE order_ref = ? LIMIT 1", [$ref]);
            if ($order) {
                redirect('/checkout/success/' . $order['order_ref']);
            }
        }
        redirect('/');
    }

    /* -- POST /payments/telebirr/notify -- */
    public function telebirrNotify(): void
    {
        $this->sharedSecretNotify('telebirr');
    }

    /* -- POST /payments/cbe/notify -- */
    public function cbeNotify(): void
    {
        $this->sharedSecretNotify('cbe');
    }

    /**
     * Placeholder verification for a gateway with no implemented public
     * webhook spec (see class docblock). Requires:
     *   - payments.<gateway>.webhook_secret configured (non-empty)
     *   - X-Webhook-Secret header matching it exactly (hash_equals)
     *   - a JSON or form body with order_ref/ref/code and amount
     * Never marks an order paid on unauthenticated or mismatched input.
     */
    private function sharedSecretNotify(string $gateway): void
    {
        $secret = (string)($this->config[$gateway]['webhook_secret'] ?? '');
        if ($secret === '') {
            $this->jsonResponse(503, ['ok' => false, 'message' => "$gateway webhook not configured"]);
        }

        $given = $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '';
        if (!is_string($given) || !hash_equals($secret, $given)) {
            $this->jsonResponse(401, ['ok' => false, 'message' => 'Invalid webhook secret']);
        }

        $body = $this->parseBody();
        $ref    = (string)($body['order_ref'] ?? $body['ref'] ?? $body['code'] ?? '');
        $amount = (float)($body['amount'] ?? 0);
        $status = strtolower((string)($body['status'] ?? 'success'));

        $this->settleOrder($gateway, $ref, $amount, $status === 'success' || $status === 'paid' || $status === 'completed');
    }

    /* -- POST /payments/chapa/notify -- */
    public function chapaNotify(): void
    {
        $secretKey = (string)($this->config['chapa']['secret_key'] ?? '');
        $raw       = file_get_contents('php://input') ?: '';
        $signature = $_SERVER['HTTP_CHAPA_SIGNATURE'] ?? ($_SERVER['HTTP_X_CHAPA_SIGNATURE'] ?? '');

        // A default/placeholder key (config's own sandbox fallback) can
        // never be a real Chapa account secret, so verifying against it
        // would be meaningless -- treat it the same as unconfigured.
        if ($secretKey === '' || str_starts_with($secretKey, 'CHASECK_TEST-xxxx') || !is_string($signature) || $signature === '') {
            $this->jsonResponse(503, ['ok' => false, 'message' => 'Chapa webhook not configured']);
        }

        $expected = hash_hmac('sha256', $raw, $secretKey);
        if (!hash_equals($expected, (string)$signature)) {
            $this->jsonResponse(401, ['ok' => false, 'message' => 'Invalid signature']);
        }

        $body   = json_decode($raw, true);
        $body   = is_array($body) ? $body : [];
        $ref    = (string)($body['tx_ref'] ?? '');
        $amount = (float)($body['amount'] ?? 0);
        $status = strtolower((string)($body['status'] ?? ''));

        // Signature proves the sender; verify API proves the money. Use
        // Chapa's own amount, not the webhook body's.
        if ($status === 'success') {
            $v = (new \App\Core\ChapaClient())->verify($ref);
            if (!$v || $v['status'] !== 'success' || $v['currency'] !== 'ETB') {
                $this->jsonResponse(422, ['ok' => false, 'message' => 'Chapa verification failed']);
            }
            $amount = $v['amount'];
        }
        $this->settleOrder('chapa', $ref, $amount, $status === 'success');
    }

    /**
     * Shared settlement path for every notify handler: look up the order
     * by its own order_ref (the exact reference checkout_success.php
     * already tells the customer to use), verify the gateway's reported
     * amount actually matches what the order charges (never trust the
     * caller's amount as the source of truth), and flip payment_status
     * atomically -- the "AND payment_status != 'paid'" guard means a
     * retried webhook delivery is a safe no-op, not a double-process.
     */
    private function settleOrder(string $gateway, string $ref, float $amount, bool $success): void
    {
        [$code, $data] = $this->applySettlement($gateway, $ref, $amount, $success);
        $this->jsonResponse($code, $data);
    }

    /** Same checks as settleOrder() but returns [httpCode, body] instead of exiting. */
    private function applySettlement(string $gateway, string $ref, float $amount, bool $success): array
    {
        if ($ref === '') {
            return [422, ['ok' => false, 'message' => 'Missing order reference']];
        }
        $order = $this->db->fetch(
            "SELECT id, total_amount, payment_status FROM orders WHERE order_ref = ? LIMIT 1",
            [$ref]
        );
        if (!$order) {
            return [404, ['ok' => false, 'message' => 'Order not found']];
        }
        if (!$success) {
            return [200, ['ok' => true, 'message' => 'Acknowledged (payment not successful)']];
        }
        if (abs((float)$order['total_amount'] - $amount) > 0.5) {
            return [422, ['ok' => false, 'message' => 'Amount mismatch']];
        }

        $updated = $this->db->update(
            'orders',
            ['payment_status' => 'paid', 'paid_at' => date('Y-m-d H:i:s'), 'payment_ref' => $ref],
            'id = ? AND payment_status != ?',
            [$order['id'], 'paid']
        );
        if ($updated > 0) {
            // Keep the admin Transactions tab in step with the order row
            // (live transactions.status is pending|completed|failed|reversed).
            try {
                $this->db->query(
                    "UPDATE transactions SET status = 'completed', updated_at = ? WHERE order_id = ? AND method = ?",
                    [date('Y-m-d H:i:s'), $order['id'], $gateway === 'chapa' ? 'bank_transfer' : $gateway]
                );
            } catch (\Throwable $e) {
                error_log('[EG-Payment] transactions sync: ' . $e->getMessage());
            }
        }
        return [200, ['ok' => true, 'updated' => $updated > 0]];
    }

    private function parseBody(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $json = json_decode($raw, true);
        if (is_array($json)) return $json;
        return $_POST ?: [];
    }

    private function jsonResponse(int $code, array $data): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}
