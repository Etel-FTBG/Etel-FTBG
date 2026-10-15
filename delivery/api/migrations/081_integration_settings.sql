-- Migration 081: payment / bank connections saved from Admin (Payments & banks) instead of api/.env.
-- Values are stored encrypted (api/lib/integrations.php). The code also creates these tables on first use.
CREATE TABLE IF NOT EXISTS `integration_settings` (
  `provider` VARCHAR(30) NOT NULL,
  `setting_key` VARCHAR(40) NOT NULL,
  `value_enc` TEXT NOT NULL,
  `updated_by` INT UNSIGNED NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`provider`, `setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `integration_changes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider` VARCHAR(30) NOT NULL,
  `setting_key` VARCHAR(40) NOT NULL,
  `action` VARCHAR(12) NOT NULL,
  `admin_id` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_provider` (`provider`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
