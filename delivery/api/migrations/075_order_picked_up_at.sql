-- Migration 075: orders.picked_up_at - when the order was actually picked up from the shop.
-- With courier_accepted_at (069) and delivered_at this gives real timings: wait for pickup (accepted -> picked up) and
-- courier trip (picked up -> delivered), shown in Admin > Dashboard. Set by every pickup path via api/lib/order_stamps.php.
-- Old orders keep NULL (the time was never recorded, so it is not invented).
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `picked_up_at` DATETIME NULL DEFAULT NULL;
