-- Migration 033: Three trust/booking/retention features for Ride:
--   1. Two-way ratings - driver rates rider back (mirrors the existing
--      rider-rates-driver `rating`/`rating_comment` columns on `rides`,
--      plus rating_sum/rating_count on `ride_drivers`).
--   2. Share live trip via a public, no-login link - `share_token` on
--      `rides`, looked up by the new standalone `api/trip_share.php`
--      (deliberately NOT routed through boot.php's require_auth(), since
--      a trusted contact watching the trip is never logged in).
--   3. Scheduled / pre-booked rides - `scheduled_at` + `is_scheduled` +
--      `dispatched_at` on `rides`, converted from status='scheduled' to
--      status='searching' by api/cron_dispatch_scheduled.php.
--
-- NOTE: RideController.php's rides_ensure_schema() self-heals these same
-- columns via rides_add_missing_cols() on every request too (belt and
-- suspenders, same pattern as migration 025/026/029/030/031/032) - this
-- migration file additionally adds the two indexes that self-heal doesn't
-- cover (KEY on share_token, KEY on scheduled dispatch lookup).
--
-- Same formatting convention as prior migrations - one statement per
-- block, semicolon last on its line, for api/migrate.php's naive
-- line-based statement splitter.

SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rides'
      AND COLUMN_NAME = 'driver_rating'
);

SET @ddl = IF(
    @col_exists = 0,
    'ALTER TABLE `rides` ADD COLUMN `driver_rating` TINYINT DEFAULT NULL, ADD COLUMN `driver_rating_comment` VARCHAR(500) DEFAULT NULL',
    'SELECT 1'
);

PREPARE stmt FROM @ddl;

EXECUTE stmt;

DEALLOCATE PREPARE stmt;

SET @col_exists2 = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rides'
      AND COLUMN_NAME = 'share_token'
);

SET @ddl2 = IF(
    @col_exists2 = 0,
    'ALTER TABLE `rides` ADD COLUMN `share_token` VARCHAR(40) DEFAULT NULL',
    'SELECT 1'
);

PREPARE stmt2 FROM @ddl2;

EXECUTE stmt2;

DEALLOCATE PREPARE stmt2;

SET @idx_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rides'
      AND INDEX_NAME = 'idx_share_token'
);

SET @ddl3 = IF(
    @idx_exists = 0,
    'ALTER TABLE `rides` ADD KEY `idx_share_token` (`share_token`)',
    'SELECT 1'
);

PREPARE stmt3 FROM @ddl3;

EXECUTE stmt3;

DEALLOCATE PREPARE stmt3;

SET @col_exists3 = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rides'
      AND COLUMN_NAME = 'scheduled_at'
);

SET @ddl4 = IF(
    @col_exists3 = 0,
    'ALTER TABLE `rides` ADD COLUMN `scheduled_at` DATETIME DEFAULT NULL, ADD COLUMN `is_scheduled` TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN `dispatched_at` DATETIME DEFAULT NULL',
    'SELECT 1'
);

PREPARE stmt4 FROM @ddl4;

EXECUTE stmt4;

DEALLOCATE PREPARE stmt4;

SET @idx_exists2 = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rides'
      AND INDEX_NAME = 'idx_scheduled_dispatch'
);

SET @ddl5 = IF(
    @idx_exists2 = 0,
    'ALTER TABLE `rides` ADD KEY `idx_scheduled_dispatch` (`status`, `scheduled_at`)',
    'SELECT 1'
);

PREPARE stmt5 FROM @ddl5;

EXECUTE stmt5;

DEALLOCATE PREPARE stmt5;

SET @col_exists4 = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'rider_rating_sum'
);

SET @ddl6 = IF(
    @col_exists4 = 0,
    'ALTER TABLE `users` ADD COLUMN `rider_rating_sum` INT UNSIGNED DEFAULT 0, ADD COLUMN `rider_rating_count` INT UNSIGNED DEFAULT 0',
    'SELECT 1'
);

PREPARE stmt6 FROM @ddl6;

EXECUTE stmt6;

DEALLOCATE PREPARE stmt6;
