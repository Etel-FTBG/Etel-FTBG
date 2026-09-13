-- Migration 025: adds vehicle_type to the rides table itself.
--
-- Previously the type a passenger selected (Bajaj/Moto/Car) was used
-- only for the fare calculation at the moment of booking and then
-- discarded - nothing on the ride row remembered which type it was.
-- That meant driver_arrived's final GPS-distance-based fare (see
-- api/controllers/RideController.php) had no way to know which rate
-- table to use for the actual bill, and hardcoded 'bajaj' regardless
-- of what was really booked. This column is what that recalculation
-- now reads.
--
-- Same formatting convention as prior migrations - one statement per
-- block, semicolon last on its line, for api/migrate.php's naive
-- line-based statement splitter.

SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rides'
      AND COLUMN_NAME = 'vehicle_type'
);

SET @ddl = IF(
    @col_exists = 0,
    "ALTER TABLE `rides` ADD COLUMN `vehicle_type` VARCHAR(20) DEFAULT 'bajaj'",
    'SELECT 1'
);

PREPARE stmt FROM @ddl;

EXECUTE stmt;

DEALLOCATE PREPARE stmt;
