-- Migration 078: order_admin_actions - a log of what staff did to an order by hand (assign / reassign / take back a courier).
-- Written by api/lib/manual_dispatch.php (Admin > Dispatch). The code also creates the table on first use.
CREATE TABLE IF NOT EXISTS `order_admin_actions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `order_id` INT NOT NULL,
  `admin_id` INT NULL,
  `action` VARCHAR(30) NOT NULL,
  `detail` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
