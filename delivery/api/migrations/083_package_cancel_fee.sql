-- Migration 083: a fee for cancelling a paid package after a courier accepted it (kept out of the refund), and the setting for it.
-- The code also adds these on first use (api/lib/order_cancel.php, api/lib/package_orders.php).
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `cancel_fee` DECIMAL(10,2) NOT NULL DEFAULT 0;
CREATE TABLE IF NOT EXISTS `package_settings` (
  `setting_key` VARCHAR(40) NOT NULL,
  `setting_value` VARCHAR(100) NOT NULL DEFAULT '',
  `updated_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
