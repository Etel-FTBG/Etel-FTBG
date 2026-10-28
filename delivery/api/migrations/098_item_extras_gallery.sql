-- Migration 098: optional extras on shop items ("Mayonnaise +50") and extra photos for the item carousel.
-- The code also creates these on first use (api/lib/menu_catalog.php); this migration only makes it show up in Migrations.
--
-- NOTE ON FORMATTING: every comment on its own line, every
-- statement-terminating `;` last on its line, so api/migrate.php's naive
-- line-based statement splitter parses this correctly.

ALTER TABLE `items` ADD COLUMN IF NOT EXISTS `gallery` TEXT NULL;

CREATE TABLE IF NOT EXISTS `item_extras` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `item_id` INT NOT NULL,
  `name` VARCHAR(80) NOT NULL,
  `name_am` VARCHAR(80) NULL,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_item_extras_item` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
