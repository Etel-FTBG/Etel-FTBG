-- Migration 093: Etel Business, the shop's own books that work with no internet (walk-in sales, stock ledger, expenses, daily cash-up).
-- The code also creates these on first use (api/lib/sg_business.php), so this migration only makes them show up in Migrations.
--
-- NOTE ON FORMATTING: every comment on its own line, every
-- statement-terminating `;` last on its line, so api/migrate.php's naive
-- line-based statement splitter parses this correctly.

ALTER TABLE `service_givers` ADD COLUMN IF NOT EXISTS `business_tools` TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS `sg_pos_sales` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `service_giver_id` INT NOT NULL,
  `client_req_id` VARCHAR(64) NOT NULL,
  `sold_by_user_id` INT NOT NULL,
  `occurred_at` DATETIME NOT NULL,
  `subtotal` DECIMAL(12,2) NOT NULL,
  `discount` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `total` DECIMAL(12,2) NOT NULL,
  `payment_method` ENUM('cash','mobile','credit') NOT NULL DEFAULT 'cash',
  `customer_name` VARCHAR(80) NULL,
  `customer_phone` VARCHAR(30) NULL,
  `status` ENUM('paid','credit','void') NOT NULL DEFAULT 'paid',
  `voided_at` DATETIME NULL,
  `voided_by_user_id` INT NULL,
  `created_at` DATETIME NOT NULL,
  UNIQUE KEY `uq_sale_req` (`service_giver_id`, `client_req_id`),
  KEY `ix_sale_day` (`service_giver_id`, `occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sg_pos_sale_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `sale_id` INT UNSIGNED NOT NULL,
  `item_id` INT NULL,
  `name_snapshot` VARCHAR(120) NOT NULL,
  `unit_price` DECIMAL(12,2) NOT NULL,
  `qty` INT NOT NULL,
  KEY `ix_sale` (`sale_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sg_stock_events` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `service_giver_id` INT NOT NULL,
  `item_id` INT NOT NULL,
  `delta` INT NOT NULL,
  `reason` ENUM('sale','online_order','delivery','waste','count','void') NOT NULL,
  `ref_id` INT UNSIGNED NULL,
  `client_req_id` VARCHAR(64) NULL,
  `user_id` INT NULL,
  `occurred_at` DATETIME NOT NULL,
  KEY `ix_stock_item` (`service_giver_id`, `item_id`),
  KEY `ix_stock_req` (`service_giver_id`, `client_req_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sg_expenses` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `service_giver_id` INT NOT NULL,
  `client_req_id` VARCHAR(64) NOT NULL,
  `category` VARCHAR(30) NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `paid_with` ENUM('cash','mobile') NOT NULL DEFAULT 'cash',
  `note` VARCHAR(200) NULL,
  `user_id` INT NULL,
  `occurred_at` DATETIME NOT NULL,
  UNIQUE KEY `uq_exp_req` (`service_giver_id`, `client_req_id`),
  KEY `ix_exp_day` (`service_giver_id`, `occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sg_cash_days` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `service_giver_id` INT NOT NULL,
  `business_date` DATE NOT NULL,
  `opening_float` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `expected_cash` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `counted_cash` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `difference` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `closed_by_user_id` INT NULL,
  `closed_at` DATETIME NOT NULL,
  `client_req_id` VARCHAR(64) NULL,
  UNIQUE KEY `uq_cash_day` (`service_giver_id`, `business_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
