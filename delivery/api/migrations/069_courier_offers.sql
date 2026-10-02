-- Migration 069: expiring courier job offers.
-- Off by default (courier_offer_settings.offer_seconds = 0); turn it on in Admin -> Couriers -> Job offers.
-- With offers on, an auto-assigned courier must accept within the time limit, otherwise the order is offered to
-- the next nearest courier (see api/lib/courier_offers.php). The code also creates all of this on first use.
--
-- NOTE ON FORMATTING: every comment on its own line, every statement-terminating
-- semicolon last on its line (api/migrate.php splits by line).

CREATE TABLE IF NOT EXISTS `courier_offer_settings` (`id` TINYINT NOT NULL PRIMARY KEY, `offer_seconds` INT NOT NULL DEFAULT 0, `max_attempts` INT NOT NULL DEFAULT 3) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `order_courier_offers` (`id` INT AUTO_INCREMENT PRIMARY KEY, `order_id` INT NOT NULL, `courier_id` INT NOT NULL, `offered_at` DATETIME NOT NULL, `expires_at` DATETIME NOT NULL, `response` VARCHAR(12) NOT NULL DEFAULT 'pending', `responded_at` DATETIME NULL, KEY `idx_oco_order` (`order_id`), KEY `idx_oco_courier` (`courier_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `courier_offer_expires_at` DATETIME NULL DEFAULT NULL;

ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `courier_accepted_at` DATETIME NULL DEFAULT NULL;

ALTER TABLE `couriers` ADD COLUMN IF NOT EXISTS `offer_capable_until` DATETIME NULL DEFAULT NULL;

ALTER TABLE `orders` ADD INDEX IF NOT EXISTS `idx_orders_offer_expires` (`courier_offer_expires_at`);
