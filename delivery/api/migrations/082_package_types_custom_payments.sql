-- Migration 082: staff can add their own package vehicle types and their own payment companies / banks from Admin.
-- package_rates: where an added vehicle sits in the size order and which courier vehicle words get its jobs.
-- integration_custom: payment companies and banks added from Admin -> Payments & banks.
-- The code also adds these on first use (api/lib/package_orders.php, api/lib/integrations.php).
ALTER TABLE `package_rates` ADD COLUMN IF NOT EXISTS `size_rank` TINYINT NOT NULL DEFAULT 0;
ALTER TABLE `package_rates` ADD COLUMN IF NOT EXISTS `courier_names` VARCHAR(200) NOT NULL DEFAULT '';
CREATE TABLE IF NOT EXISTS `integration_custom` (
  `provider` VARCHAR(30) NOT NULL,
  `name` VARCHAR(60) NOT NULL,
  `kind` VARCHAR(10) NOT NULL DEFAULT 'payment',
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
