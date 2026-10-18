-- Migration 086: indexes for the ride dispatch queries.
--
-- The driver request list, the 3-second driver stream and the expiry sweep all filter rides by
-- status and expires_at, and the "who is online and recently seen" lookups filter ride_drivers by
-- online / last_seen. ride_path_points (one row per GPS ping) is always read by ride
-- and in time order. These are plain additive indexes: nothing changes for the app, they only stop
-- these queries from scanning more rows as the tables grow. Each uses IF NOT EXISTS so running the
-- migration twice is harmless.
--
-- NOTE ON FORMATTING: every comment on its own line, every
-- statement-terminating `;` last on its line, so api/migrate.php's naive
-- line-based statement splitter parses this correctly.

CREATE INDEX IF NOT EXISTS `idx_rides_status_expires` ON `rides` (`status`, `expires_at`);

CREATE INDEX IF NOT EXISTS `idx_ride_drivers_online_seen` ON `ride_drivers` (`online`, `last_seen`);

CREATE INDEX IF NOT EXISTS `idx_ride_path_ride_time` ON `ride_path_points` (`ride_id`, `recorded_at`);

CREATE TABLE IF NOT EXISTS `ride_driver_exclusions` (
    `ride_id` INT NOT NULL,
    `driver_id` INT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`ride_id`, `driver_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX IF NOT EXISTS `idx_ride_exclusions_driver` ON `ride_driver_exclusions` (`driver_id`);
