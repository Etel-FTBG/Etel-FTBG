-- Migration 064: loyalty points.
-- loyalty_points is an append-only ledger (the balance is the SUM of a customer's rows).
-- One row per (customer, order, kind) so an order can never award or refund twice.
-- orders gets points_used / points_discount for orders that paid part with points.
-- The code also creates these on first use (api/lib/loyalty.php), so code and migration
-- can deploy in either order.
--
-- NOTE ON FORMATTING: every comment on its own line, every statement-terminating
-- semicolon last on its line (api/migrate.php splits by line).

CREATE TABLE IF NOT EXISTS `loyalty_points` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `user_id`    INT NOT NULL,
    `order_id`   INT NULL,
    `points`     INT NOT NULL,
    `kind`       VARCHAR(12) NOT NULL,
    `note`       VARCHAR(120) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_user_order_kind` (`user_id`, `order_id`, `kind`),
    INDEX `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `points_used` INT NOT NULL DEFAULT 0;

ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `points_discount` DECIMAL(10,2) NOT NULL DEFAULT 0;

ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `discount` DECIMAL(10,2) NOT NULL DEFAULT 0;
