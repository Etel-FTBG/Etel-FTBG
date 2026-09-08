<?php
declare(strict_types=1);
namespace App\Core;

/**
 * Order-fund escrow -- shared by the customer "Confirm Received" action,
 * the daily auto-release cron, and admin dispute resolution.
 *
 * Only applies to orders paid via the Etel Pay wallet: that's the only
 * payment method where this backend actually holds custody of real money
 * (it verified a transfer INTO the platform's own wallet at checkout --
 * see EtelPayClient::verifyTx()). Cash-on-delivery and the manual
 * Telebirr/CBE instructions never put money in our custody, so there is
 * nothing to release or reverse for those -- their order/item statuses
 * just update without any wallet transfer.
 */
final class EscrowService
{
    private Database $db;
    private EtelPayClient $pay;

    public function __construct()
    {
        $this->db  = Database::getInstance();
        $this->pay = new EtelPayClient();
        $this->ensureColumns();
    }

    /**
     * Release one seller's escrowed payout for a delivered order item.
     * Safe to call more than once -- a no-op if already released, held by
     * a non-etel_pay order, or blocked by an open dispute.
     */
    public function releaseItem(int $orderItemId, string $reason = 'customer_confirmed'): bool
    {
        $item = $this->db->fetch(
            "SELECT oi.*, o.payment_method, o.order_ref, o.id AS order_id
               FROM order_items oi JOIN orders o ON o.id = oi.order_id
              WHERE oi.id = ? LIMIT 1",
            [$orderItemId]
        );
        if (!$item) return false;
        if ($item['payment_method'] !== 'etel_pay') return false;
        if ($item['status'] !== 'delivered') return false;
        if (($item['escrow_status'] ?? 'held') !== 'held') return false;
        if ($this->hasOpenDispute((int)$item['order_id'])) return false;

        $seller = $this->db->fetch(
            "SELECT phone, payout_method, payout_account FROM sellers WHERE id = ? LIMIT 1",
            [(int)$item['seller_id']]
        );
        if (!$seller) return false;

        $sellerPhone = ($seller['payout_method'] === 'etel_pay' && !empty($seller['payout_account']))
            ? $seller['payout_account']
            : $seller['phone'];
        if (!$sellerPhone) return false;

        $merchantPhone = $this->pay->merchantPhone();
        if (!$merchantPhone) return false;

        $amount = (float)$item['seller_payout'];
        if ($amount <= 0) return false;

        $partnerRef = 'release-item-' . $orderItemId;
        $txRef = $this->pay->transfer(
            $merchantPhone, $sellerPhone, $amount, $partnerRef,
            'Etel Gebeya payout for order ' . $item['order_ref']
        );
        if (!$txRef) return false;

        $this->db->query(
            "UPDATE order_items SET escrow_status='released', payout_tx_ref=?, customer_confirmed_at=COALESCE(customer_confirmed_at, NOW()) WHERE id=?",
            [$txRef, $orderItemId]
        );
        return true;
    }

    /** Release every eligible delivered item on an order in one call. */
    public function releaseOrder(int $orderId): int
    {
        $items = $this->db->fetchAll(
            "SELECT id FROM order_items WHERE order_id=? AND status='delivered' AND escrow_status='held'",
            [$orderId]
        );
        $released = 0;
        foreach ($items as $row) {
            if ($this->releaseItem((int)$row['id'])) $released++;
        }
        return $released;
    }

    /**
     * Reverse an order's escrowed payment back to the customer -- used on
     * cancellation. A no-op for orders that were never actually paid via
     * the wallet, or already refunded/released.
     */
    public function refundOrder(int $orderId): bool
    {
        $order = $this->db->fetch(
            "SELECT o.*, u.phone AS user_phone FROM orders o LEFT JOIN users u ON u.id = o.user_id WHERE o.id = ? LIMIT 1",
            [$orderId]
        );
        if (!$order) return false;
        if ($order['payment_method'] !== 'etel_pay') return false;
        if ($order['payment_status'] !== 'paid') return false;

        $customerPhone = $order['user_phone'] ?? null;
        if (!$customerPhone) return false;

        $merchantPhone = $this->pay->merchantPhone();
        if (!$merchantPhone) return false;

        $amount = (float)$order['total_amount'];
        if ($amount <= 0) return false;

        $partnerRef = 'refund-order-' . $orderId;
        $txRef = $this->pay->transfer(
            $merchantPhone, $customerPhone, $amount, $partnerRef,
            'Etel Gebeya refund for order ' . $order['order_ref']
        );
        if (!$txRef) return false;

        $this->db->query("UPDATE orders SET payment_status='refunded', refund_tx_ref=? WHERE id=?", [$txRef, $orderId]);
        $this->db->query("UPDATE order_items SET escrow_status='refunded' WHERE order_id=? AND escrow_status='held'", [$orderId]);
        return true;
    }

    public function hasOpenDispute(int $orderId): bool
    {
        $n = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM disputes WHERE order_id=? AND status IN ('open','under_review')",
            [$orderId]
        );
        return (int)$n > 0;
    }

    /** Defensive schema check -- adds escrow tracking columns if this is
     *  an older deploy that doesn't have them yet (same pattern used
     *  elsewhere in this app for rejection_reason/verified_at). */
    private function ensureColumns(): void
    {
        static $checked = false;
        if ($checked) return;
        $checked = true;
        if (!$this->db->isConnected()) return;

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
            'payout_tx_ref'         => "ALTER TABLE order_items ADD COLUMN payout_tx_ref VARCHAR(60) DEFAULT NULL",
        ];
        foreach ($want as $col => $sql) {
            if (!isset($existing[$col])) {
                try { $this->db->pdo()->exec($sql); } catch (\Throwable $e) {}
            }
        }

        try {
            $orderCols = [];
            $rows = $this->db->fetchAll(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'"
            );
            foreach ($rows as $r) { $orderCols[$r['COLUMN_NAME']] = true; }
            if (!isset($orderCols['refund_tx_ref'])) {
                try { $this->db->pdo()->exec("ALTER TABLE orders ADD COLUMN refund_tx_ref VARCHAR(60) DEFAULT NULL"); } catch (\Throwable $e) {}
            }
        } catch (\Throwable $e) {}
    }
}
