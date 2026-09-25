<?php
declare(strict_types=1);
namespace App\Core;

/**
 * Shared order-item -> order status bubbling, used by both
 * VendorController (sellers moving an item through confirmed/shipped/
 * delivered) and CourierController (couriers moving shipped -> delivered).
 * Extracted out of VendorController so both callers share one
 * implementation instead of drifting apart.
 */
final class OrderFulfillment
{
    /**
     * Keeps the customer-facing orders.status in sync with what sellers
     * (and couriers) actually report at the item level -- it must never
     * be the only thing that says "delivered" (escrow release depends on
     * order_items.status, not this), but leaving it stuck on "new"
     * forever while every item ships is equally wrong. Whole-order
     * status = the weakest stage among its still-active items.
     */
    public static function bubbleOrderStatus(Database $db, int $orderId): void
    {
        $items = $db->fetchAll("SELECT status FROM order_items WHERE order_id = ?", [$orderId]);
        if (!$items) return;

        // order_items.status -> orders.status, in ascending fulfillment order.
        $stageOrder  = ['pending' => 0, 'confirmed' => 1, 'shipped' => 2, 'delivered' => 3];
        $itemToOrder = ['pending' => 'new', 'confirmed' => 'confirmed', 'shipped' => 'shipped', 'delivered' => 'delivered'];
        $active = array_filter($items, fn($it) => !in_array($it['status'], ['cancelled', 'returned'], true));
        if (!$active) return; // everything on this order was cancelled -- leave orders.status alone

        $minStage  = min(array_map(fn($it) => $stageOrder[$it['status']] ?? 0, $active));
        $itemStage = array_search($minStage, $stageOrder, true);
        $newStatus = $itemToOrder[$itemStage] ?? 'new';

        $orderRank = ['new'=>0,'confirmed'=>1,'processing'=>2,'packed'=>3,'shipped'=>4,'out_for_delivery'=>5,'delivered'=>6];
        $current = $db->fetchColumn("SELECT status FROM orders WHERE id = ?", [$orderId]);
        // Never touch a cancelled/disputed/etc. order, and never move it
        // backward (item-level tracking is coarser than orders.status --
        // e.g. it has no notion of "packed" -- so it should only ever
        // push the order FORWARD to a stage it can actually represent).
        if (!isset($orderRank[$current])) return;
        if (($orderRank[$newStatus] ?? 0) > $orderRank[$current]) {
            $db->query("UPDATE orders SET status=?, updated_at=NOW() WHERE id=?", [$newStatus, $orderId]);
        }
    }
}
