-- Migration 015: optional Amharic name/description for items
--
-- Lets a service giver optionally add an Amharic name/description when
-- registering an item, shown to customers instead of the English
-- name/description once they've switched the app to Amharic. Both columns
-- are nullable and optional - falls back to the English name/description
-- (already required) whenever a service giver hasn't filled these in.
--
-- NOTE ON FORMATTING: every comment on its own line, every
-- statement-terminating `;` last on its line, so api/migrate.php's naive
-- line-based statement splitter parses this correctly.

SET @col1_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'items'
      AND COLUMN_NAME = 'name_am'
);

SET @ddl1 = IF(
    @col1_exists = 0,
    'ALTER TABLE `items` ADD COLUMN `name_am` VARCHAR(255) NULL AFTER `name`',
    'SELECT 1'
);

PREPARE stmt1 FROM @ddl1;

EXECUTE stmt1;

DEALLOCATE PREPARE stmt1;

SET @col2_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'items'
      AND COLUMN_NAME = 'description_am'
);

SET @ddl2 = IF(
    @col2_exists = 0,
    'ALTER TABLE `items` ADD COLUMN `description_am` TEXT NULL AFTER `description`',
    'SELECT 1'
);

PREPARE stmt2 FROM @ddl2;

EXECUTE stmt2;

DEALLOCATE PREPARE stmt2;
