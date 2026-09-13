-- Migration 026: adds surge_applied to the rides table.
--
-- Peak-hour surge (rides_calc_fare() in api/lib/ride_fare.php) was
-- already being calculated and silently baked into fare_estimate/
-- fare_final - a fare like ETB 596 for an 11.27km Bajaj open trip is
-- correct (120 base + 30/km x 11.27km = 458.10, x1.3 peak surge =
-- 595.53), but nothing on screen ever said surge was active, so a
-- correct fare could look like a bug. This column records whether
-- surge was active AT THE TIME the fare was calculated, so it can be
-- displayed accurately later even if the current time (and therefore
-- current peak-hour status) has since changed.
--
-- Same formatting convention as prior migrations - one statement per
-- block, semicolon last on its line, for api/migrate.php's naive
-- line-based statement splitter.

SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rides'
      AND COLUMN_NAME = 'surge_applied'
);

SET @ddl = IF(
    @col_exists = 0,
    'ALTER TABLE `rides` ADD COLUMN `surge_applied` TINYINT(1) DEFAULT 0',
    'SELECT 1'
);

PREPARE stmt FROM @ddl;

EXECUTE stmt;

DEALLOCATE PREPARE stmt;
