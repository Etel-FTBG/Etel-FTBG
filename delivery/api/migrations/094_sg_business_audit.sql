-- Migration 094: Etel Business activity log (cancelled sales, big discounts, stock waste and counts, cash-up results, permission refusals, daily limits).
-- The code also creates this table on first use (api/lib/sg_business.php); this migration only makes it show up in Migrations.
--
-- NOTE ON FORMATTING: every comment on its own line, every
-- statement-terminating `;` last on its line, so api/migrate.php's naive
-- line-based statement splitter parses this correctly.

CREATE TABLE IF NOT EXISTS `sg_business_audit` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `service_giver_id` INT NOT NULL,
  `user_id` INT NULL,
  `action` VARCHAR(40) NOT NULL,
  `ref` VARCHAR(64) NULL,
  `detail` VARCHAR(255) NULL,
  `occurred_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL,
  KEY `ix_audit_shop` (`service_giver_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
