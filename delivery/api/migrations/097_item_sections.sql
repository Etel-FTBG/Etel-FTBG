-- Migration 097: menu sections and "Most popular" on shop items (Burger, Drinks, Breakfast... as tabs on the customer shop page).
-- The code also adds these columns on first use (api/lib/menu_catalog.php); this migration only makes it show up in Migrations.
--
-- NOTE ON FORMATTING: every comment on its own line, every
-- statement-terminating `;` last on its line, so api/migrate.php's naive
-- line-based statement splitter parses this correctly.

ALTER TABLE `items` ADD COLUMN IF NOT EXISTS `menu_section` VARCHAR(60) NULL DEFAULT NULL;
ALTER TABLE `items` ADD COLUMN IF NOT EXISTS `is_popular` TINYINT(1) NOT NULL DEFAULT 0;
