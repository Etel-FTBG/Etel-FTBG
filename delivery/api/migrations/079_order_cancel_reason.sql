-- Migration 079: why, by whom and when an order was cancelled (api/lib/order_cancel.php).
-- cancelled_by is customer, shop or admin; cancel_reason is a short code from a fixed list per role.
-- Old cancelled orders keep NULL (the reason was never recorded). The code also adds these columns on first use.
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `cancel_reason` VARCHAR(30) NULL DEFAULT NULL;
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `cancelled_by` VARCHAR(10) NULL DEFAULT NULL;
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `cancel_note` VARCHAR(200) NULL DEFAULT NULL;
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `cancelled_at` DATETIME NULL DEFAULT NULL;
