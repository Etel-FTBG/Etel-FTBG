-- Migration 077: refunds and courier cash bookkeeping (api/lib/money_ledger.php).
-- order_refunds: one row per refunded order (the UNIQUE key makes a second refund of the same order impossible).
-- courier_cash_remittances: cash a courier handed over to the company; the courier's "still holding" figure is
--   cash collected on delivered cash orders minus these. The code also creates both tables on first use.
CREATE TABLE IF NOT EXISTS `order_refunds` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `order_id` INT NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `method` VARCHAR(20) NOT NULL DEFAULT 'other',
  `reference` VARCHAR(80) NOT NULL DEFAULT '',
  `note` VARCHAR(255) NOT NULL DEFAULT '',
  `admin_id` INT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `courier_cash_remittances` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `courier_id` INT NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `note` VARCHAR(255) NOT NULL DEFAULT '',
  `admin_id` INT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_courier` (`courier_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
