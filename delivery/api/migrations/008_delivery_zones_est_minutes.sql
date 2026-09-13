-- Migration 008: add missing est_minutes column to delivery_zones
--
-- This DB already had a delivery_zones table (created earlier by
-- api/seeds/complete_migration.sql, which never included an est_minutes
-- column). Migration 007's `CREATE TABLE IF NOT EXISTS delivery_zones`
-- was therefore a no-op on this install - it does not add columns to an
-- existing table - so its own seed INSERT (which references est_minutes)
-- failed with "Unknown column 'est_minutes'" and migration 007 was left
-- unapplied. This migration adds the column the safe/idempotent way
-- (checked via information_schema first, since MySQL's
-- "ADD COLUMN IF NOT EXISTS" syntax isn't available on all hosting
-- versions), then re-runs 007's delivery_zones seed insert so the row
-- data actually lands.
--
-- NOTE ON FORMATTING: same as 007 - every comment on its own line, every
-- statement-terminating `;` last on its line, so api/migrate.php's naive
-- line-based statement splitter parses this correctly.

SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'delivery_zones'
      AND COLUMN_NAME = 'est_minutes'
);

SET @ddl = IF(
    @col_exists = 0,
    'ALTER TABLE `delivery_zones` ADD COLUMN `est_minutes` INT(4) NOT NULL DEFAULT 20 AFTER `base_fare`',
    'SELECT 1'
);

PREPARE stmt FROM @ddl;

EXECUTE stmt;

DEALLOCATE PREPARE stmt;

-- Re-run 007's zone seed now that the column is guaranteed to exist.
-- ON DUPLICATE KEY UPDATE only touches est_minutes so it won't clobber
-- base_fare/active/sort_order an admin may have already edited via the
-- panel between the failed 007 attempt and this migration.
INSERT INTO `delivery_zones` (name, base_fare, est_minutes, sort_order, active) VALUES
    ('Mehal Ketema',         50, 20,  1, 1),
    ('Tabor',                50, 20,  2, 1),
    ('Addis Ketema',         60, 25,  3, 1),
    ('Haik Dar',             70, 30,  4, 1),
    ('Tulu Dimtu',           70, 30,  5, 1),
    ('Misrak',               65, 28,  6, 1),
    ('Referral',             55, 22,  7, 1),
    ('Hawela Teklehaimanot', 75, 35,  8, 1),
    ('Dore Bafana',          80, 35,  9, 1),
    ('Stadium',              45, 15, 10, 1),
    ('Liqe Mender',          50, 18, 11, 1),
    ('University',           60, 25, 12, 1)
ON DUPLICATE KEY UPDATE
    est_minutes = IF(est_minutes = 0, VALUES(est_minutes), est_minutes);
