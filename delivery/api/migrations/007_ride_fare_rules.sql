-- Migration 007: admin-configurable ride pricing (per vehicle type) + zones
--
-- Before this migration, ride fare math (base fare, per-km rate, wait rate,
-- minimum fares, per-vehicle-type multipliers) was hardcoded in
-- RideController.php's rides_calc_fare(), and the Hawassa zone list +
-- per-zone delivery fees were hardcoded in config-etb.php. Neither could be
-- changed without a code deploy. This migration adds the tables the admin
-- panel now reads/writes so pricing can be adjusted per vehicle type
-- without touching code.
--
-- Uses CREATE TABLE IF NOT EXISTS + INSERT ... ON DUPLICATE KEY UPDATE
-- throughout so it's safe to run whether or not delivery_zones/pricing_rules
-- already exist from the (never-auto-applied) seed files in api/seeds/.
--
-- NOTE ON FORMATTING: api/migrate.php splits this file into individual
-- statements with a simple line-based rule (a statement ends when a
-- trimmed line ends in `;`) and does not understand inline `-- comments`
-- after SQL on the same line. Every comment below is therefore on its own
-- line, and every statement-terminating `;` is the last character on its
-- line, so the splitter sees exactly the statements intended.

CREATE TABLE IF NOT EXISTS `ride_fare_rules` (
    `id`                  INT(11)       NOT NULL AUTO_INCREMENT,
    `vehicle_type`        VARCHAR(20)   NOT NULL,
    `base_fare`           DECIMAL(8,2)  NOT NULL DEFAULT 120.00,
    `per_km_rate`         DECIMAL(8,2)  NOT NULL DEFAULT 32.00,
    `per_km_rate_open`    DECIMAL(8,2)  NOT NULL DEFAULT 30.00,
    `wait_rate_per_min`   DECIMAL(8,2)  NOT NULL DEFAULT 3.00,
    `min_fare`            DECIMAL(8,2)  NOT NULL DEFAULT 120.00,
    `min_fare_short_trip` DECIMAL(8,2)  NOT NULL DEFAULT 150.00,
    `active`              TINYINT(1)    NOT NULL DEFAULT 1,
    `updated_at`          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_vehicle_type` (`vehicle_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seeded so post-migration behavior is byte-for-byte the same as the old
-- hardcoded formula: fare = (120 + rate*km + 3*wait) * vehicleMultiplier,
-- with bajaj=1.0x, moto=1.15x, car=1.5x, minibus=1.3x, and car's minimum
-- fare hard-floored at 200 regardless of trip length (the one place the
-- old code treated a vehicle type specially beyond the multiplier).
-- ON DUPLICATE KEY UPDATE is a no-op update (sets vehicle_type to itself)
-- so re-running this migration never clobbers rates an admin has since
-- changed in the panel.
INSERT INTO `ride_fare_rules`
    (vehicle_type, base_fare, per_km_rate, per_km_rate_open, wait_rate_per_min, min_fare, min_fare_short_trip, active) VALUES
    ('bajaj',   120.00, 32.00, 30.00, 3.00, 120.00, 150.00, 1),
    ('moto',    138.00, 36.80, 34.50, 3.45, 120.00, 150.00, 1),
    ('car',     180.00, 48.00, 45.00, 4.50, 200.00, 200.00, 1),
    ('minibus', 156.00, 41.60, 39.00, 3.90, 120.00, 150.00, 1)
ON DUPLICATE KEY UPDATE
    vehicle_type = VALUES(vehicle_type);

-- Global ride settings (peak-hour surge multiplier). Reuses the
-- pricing_rules key/value table from api/seeds/ (creating it here too,
-- since those seed files are never auto-applied and this table may not
-- exist yet on a given install).
CREATE TABLE IF NOT EXISTS `pricing_rules` (
    `id`          INT(11)      NOT NULL AUTO_INCREMENT,
    `rule_key`    VARCHAR(60)  NOT NULL,
    `rule_value`  DECIMAL(8,2) NOT NULL,
    `description` VARCHAR(160) DEFAULT '',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_key` (`rule_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ride_surge_multiplier is intentionally a distinct key from any
-- order-side 'surge_multiplier' row a seed file may have inserted -
-- rides and delivery orders are priced independently.
INSERT INTO `pricing_rules` (rule_key, rule_value, description) VALUES
    ('ride_surge_multiplier', 1.30, 'Ride fare multiplier during peak hours (weekday rush + weekend evenings)')
ON DUPLICATE KEY UPDATE
    rule_key = VALUES(rule_key);

-- Zones: creating delivery_zones here too for the same reason as
-- pricing_rules above (never-auto-applied seed file). Adds est_minutes,
-- which config-etb.php's hardcoded $HAWASSA_DELIVERY_FEES had per zone
-- but the seeded table schema did not.
CREATE TABLE IF NOT EXISTS `delivery_zones` (
    `id`           INT(11)      NOT NULL AUTO_INCREMENT,
    `name`         VARCHAR(60)  NOT NULL,
    `base_fare`    INT(6)       NOT NULL DEFAULT 50,
    `est_minutes`  INT(4)       NOT NULL DEFAULT 20,
    `surge_active` TINYINT(1)   DEFAULT 0,
    `surge_mult`   DECIMAL(4,2) DEFAULT 1.50,
    `sort_order`   INT(4)       DEFAULT 0,
    `active`       TINYINT(1)   DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `delivery_zones` (name, base_fare, est_minutes, sort_order, active) VALUES
    ('Mehal Ketema',         50, 20,  1, 1),
    ('Tabor',                50, 20,  2, 1),
    ('Addis Ketema',         60, 25,  3, 1),
    ('Haik Dar',             70, 30,  4, 1),
    ('Tulu Dimtu',           70, 30,  5, 1),
    ('Misrak',               65, 28,  6, 1),
    ('Referral',             55, 22,  7, 1),
    ('Hawela Teklehaimanot', 75, 35,  8, 1),
    ('Dore Bafana',          80, 35,  9, 1),
    ('Stadium',              45, 15, 10, 1),
    ('Liqe Mender',          50, 18, 11, 1),
    ('University',           60, 25, 12, 1)
ON DUPLICATE KEY UPDATE
    name = VALUES(name);
