-- Migration 088: ride promo codes.
--
-- Anyone signed in can propose a code (status pending); it works only after an admin approves it. The discount is
-- worked out on the final fare when the trip ends and is paid for by Etel, not by the driver (api/lib/ride_promo.php).
-- The same tables and columns are also created on first use, so this migration only makes them show up in Migrations.
--
-- NOTE ON FORMATTING: every comment on its own line, every
-- statement-terminating `;` last on its line, so api/migrate.php's naive
-- line-based statement splitter parses this correctly.

CREATE TABLE IF NOT EXISTS `ride_promo_codes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(40) NOT NULL,
  `description` VARCHAR(255) NULL,
  `discount_type` VARCHAR(10) NOT NULL DEFAULT 'percent',
  `value` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `max_discount` DECIMAL(10,2) NULL,
  `min_fare` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `starts_at` DATETIME NULL,
  `expires_at` DATETIME NULL,
  `max_uses` INT NULL,
  `used_count` INT NOT NULL DEFAULT 0,
  `per_rider_limit` INT NOT NULL DEFAULT 1,
  `status` VARCHAR(10) NOT NULL DEFAULT 'pending',
  `created_by` INT NULL,
  `created_by_role` VARCHAR(20) NULL,
  `reviewed_by` INT NULL,
  `reviewed_at` DATETIME NULL,
  `review_note` VARCHAR(255) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_ride_promo_code` (`code`),
  KEY `idx_ride_promo_status` (`status`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ride_promo_redemptions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `promo_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `ride_id` INT NOT NULL,
  `discount` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_ride_promo_ride` (`ride_id`),
  KEY `idx_ride_promo_user` (`promo_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `rides` ADD COLUMN IF NOT EXISTS `promo_id` INT NULL DEFAULT NULL;

ALTER TABLE `rides` ADD COLUMN IF NOT EXISTS `promo_code` VARCHAR(40) NULL DEFAULT NULL;

ALTER TABLE `rides` ADD COLUMN IF NOT EXISTS `promo_discount` DECIMAL(10,2) NOT NULL DEFAULT 0;

ALTER TABLE `rides` ADD COLUMN IF NOT EXISTS `promo_net` DECIMAL(10,2) NULL DEFAULT NULL;
