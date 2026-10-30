-- Migration 100: a courier's ID document and number, selfie, licence and emergency contact (taken by staff in the Courier desk).
-- The code also creates this table on first use (api/lib/courier_details.php); this migration only makes it show up in Migrations.
--
-- NOTE ON FORMATTING: every comment on its own line, every
-- statement-terminating `;` last on its line, so api/migrate.php's naive
-- line-based statement splitter parses this correctly.

CREATE TABLE IF NOT EXISTS `courier_details` (
  `user_id` INT NOT NULL PRIMARY KEY,
  `id_type` VARCHAR(20) NOT NULL DEFAULT '',
  `id_number` VARCHAR(60) NOT NULL DEFAULT '',
  `id_photo_url` VARCHAR(500) NOT NULL DEFAULT '',
  `selfie_url` VARCHAR(500) NOT NULL DEFAULT '',
  `licence_number` VARCHAR(60) NOT NULL DEFAULT '',
  `licence_photo_url` VARCHAR(500) NOT NULL DEFAULT '',
  `emergency_name` VARCHAR(100) NOT NULL DEFAULT '',
  `emergency_phone` VARCHAR(30) NOT NULL DEFAULT '',
  `address` VARCHAR(200) NOT NULL DEFAULT '',
  `created_by` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_courier_id_number` (`id_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
