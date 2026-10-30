-- Migration 099: papers a business hands in before it goes live (licence, owner's ID); admin approves or rejects each.
-- The code also creates this table on first use (api/lib/sg_docs.php); this migration only makes it show up in Migrations.
--
-- NOTE ON FORMATTING: every comment on its own line, every
-- statement-terminating `;` last on its line, so api/migrate.php's naive
-- line-based statement splitter parses this correctly.

CREATE TABLE IF NOT EXISTS `sg_documents` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `sg_id` INT NOT NULL,
  `doc_type` VARCHAR(30) NOT NULL,
  `file_url` VARCHAR(500) NOT NULL,
  `status` VARCHAR(12) NOT NULL DEFAULT 'pending',
  `reject_reason` VARCHAR(255) NULL,
  `uploaded_by` INT NOT NULL DEFAULT 0,
  `reviewed_by` INT NULL,
  `reviewed_at` DATETIME NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_sg_doc` (`sg_id`, `doc_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
