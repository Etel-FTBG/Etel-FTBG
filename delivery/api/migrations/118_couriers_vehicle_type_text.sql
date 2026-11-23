-- Migration 118: couriers.vehicle_type as plain text (VARCHAR 30)
--
-- On some installs the column was an ENUM of a few vehicles, so a vehicle that is not on the list (qute, minibus, ev_car, on_foot, a type staff
-- added) was silently stored as an empty value. A VARCHAR keeps every value already there. The app also does this by itself the first time
-- Admin saves a courier (api/lib/courier_schema.php), so running this is optional.
--
-- NOTE ON FORMATTING: every comment on its own line, every statement-terminating semicolon last on its line.

SET @col_type = (
    SELECT DATA_TYPE
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'couriers'
      AND COLUMN_NAME = 'vehicle_type'
);

SET @ddl = IF(
    @col_type = 'enum',
    'ALTER TABLE `couriers` MODIFY COLUMN `vehicle_type` VARCHAR(30) NULL DEFAULT ''motorcycle''',
    'SELECT 1'
);

PREPARE stmt FROM @ddl;

EXECUTE stmt;

DEALLOCATE PREPARE stmt;
