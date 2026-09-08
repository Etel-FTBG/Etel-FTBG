<?php
declare(strict_types=1);
namespace App\Core;

/**
 * Order-fund escrow -- shared by checkout, the customer "Confirm Received"
 * action, the daily auto-release cron, and admin dispute resolution.
 *
 * Money is held per (order, seller): at checkout the browser opens a
 * pending hold directly from the customer's Etel Pay wallet to each
 * seller in the cart (see EtelPayClient::verifyEscrowTx() / ep_escrow_send
 * in Etel Pay) -- the customer is debited immediately and the seller sees
 * a "Pending" incoming line, but neither balance settles until release.
 * Only applies to orders paid via the Etel Pay wallet -- cash-on-delivery
 * and the manual Telebirr/CBE instructions never put money in escrow.
 */
final class EscrowService
{
    private Database $db;
    private EtelPayClient $pay;

    public function __construct()
    {
        $this->db  = Database::getInstance();
        $this->pay = new EtelPayClient();
        $this->ensureSchema();
    }

    /**
     * Release every eligible seller-hold on an order: eligible means the
     * seller's items are all delivered, the hold is still 'held', and
     * there's no open dispute on the order. Returns the number released.
     */
    public function releaseOrder(int $orderId, string $reason = 'customer_confirmed'): int
    {
        if ($this->hasOpenDispute($orderId)) return 0;

        $holds = $this->db->fetchAll(
            "SELECT * FROM order_seller_escrow WHERE order_id = ? AND status = 'held'",
            [$orderId]
        );
        $released = 0;
        foreach ($holds as $hold) {
            $undeliveredCount = (int)($this->db->fetchColumn(
                "SELECT COUNT(*) FROM order_items WHERE order_id = ? AND seller_id = ? AND status NOT IN ('delivered','cancelled','returned')",
                [$orderId, $hold['seller_id']]
            ) ?: 0);
            if ($undeliveredCount > 0) continue; // this seller hasn't delivered everything yet

            if ($this->pay->escrowRelease($hold['tx_ref'])) {
                $this->db->query(
                    "UPDATE order_seller_escrow SET status='released', released_at=NOW() WHERE id=?",
                    [$hold['id']]
                );
                $this->db->query(
                    "UPDATE order_items SET escrow_status='released', customer_confirmed_at=COALESCE(customer_confirmed_at, NOW()) WHERE order_id=? AND seller_id=?",
                    [$orderId, $hold['seller_id']]
                );
                $released++;
            }
        }
        if ($released > 0 && !$this->db->fetch("SELECT id FROM order_seller_escrow WHERE order_id=? AND status='held'", [$orderId])) {
            // Every seller-hold on this order is now resolved -- finalize the bookkeeping row.
            $this->db->query("UPDATE transactions SET status='completed' WHERE order_id=? AND type='payment'", [$orderId]);
        }
        return $released;
    }

    /**
     * Reverse every held escrow hold on an order back to the customer --
     * used on cancellation. A no-op for orders with nothing held.
     */
    public function refundOrder(int $orderId): bool
    {
        $holds = $this->db->fetchAll(
            "SELECT * FROM order_seller_escrow WHERE order_id = ? AND status = 'held'",
            [$orderId]
        );
        if (!$holds) return false;

        $anyReversed = false;
        foreach ($holds as $hold) {
            if ($this->pay->escrowReverse($hold['tx_ref'])) {
                $this->db->query(
                    "UPDATE order_seller_escrow SET status='refunded', released_at=NOW() WHERE id=?",
                    [$hold['id']]
                );
                $this->db->query(
                    "UPDATE order_items SET escrow_status='refunded' WHERE order_id=? AND seller_id=?",
                    [$orderId, $hold['seller_id']]
                );
                $anyReversed = true;
            }
        }
        if ($anyReversed) {
            $this->db->query("UPDATE orders SET payment_status='refunded' WHERE id=?", [$orderId]);
            $this->db->query("UPDATE transactions SET status='reversed' WHERE order_id=? AND type='payment'", [$orderId]);
        }
        return $anyReversed;
    }

    /** Record a new pending hold opened at checkout (called by CheckoutController). */
    public function recordHold(int $orderId, int $sellerId, string $txRef, float $amount): void
    {
        $this->db->insert('order_seller_escrow', [
            'order_id'   => $orderId,
            'seller_id'  => $sellerId,
            'tx_ref'     => $txRef,
            'amount'     => $amount,
            'status'     => 'held',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function hasOpenDispute(int $orderId): bool
    {
        $n = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM disputes WHERE order_id=? AND status IN ('open','under_review')",
            [$orderId]
        );
        return (int)$n > 0;
    }

    /** Every held row for an order whose seller has fully delivered -- used to show the "Confirm Received" button. */
    public function hasReleasableHold(int $orderId): bool
    {
        $holds = $this->db->fetchAll("SELECT seller_id FROM order_seller_escrow WHERE order_id=? AND status='held'", [$orderId]);
        foreach ($holds as $hold) {
            $undelivered = (int)($this->db->fetchColumn(
                "SELECT COUNT(*) FROM order_items WHERE order_id=? AND seller_id=? AND status NOT IN ('delivered','cancelled','returned')",
                [$orderId, $hold['seller_id']]
            ) ?: 0);
            if ($undelivered === 0) return true;
        }
        return false;
    }

    /** Defensive schema check -- creates the escrow table/columns this app needs if missing. */
    private function ensureSchema(): void
    {
        static $checked = false;
        if ($checked) return;
        $checked = true;
        if (!$this->db->isConnected()) return;

        try {
            $this->db->pdo()->exec(
                "CREATE TABLE IF NOT EXISTS order_seller_escrow (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    order_id INT UNSIGNED NOT NULL,
                    seller_id INT UNSIGNED NOT NULL,
                    tx_ref VARCHAR(60) NOT NULL,
                    amount DECIMAL(12,2) NOT NULL,
                    status VARCHAR(20) NOT NULL DEFAULT 'held',
                    created_at DATETIME NOT NULL,
                    released_at DATETIME DEFAULT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uk_tx_ref (tx_ref),
                    KEY idx_order (order_id),
                    KEY idx_seller (seller_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (\Throwable $e) {}

        $existing = [];
        try {
            $rows = $this->db->fetchAll(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items'"
            );
            foreach ($rows as $r) { $existing[$r['COLUMN_NAME']] = true; }
        } catch (\Throwable $e) { return; }

        $want = [
            'delivered_at'          => "ALTER TABLE order_items ADD COLUMN delivered_at DATETIME DEFAULT NULL",
            'escrow_status'         => "ALTER TABLE order_items ADD COLUMN escrow_status VARCHAR(20) NOT NULL DEFAULT 'held'",
            'customer_confirmed_at' => "ALTER TABLE order_items ADD COLUMN customer_confirmed_at DATETIME DEFAULT NULL",
        ];
        foreach ($want as $col => $sql) {
            if (!isset($existing[$col])) {
                try { $this->db->pdo()->exec($sql); } catch (\Throwable $e) {}
            }
        }
    }
}
