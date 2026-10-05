-- Migration 000: baseline for schema that application code used to create at runtime
--
-- Some tables and columns were only ever created by application code the first
-- time an action ran (rides_ensure_schema() in controllers/RideController.php,
-- and the ALTER/CREATE statements that used to sit inside courier_get_my_orders).
-- Later migrations (025, 034, 046, ...) depend on them, so a database rebuilt from
-- the repo (complete_migration.sql + migrations) failed with "Table/Column ...
-- doesn't exist". These are the same definitions the application uses; on a
-- database that already has them (every live install) this changes nothing.
CREATE TABLE IF NOT EXISTS `rides` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `passenger_id` INT UNSIGNED NOT NULL,
    `driver_id` INT UNSIGNED DEFAULT NULL,
    `pickup_lat` DECIMAL(10,7) DEFAULT NULL,
    `pickup_lng` DECIMAL(10,7) DEFAULT NULL,
    `dropoff_lat` DECIMAL(10,7) DEFAULT NULL,
    `dropoff_lng` DECIMAL(10,7) DEFAULT NULL,
    `pickup_address` VARCHAR(255) DEFAULT '',
    `dropoff_address` VARCHAR(255) DEFAULT '',
    `distance_km` DECIMAL(8,2) DEFAULT 0,
    `fare_estimate` DECIMAL(10,2) DEFAULT 0,
    `fare_final` DECIMAL(10,2) DEFAULT 0,
    `fare_paid` DECIMAL(10,2) DEFAULT 0,
    `payment_method` VARCHAR(20) DEFAULT 'cash',
    `status` ENUM('searching','accepted','in_progress','completed','cancelled') DEFAULT 'searching',
    `rating` TINYINT DEFAULT NULL,
    `rating_comment` VARCHAR(500) DEFAULT NULL,
    `expires_at` DATETIME DEFAULT NULL,
    `assigned_at` DATETIME DEFAULT NULL,
    `started_at` DATETIME DEFAULT NULL,
    `completed_at` DATETIME DEFAULT NULL,
    `cancelled_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_passenger` (`passenger_id`, `status`),
    KEY `idx_driver` (`driver_id`, `status`),
    KEY `idx_status` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ride_drivers` (
    `user_id` INT UNSIGNED NOT NULL,
    `online` TINYINT(1) DEFAULT 0,
    `lat` DECIMAL(10,7) DEFAULT NULL,
    `lng` DECIMAL(10,7) DEFAULT NULL,
    `vehicle_plate` VARCHAR(30) DEFAULT '',
    `total_earnings` DECIMAL(12,2) DEFAULT 0,
    `total_paid_out` DECIMAL(12,2) DEFAULT 0,
    `total_rides` INT UNSIGNED DEFAULT 0,
    `rating_sum` INT UNSIGNED DEFAULT 0,
    `rating_count` INT UNSIGNED DEFAULT 0,
    `last_seen` DATETIME DEFAULT NULL,
    PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `service_givers`
  ADD COLUMN IF NOT EXISTS `lat` DECIMAL(10,7) NULL,
  ADD COLUMN IF NOT EXISTS `lng` DECIMAL(10,7) NULL;

ALTER TABLE `orders`
  ADD COLUMN IF NOT EXISTS `recipient_address` TEXT NULL,
  ADD COLUMN IF NOT EXISTS `delivery_lat` DECIMAL(10,7) NULL,
  ADD COLUMN IF NOT EXISTS `delivery_lng` DECIMAL(10,7) NULL;
