-- Migration 012: delivery vehicle tiers (moto / car / express cargo)
--
-- Adds an admin-editable `delivery_tiers` table (mirrors the
-- `delivery_zones` pattern from migration 007/008) so a customer can
-- choose how their package is delivered, with the multiplier applied
-- on top of the zone's base_fare. Also adds `delivery_tier` to
-- `orders` so we know which tier was billed on each order.
--
-- NOTE ON FORMATTING: every comment on its own line, every
-- statement-terminating `;` last on its line, so api/migrate.php's
-- naive line-based statement splitter parses this correctly.

SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'orders'
      AND COLUMN_NAME = 'delivery_tier'
);

SET @ddl = IF(
    @col_exists = 0,
    'ALTER TABLE `orders` ADD COLUMN `delivery_tier` VARCHAR(20) NOT NULL DEFAULT ''moto'' AFTER `package_type`',
    'SELECT 1'
);

PREPARE stmt FROM @ddl;

EXECUTE stmt;

DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `delivery_tiers` (
    `id`            INT(11)       NOT NULL AUTO_INCREMENT,
    `tier_key`      VARCHAR(20)   NOT NULL,
    `label`         VARCHAR(60)   NOT NULL,
    `icon`          VARCHAR(10)   NOT NULL DEFAULT '',
    `multiplier`    DECIMAL(4,2)  NOT NULL DEFAULT 1.00,
    `eta_delta_min` INT(4)        NOT NULL DEFAULT 0,
    `sort_order`    INT(4)        NOT NULL DEFAULT 0,
    `active`        TINYINT(1)    NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tier_key` (`tier_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- icon is left blank here on purpose - non-ASCII bytes (emoji) in
-- content that flows through PHP/DB on this host's WAF have been
-- mangled before (see etel-group-ops skill); icon-per-tier is mapped
-- client-side in customer.js by tier_key instead.
INSERT INTO `delivery_tiers` (tier_key, label, icon, multiplier, eta_delta_min, sort_order, active) VALUES
    ('moto',          'Delivery by moto', '', 1.00,  0, 1, 1),
    ('car',           'Delivery by car',  '', 1.12, -2, 2, 1),
    ('express_cargo', 'Express Cargo',    '', 1.60,  5, 3, 1)
ON DUPLICATE KEY UPDATE
    tier_key = tier_key;
