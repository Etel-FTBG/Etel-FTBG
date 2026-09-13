-- Migration 013: optional delivery address photo (gate/entrance/landmark)
--
-- Adds `delivery_photo_url` to `orders` so a customer can optionally attach
-- a photo of their gate/entrance when Hawassa addresses are unmarked or
-- ambiguous - a real fit for this market, unlike literal
-- Entrance/Apartment/Floor fields (Hawassa is overwhelmingly single-story
-- compounds and gated houses, not numbered-floor apartment blocks, so those
-- fields were skipped as low-value here).
--
-- NOTE ON FORMATTING: every comment on its own line, every
-- statement-terminating `;` last on its line, so api/migrate.php's naive
-- line-based statement splitter parses this correctly.

SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'orders'
      AND COLUMN_NAME = 'delivery_photo_url'
);

SET @ddl = IF(
    @col_exists = 0,
    'ALTER TABLE `orders` ADD COLUMN `delivery_photo_url` VARCHAR(255) NULL AFTER `recipient_address`',
    'SELECT 1'
);

PREPARE stmt FROM @ddl;

EXECUTE stmt;

DEALLOCATE PREPARE stmt;
