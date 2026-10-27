-- Migration 096: claim codes for pre-registered businesses (admin makes the business, the owner later claims it with a one-time code).
-- The code also creates this table on first use (api/lib/sg_claim.php); this migration only makes it show up in Migrations.
--
-- NOTE ON FORMATTING: every comment on its own line, every
-- statement-terminating `;` last on its line, so api/migrate.php's naive
-- line-based statement splitter parses this correctly.

CREATE TABLE IF NOT EXISTS `sg_claim_codes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `sg_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `code_hash` CHAR(64) NOT NULL,
  `phone_is_placeholder` TINYINT(1) NOT NULL DEFAULT 0,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_sg_claim_hash` (`code_hash`),
  KEY `idx_sg_claim_sg` (`sg_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
