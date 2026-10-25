-- Migration 095: Etel Business expense corrections: an expense can be cancelled (it stays in the books, marked void).
-- The code also adds these columns on first use (api/lib/sg_business.php); this migration only makes it show up in Migrations.
--
-- NOTE ON FORMATTING: every comment on its own line, every
-- statement-terminating `;` last on its line, so api/migrate.php's naive
-- line-based statement splitter parses this correctly.

ALTER TABLE `sg_expenses` ADD COLUMN IF NOT EXISTS `status` ENUM('active','void') NOT NULL DEFAULT 'active';
ALTER TABLE `sg_expenses` ADD COLUMN IF NOT EXISTS `voided_at` DATETIME NULL;
ALTER TABLE `sg_expenses` ADD COLUMN IF NOT EXISTS `voided_by_user_id` INT NULL;
