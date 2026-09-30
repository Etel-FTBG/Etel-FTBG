-- Migration 062: service_givers.cover_url - a wide cover photo for each shop,
-- shown on the customer app's shop cards and shop page (next to the existing
-- logo_url). Set by the shop owner in Settings, or by an admin.
--
-- NOTE ON FORMATTING: same convention as prior migrations - every comment on
-- its own line, every statement-terminating semicolon last on its line, so
-- api/migrate.php's naive line-based splitter parses this correctly.
-- boot.php also adds the column on first use, so the code and the migration
-- can be deployed in either order.

ALTER TABLE `service_givers` ADD COLUMN IF NOT EXISTS `cover_url` VARCHAR(512) NULL DEFAULT NULL;
