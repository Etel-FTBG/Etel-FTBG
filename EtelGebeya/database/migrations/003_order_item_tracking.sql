-- ============================================================
-- EtelGebeya - per-item shipment tracking
-- orders.tracking_number/courier_id already existed but were never
-- wired to any code path - and being order-level, they don't fit this
-- app's actual fulfillment model anyway (order_items.status/
-- bubbleOrderStatus already track shipment per seller-item, since one
-- order can span multiple sellers each shipping independently). Adding
-- the same two fields at the order_item level instead, so each
-- seller's shipment carries its own tracking number/courier without
-- one seller's update overwriting another's on the same order.
-- ============================================================

ALTER TABLE `order_items`
  ADD COLUMN IF NOT EXISTS `tracking_number` VARCHAR(60) DEFAULT NULL AFTER `payout_tx_ref`,
  ADD COLUMN IF NOT EXISTS `courier_id` INT(10) UNSIGNED DEFAULT NULL AFTER `tracking_number`;
