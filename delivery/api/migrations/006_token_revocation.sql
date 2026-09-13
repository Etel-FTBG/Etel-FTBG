-- Migration 006: token_version (bearer token revocation)
-- Bearer tokens are stateless HMACs with no session table, so previously
-- there was no way to invalidate one before its 7-day expiry - a logout
-- didn't actually revoke anything server-side. Adding a per-user counter:
-- every issued token embeds the user's token_version at issue time, and
-- every request re-checks it against the current value in this column.
-- Bumping token_version (see etel_bump_token_version() in boot.php)
-- instantly invalidates every outstanding token for that user - used by
-- the new `logout` action, and ready to be reused by a future
-- password-reset or admin-suspend flow.
--
-- Made idempotent (info_schema check + PREPARE/EXECUTE) after discovering
-- this plain ALTER TABLE blocked every later migration on this install:
-- the token_version column already existed here (added directly during an
-- earlier session, before this migration file existed), so a plain ALTER
-- threw "Duplicate column name" every time --apply ran, which stopped
-- migrate.php's run loop before it ever reached 007/008 - meaning
-- ride_fare_rules was never created despite 007 looking otherwise fine.
--
-- NOTE ON FORMATTING: same as 007/008 - every comment on its own line,
-- every statement-terminating `;` last on its line, so api/migrate.php's
-- naive line-based statement splitter parses this correctly.

SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'token_version'
);

SET @ddl = IF(
    @col_exists = 0,
    'ALTER TABLE `users` ADD COLUMN `token_version` INT(11) NOT NULL DEFAULT 0 AFTER `password_hash`',
    'SELECT 1'
);

PREPARE stmt FROM @ddl;

EXECUTE stmt;

DEALLOCATE PREPARE stmt;
