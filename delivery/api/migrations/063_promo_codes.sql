-- Migration 063: real promo / discount codes.
-- promo_codes holds the codes, promo_redemptions one row per use, and the
-- orders table records which code was applied and how much it took off.
-- Seeds the two codes the customer app already advertises on its home banners
-- (PREMIUM50 and FREEDEL) so those banners now actually work. Admins manage
-- codes in Admin - Promo codes. The code also creates these tables on first
-- use (api/lib/promo.php), so code and migration can deploy in either order.
--
-- NOTE ON FORMATTING: every comment on its own line, every statement-
-- terminating semicolon last on its line (api/migrate.php splits by line).

CREATE TABLE IF NOT EXISTS `promo_codes` (
    `id`               INT AUTO_INCREMENT PRIMARY KEY,
    `code`             VARCHAR(40) NOT NULL UNIQUE,
    `description`      VARCHAR(255) NULL,
    `discount_type`    ENUM('percent','fixed','free_delivery') NOT NULL DEFAULT 'percent',
    `value`            DECIMAL(10,2) NOT NULL DEFAULT 0,
    `min_subtotal`     DECIMAL(10,2) NOT NULL DEFAULT 0,
    `max_discount`     DECIMAL(10,2) NULL,
    `starts_at`        DATETIME NULL,
    `expires_at`       DATETIME NULL,
    `max_uses`         INT NULL,
    `used_count`       INT NOT NULL DEFAULT 0,
    `per_user_limit`   INT NOT NULL DEFAULT 1,
    `first_order_only` TINYINT(1) NOT NULL DEFAULT 0,
    `is_active`        TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `promo_redemptions` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `promo_id`   INT NOT NULL,
    `user_id`    INT NOT NULL,
    `order_id`   INT NULL,
    `discount`   DECIMAL(10,2) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_promo_user` (`promo_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `promo_code` VARCHAR(40) NULL DEFAULT NULL;

ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `discount` DECIMAL(10,2) NOT NULL DEFAULT 0;

INSERT IGNORE INTO `promo_codes` (`code`, `description`, `discount_type`, `value`, `min_subtotal`, `max_discount`, `per_user_limit`, `first_order_only`, `is_active`) VALUES ('PREMIUM50', '50% off your first order (up to ETB 150)', 'percent', 50, 0, 150, 1, 1, 1);

INSERT IGNORE INTO `promo_codes` (`code`, `description`, `discount_type`, `value`, `min_subtotal`, `max_discount`, `per_user_limit`, `first_order_only`, `is_active`) VALUES ('FREEDEL', 'Free delivery on orders above ETB 500', 'free_delivery', 0, 500, NULL, 99, 0, 1);
