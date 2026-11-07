-- Migration 104: Agents programme phase 6 - agents can book a ride for a customer (vehicle type + customer phone).
--
-- Additive and idempotent: one CREATE TABLE IF NOT EXISTS and INSERT IGNORE (never overwrites a value an admin changed).
-- No ALTER on any existing table, no transaction statements. Safe to run twice (phpMyAdmin or api/migrate.php).
-- Same formatting convention as 101: every comment on its own line, every semicolon last on its line, single quotes only.

CREATE TABLE IF NOT EXISTS `fa_bookings` (
  `id`               INT(11)       NOT NULL AUTO_INCREMENT,
  `agent_id`         INT(11)       NOT NULL,
  `ride_id`          INT(11)       NOT NULL,
  `customer_user_id` INT(11)       NOT NULL,
  `phone_last9`      VARCHAR(9)    NOT NULL DEFAULT '',
  `vehicle_type`     VARCHAR(24)   NOT NULL DEFAULT '',
  `fare_estimate`    DECIMAL(10,2) NOT NULL DEFAULT 0,
  `bonus_eligible`   TINYINT(1)    NOT NULL DEFAULT 0,
  `new_account`      TINYINT(1)    NOT NULL DEFAULT 0,
  `created_at`       DATETIME      NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fa_booking_ride` (`ride_id`),
  KEY `idx_fa_booking_agent` (`agent_id`, `created_at`),
  KEY `idx_fa_booking_customer` (`customer_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `fa_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES
  ('agent_booking_enabled', '1', NOW()),
  ('agent_booking_daily_cap', '10', NOW()),
  ('agent_booking_cash_counts', '1', NOW()),
  ('agent_booking_review_trips', '10', NOW()),
  ('risk_w_sequential_phones', '25', NOW());
