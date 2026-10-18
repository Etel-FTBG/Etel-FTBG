-- Migration 089: ride tips.
--
-- After a completed trip the rider can leave a tip: they send it to an Etel bank account and enter the transfer
-- reference, an admin with rides.manage approves it once the money has arrived, and then the driver gets all of it
-- (no commission) in total_earnings. One tip per trip; a transfer reference can back only one tip.
-- The same table is also created on first use, so this migration only makes it show up in Migrations.
--
-- NOTE ON FORMATTING: every comment on its own line, every
-- statement-terminating `;` last on its line, so api/migrate.php's naive
-- line-based statement splitter parses this correctly.

CREATE TABLE IF NOT EXISTS `ride_tips` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ride_id` INT NOT NULL,
  `rider_id` INT NOT NULL,
  `driver_id` INT NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `bank_key` VARCHAR(30) NULL,
  `payment_ref` VARCHAR(60) NOT NULL,
  `note` VARCHAR(255) NULL,
  `status` VARCHAR(10) NOT NULL DEFAULT 'pending',
  `reviewed_by` INT NULL,
  `reviewed_at` DATETIME NULL,
  `review_note` VARCHAR(255) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_ride_tip_ride` (`ride_id`),
  UNIQUE KEY `uq_ride_tip_ref` (`payment_ref`),
  KEY `idx_ride_tip_status` (`status`, `id`),
  KEY `idx_ride_tip_driver` (`driver_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO pricing_rules (rule_key, rule_value, description) VALUES ('ride_tip_max', 500, 'Largest tip a rider can leave for one trip (ETB)');

INSERT IGNORE INTO pricing_rules (rule_key, rule_value, description) VALUES ('ride_tip_window_hours', 72, 'Hours after a trip during which the rider can still leave a tip');
