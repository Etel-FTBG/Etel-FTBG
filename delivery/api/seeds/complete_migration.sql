-- ═══════════════════════════════════════════════════════════════════
-- ETEL Delivery — Complete Migration v4.0.0
-- ───────────────────────────────────────────────────────────────────
-- HOW TO RUN:
--   phpMyAdmin → etel_delivery → Import tab → Choose File → Go
--   (DELIMITER // is parsed automatically by phpMyAdmin file import)
--
-- SAFE FOR BOTH:
--   ✓  Fresh database  — creates all 19 tables and seeds demo data
--   ✓  Existing database — skips existing tables, adds missing columns
--
-- REQUIRES: MySQL 5.7+ or MariaDB 10.2+
--   (JSON column type in telebirr_payments and scheduled_orders)
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;
SET foreign_key_checks = 0;
SET sql_mode = '';

-- ══════════════════════════════════════════════════════════════════
-- SECTION 1 — CORE TABLES
-- ══════════════════════════════════════════════════════════════════

-- ── users ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `users` (
  `id`            INT(11)      NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(120) NOT NULL DEFAULT '',
  `phone`         VARCHAR(25)  NOT NULL,
  `email`         VARCHAR(160) DEFAULT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role`          VARCHAR(20)  NOT NULL DEFAULT 'customer',
  `lang`          VARCHAR(5)   DEFAULT 'en',
  `active`        TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`    DATETIME     DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── couriers ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `couriers` (
  `id`               INT(11)       NOT NULL AUTO_INCREMENT,
  `user_id`          INT(11)       NOT NULL,
  `courier_code`     VARCHAR(20)   NOT NULL,
  `vehicle_type`     VARCHAR(30)   DEFAULT 'motorcycle',
  `plate_number`     VARCHAR(20)   DEFAULT '',
  `home_zone`        VARCHAR(60)   DEFAULT 'Stadium',
  `zone`             VARCHAR(60)   DEFAULT 'Stadium',
  `status`           VARCHAR(20)   DEFAULT 'offline',
  `last_lat`         DECIMAL(10,7) DEFAULT NULL,
  `last_lng`         DECIMAL(10,7) DEFAULT NULL,
  `last_seen`        DATETIME      DEFAULT NULL,
  `rating`           DECIMAL(3,2)  DEFAULT 0.00,
  `total_deliveries` INT(11)       DEFAULT 0,
  `active`           TINYINT(1)    DEFAULT 1,
  `created_at`       DATETIME      DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_code` (`courier_code`),
  KEY `idx_user`   (`user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── service_givers ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `service_givers` (
  `id`                    INT(11)       NOT NULL AUTO_INCREMENT,
  `user_id`               INT(11)       NOT NULL,
  `service_giver_code`    VARCHAR(20)   NOT NULL,
  `business_name`         VARCHAR(200)  NOT NULL DEFAULT '',
  `service_type`          VARCHAR(50)   DEFAULT 'food',
  `vehicle_type`          VARCHAR(30)   DEFAULT 'motorcycle',
  `vehicle_plate`         VARCHAR(20)   DEFAULT '',
  `zone`                  VARCHAR(60)   DEFAULT '',
  `address`               VARCHAR(255)  DEFAULT '',
  `city`                  VARCHAR(100)  DEFAULT 'Hawassa',
  `identification_number` VARCHAR(100)  DEFAULT '',
  `status`                VARCHAR(20)   DEFAULT 'pending',
  `is_online`             TINYINT(1)    DEFAULT 0,
  `average_rating`        DECIMAL(3,2)  DEFAULT 0.00,
  `total_deliveries`      INT(11)       DEFAULT 0,
  `completed_deliveries`  INT(11)       DEFAULT 0,
  `failed_deliveries`     INT(11)       DEFAULT 0,
  `total_earnings`        DECIMAL(10,2) DEFAULT 0.00,
  `rating_count`          INT(11)       DEFAULT 0,
  `last_seen`             DATETIME      DEFAULT NULL,
  `created_at`            DATETIME      DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sg_code` (`service_giver_code`),
  KEY `idx_user`   (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_zone`   (`zone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── delivery_zones ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `delivery_zones` (
  `id`           INT(11)      NOT NULL AUTO_INCREMENT,
  `name`         VARCHAR(60)  NOT NULL,
  `base_fare`    INT(6)       NOT NULL DEFAULT 50,
  `surge_active` TINYINT(1)   DEFAULT 0,
  `surge_mult`   DECIMAL(4,2) DEFAULT 1.50,
  `sort_order`   INT(4)       DEFAULT 0,
  `active`       TINYINT(1)   DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── pricing_rules ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `pricing_rules` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `rule_key`    VARCHAR(60)  NOT NULL,
  `rule_value`  DECIMAL(8,2) NOT NULL,
  `description` VARCHAR(160) DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_key` (`rule_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── orders ─────────────────────────────────────────────────────────
-- Full schema including payment extension columns (subtotal, delivery_fee, etc.)
CREATE TABLE IF NOT EXISTS `orders` (
  `id`                INT(11)       NOT NULL AUTO_INCREMENT,
  `order_code`        VARCHAR(30)   NOT NULL,
  `customer_id`       INT(11)       NOT NULL,
  `courier_id`        INT(11)       DEFAULT NULL,
  `service_giver_id`  INT(11)       DEFAULT NULL,
  `from_zone`         VARCHAR(60)   NOT NULL DEFAULT '',
  `to_zone`           VARCHAR(60)   NOT NULL DEFAULT '',
  `package_type`      VARCHAR(20)   DEFAULT 'parcel',
  `payment_method`    VARCHAR(20)   DEFAULT 'cash',
  `payment_status`    VARCHAR(20)   DEFAULT 'unpaid',
  `fare_total`        DECIMAL(10,2) DEFAULT 0.00,
  `subtotal`          DECIMAL(10,2) DEFAULT 0.00,
  `delivery_fee`      DECIMAL(8,2)  DEFAULT 0.00,
  `service_charge`    DECIMAL(8,2)  DEFAULT 0.00,
  `status`            VARCHAR(30)   DEFAULT 'pending',
  `notes`             TEXT          DEFAULT NULL,
  `recipient_name`    VARCHAR(120)  DEFAULT '',
  `recipient_phone`   VARCHAR(25)   DEFAULT '',
  `recipient_address` VARCHAR(255)  DEFAULT '',
  `sender_name`       VARCHAR(120)  DEFAULT '',
  `sender_phone`      VARCHAR(25)   DEFAULT '',
  `pickup_lat`        DECIMAL(10,7) DEFAULT NULL,
  `pickup_lng`        DECIMAL(10,7) DEFAULT NULL,
  `delivery_lat`      DECIMAL(10,7) DEFAULT NULL,
  `delivery_lng`      DECIMAL(10,7) DEFAULT NULL,
  `placed_at`         DATETIME      DEFAULT CURRENT_TIMESTAMP,
  `delivered_at`      DATETIME      DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_code`       (`order_code`),
  KEY `idx_customer`         (`customer_id`),
  KEY `idx_courier`          (`courier_id`),
  KEY `idx_service_giver`    (`service_giver_id`),
  KEY `idx_status`           (`status`),
  KEY `idx_placed`           (`placed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── order_ratings ──────────────────────────────────────────────────
-- Includes extended courier_rating and food_rating columns
CREATE TABLE IF NOT EXISTS `order_ratings` (
  `id`             INT(11)    NOT NULL AUTO_INCREMENT,
  `order_id`       INT(11)    NOT NULL,
  `customer_id`    INT(11)    DEFAULT NULL,
  `stars`          TINYINT(1) NOT NULL DEFAULT 5,
  `courier_rating` TINYINT(1) DEFAULT NULL,
  `food_rating`    TINYINT(1) DEFAULT NULL,
  `comment`        TEXT       DEFAULT NULL,
  `rated_at`       DATETIME   DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── order_assignments ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `order_assignments` (
  `id`                  INT(11)    NOT NULL AUTO_INCREMENT,
  `order_id`            INT(11)    NOT NULL,
  `service_giver_id`    INT(11)    NOT NULL,
  `status`              VARCHAR(20) DEFAULT 'pending',
  `assigned_at`         DATETIME   DEFAULT CURRENT_TIMESTAMP,
  `accepted_at`         DATETIME   DEFAULT NULL,
  `pickup_qr_scanned`   TINYINT(1) DEFAULT 0,
  `pickup_scanned_at`   DATETIME   DEFAULT NULL,
  `delivery_qr_scanned` TINYINT(1) DEFAULT 0,
  `delivery_scanned_at` DATETIME   DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_order`  (`order_id`),
  KEY `idx_sg`     (`service_giver_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── patch_log ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `patch_log` (
  `id`         INT(11)     NOT NULL AUTO_INCREMENT,
  `version`    VARCHAR(20) NOT NULL,
  `applied_at` DATETIME    DEFAULT CURRENT_TIMESTAMP,
  `applied_by` INT(11)     DEFAULT NULL,
  `notes`      TEXT        DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ══════════════════════════════════════════════════════════════════
-- SECTION 2 — ITEMS / PRODUCTS TABLES
-- ══════════════════════════════════════════════════════════════════

-- ── items ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `items` (
  `id`               INT(11)       NOT NULL AUTO_INCREMENT,
  `service_giver_id` INT(11)       NOT NULL,
  `item_code`        VARCHAR(20)   NOT NULL,
  `name`             VARCHAR(255)  NOT NULL,
  `description`      TEXT          DEFAULT NULL,
  `category`         VARCHAR(30)   NOT NULL DEFAULT 'food',
  `price`            DECIMAL(10,2) NOT NULL,
  `original_price`   DECIMAL(10,2) DEFAULT NULL,
  `image_url`        VARCHAR(500)  DEFAULT NULL,
  `is_available`     TINYINT(1)    DEFAULT 1,
  `stock_quantity`   INT(11)       DEFAULT 0,
  `preparation_time` INT(11)       DEFAULT 30,
  `created_at`       DATETIME      DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_item_code`    (`item_code`),
  KEY `idx_category`           (`category`),
  KEY `idx_available`          (`is_available`),
  KEY `idx_service_giver`      (`service_giver_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── order_items ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `order_items` (
  `id`         INT(11)       NOT NULL AUTO_INCREMENT,
  `order_id`   INT(11)       NOT NULL,
  `item_id`    INT(11)       NOT NULL,
  `item_name`  VARCHAR(255)  NOT NULL,
  `quantity`   INT(11)       NOT NULL DEFAULT 1,
  `unit_price` DECIMAL(10,2) NOT NULL,
  `subtotal`   DECIMAL(10,2) NOT NULL,
  `created_at` DATETIME      DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`),
  KEY `idx_item`  (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ══════════════════════════════════════════════════════════════════
-- SECTION 3 — PAYMENT & WALLET TABLES
-- Requires MySQL 5.7+ / MariaDB 10.2+ (JSON column type)
-- ══════════════════════════════════════════════════════════════════

-- ── telebirr_payments ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `telebirr_payments` (
  `id`                      INT(11)       NOT NULL AUTO_INCREMENT,
  `user_id`                 INT(11)       NOT NULL,
  `order_id`                INT(11)       DEFAULT NULL,
  `amount`                  DECIMAL(10,2) NOT NULL,
  `currency`                VARCHAR(3)    DEFAULT 'ETB',
  `phone`                   VARCHAR(20)   NOT NULL,
  `merchant_transaction_id` VARCHAR(100)  NOT NULL,
  `telebirr_transaction_id` VARCHAR(100)  DEFAULT '',
  `payment_reference`       VARCHAR(100)  DEFAULT '',
  `status`                  VARCHAR(20)   DEFAULT 'pending',
  `response_data`           JSON          DEFAULT NULL,
  `error_message`           TEXT          DEFAULT NULL,
  `retry_count`             INT(4)        DEFAULT 0,
  `created_at`              DATETIME      DEFAULT CURRENT_TIMESTAMP,
  `completed_at`            DATETIME      DEFAULT NULL,
  `updated_at`              DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_merchant_tx` (`merchant_transaction_id`),
  KEY `idx_user`   (`user_id`),
  KEY `idx_order`  (`order_id`),
  KEY `idx_phone`  (`phone`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── payment_retries ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `payment_retries` (
  `id`                  INT(11)     NOT NULL AUTO_INCREMENT,
  `telebirr_payment_id` INT(11)     NOT NULL,
  `attempt_number`      INT(4)      DEFAULT 1,
  `error_code`          VARCHAR(50) DEFAULT '',
  `error_message`       TEXT        DEFAULT NULL,
  `created_at`          DATETIME    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_payment` (`telebirr_payment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── bank_payments ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `bank_payments` (
  `id`           INT(11)       NOT NULL AUTO_INCREMENT,
  `user_id`      INT(11)       NOT NULL,
  `order_id`     INT(11)       DEFAULT NULL,
  `bank`         VARCHAR(20)   DEFAULT 'abysinia',
  `amount`       DECIMAL(10,2) NOT NULL,
  `reference_no` VARCHAR(100)  NOT NULL,
  `status`       VARCHAR(20)   DEFAULT 'pending',
  `verified_by`  INT(11)       DEFAULT NULL,
  `notes`        TEXT          DEFAULT NULL,
  `created_at`   DATETIME      DEFAULT CURRENT_TIMESTAMP,
  `verified_at`  DATETIME      DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user`  (`user_id`),
  KEY `idx_order` (`order_id`),
  KEY `idx_ref`   (`reference_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── user_wallets ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `user_wallets` (
  `id`         INT(11)       NOT NULL AUTO_INCREMENT,
  `user_id`    INT(11)       NOT NULL,
  `balance`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `currency`   CHAR(3)       NOT NULL DEFAULT 'ETB',
  `created_at` DATETIME      DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── wallet_transactions ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `wallet_transactions` (
  `id`            INT(11)       NOT NULL AUTO_INCREMENT,
  `user_id`       INT(11)       NOT NULL,
  `type`          VARCHAR(10)   NOT NULL DEFAULT 'credit',
  `amount`        DECIMAL(10,2) NOT NULL,
  `balance_after` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `reason`        VARCHAR(160)  DEFAULT '',
  `reference_id`  INT(11)       DEFAULT NULL,
  `created_at`    DATETIME      DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── push_tokens ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `push_tokens` (
  `id`         INT(11)     NOT NULL AUTO_INCREMENT,
  `user_id`    INT(11)     NOT NULL,
  `token`      TEXT        NOT NULL,
  `platform`   VARCHAR(10) DEFAULT 'web',
  `created_at` DATETIME    DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── scheduled_orders ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `scheduled_orders` (
  `id`             INT(11)    NOT NULL AUTO_INCREMENT,
  `customer_id`    INT(11)    NOT NULL,
  `scheduled_at`   DATETIME   NOT NULL,
  `repeat_daily`   TINYINT(1) DEFAULT 0,
  `order_template` JSON       NOT NULL,
  `status`         VARCHAR(20) DEFAULT 'active',
  `created_at`     DATETIME   DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_customer`  (`customer_id`),
  KEY `idx_scheduled` (`scheduled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ══════════════════════════════════════════════════════════════════
-- SECTION 4 — CONFIRMATION TABLE
-- ══════════════════════════════════════════════════════════════════

-- ── order_confirmations ────────────────────────────────────────────
-- Records every courier→SG pickup confirmation and courier→customer delivery confirmation
CREATE TABLE IF NOT EXISTS `order_confirmations` (
  `id`                INT(11)      NOT NULL AUTO_INCREMENT,
  `order_id`          INT(11)      NOT NULL,
  `type`              VARCHAR(10)  NOT NULL DEFAULT 'pickup',
  `status`            VARCHAR(10)  NOT NULL DEFAULT 'pending',
  `requested_by`      INT(11)      NOT NULL COMMENT 'courier user_id',
  `requested_at`      DATETIME     DEFAULT CURRENT_TIMESTAMP,
  `responded_by`      INT(11)      DEFAULT NULL COMMENT 'sg or customer user_id',
  `responded_at`      DATETIME     DEFAULT NULL,
  `payment_confirmed` TINYINT(1)   DEFAULT 0,
  `notes`             VARCHAR(500) DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_order_type`  (`order_id`, `type`),
  KEY `idx_status_time` (`status`, `requested_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET foreign_key_checks = 1;

-- ══════════════════════════════════════════════════════════════════
-- SECTION 5 — SAFE COLUMN UPGRADES  (upgrade path only)
-- Uses a stored procedure to add columns only if they are missing.
-- On a fresh install all columns already exist — these are no-ops.
-- ══════════════════════════════════════════════════════════════════

DELIMITER //

DROP PROCEDURE IF EXISTS _etel_add_col //
CREATE PROCEDURE _etel_add_col(IN p_tbl VARCHAR(64), IN p_col VARCHAR(64), IN p_def TEXT)
BEGIN
  -- CONTINUE HANDLER silences "Duplicate column name" errors when column already exists.
  -- Avoids information_schema collation mismatch issues on shared hosting.
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
  SET @_sql = CONCAT('ALTER TABLE `', p_tbl, '` ADD COLUMN `', p_col, '` ', p_def);
  PREPARE _stmt FROM @_sql;
  EXECUTE _stmt;
  DEALLOCATE PREPARE _stmt;
END //

-- orders: GPS coordinate columns (added in v4.1)
CALL _etel_add_col('orders', 'pickup_lat',   'DECIMAL(10,7) DEFAULT NULL') //
CALL _etel_add_col('orders', 'pickup_lng',   'DECIMAL(10,7) DEFAULT NULL') //
CALL _etel_add_col('orders', 'delivery_lat', 'DECIMAL(10,7) DEFAULT NULL') //
CALL _etel_add_col('orders', 'delivery_lng', 'DECIMAL(10,7) DEFAULT NULL') //

-- orders: payment extension columns (added in v3.5 migration)
CALL _etel_add_col('orders', 'service_giver_id',  'INT(11) DEFAULT NULL') //
CALL _etel_add_col('orders', 'payment_status',    "VARCHAR(20) DEFAULT 'unpaid'") //
CALL _etel_add_col('orders', 'subtotal',          'DECIMAL(10,2) DEFAULT 0.00') //
CALL _etel_add_col('orders', 'delivery_fee',      'DECIMAL(8,2) DEFAULT 0.00') //
CALL _etel_add_col('orders', 'service_charge',    'DECIMAL(8,2) DEFAULT 0.00') //
CALL _etel_add_col('orders', 'recipient_address', 'VARCHAR(255) DEFAULT NULL') //
CALL _etel_add_col('orders', 'sender_name',       'VARCHAR(120) DEFAULT NULL') //
CALL _etel_add_col('orders', 'sender_phone',      'VARCHAR(25) DEFAULT NULL') //

-- service_givers: bank account (added in v4.2)
CALL _etel_add_col('service_givers', 'bank_account', "VARCHAR(50) DEFAULT NULL") //

-- order_ratings: extended rating columns (added in v3.5 migration)
CALL _etel_add_col('order_ratings', 'customer_id',    'INT(11) DEFAULT NULL') //
CALL _etel_add_col('order_ratings', 'courier_rating', 'TINYINT(1) DEFAULT NULL') //
CALL _etel_add_col('order_ratings', 'food_rating',    'TINYINT(1) DEFAULT NULL') //

DROP PROCEDURE IF EXISTS _etel_add_col //

DELIMITER ;

-- ══════════════════════════════════════════════════════════════════
-- SECTION 6 — SEED DATA
-- Safe to re-run: uses ON DUPLICATE KEY UPDATE throughout
-- ══════════════════════════════════════════════════════════════════

-- ── Demo users ─────────────────────────────────────────────────────
-- Admin:    phone=0900000000      password=admin1234
-- Customer: phone=+251911000001   password=etel1234
-- Courier:  phone=+251911000002   password=etel1234
INSERT INTO `users` (id, name, phone, password_hash, role, active) VALUES
(1, 'Etel Admin',    '0900000000',    '$2y$10$j877OCvpklrSPDdirlS1hO0/b1x8zjSA6FGqcaXnI5fLzJZc8tQIC', 'admin',    1),
(2, 'Selam Tadesse', '+251911000001', '$2y$10$CZ6rhOlEze5Tgbn3k2NFB.tJIrexk5G55MrXfVIHVCXZTEirz8J1W', 'customer', 1),
(3, 'Dawit Tesfaye', '+251911000002', '$2y$10$CZ6rhOlEze5Tgbn3k2NFB.tJIrexk5G55MrXfVIHVCXZTEirz8J1W', 'courier',  1)
ON DUPLICATE KEY UPDATE name=VALUES(name), role=VALUES(role), active=1;

-- ── Demo courier profile ───────────────────────────────────────────
INSERT INTO `couriers` (user_id, courier_code, vehicle_type, home_zone, zone, status, rating, total_deliveries, active)
VALUES (3, 'C-00001', 'motorcycle', 'Stadium', 'Stadium', 'online', 4.90, 312, 1)
ON DUPLICATE KEY UPDATE status='online', zone='Stadium', rating=4.90, active=1;

-- ── Hawassa delivery zones — 12 zones with correct ETB fees ────────
-- Replaces any old Addis Ababa zones that share the same name.
-- Zones with different names (old Addis ones) are left untouched.
INSERT INTO `delivery_zones` (name, base_fare, surge_active, surge_mult, sort_order, active) VALUES
('Stadium',              45, 0, 1.50,  1, 1),
('Mehal Ketema',         50, 0, 1.50,  2, 1),
('Tabor',                50, 0, 1.50,  3, 1),
('Liqe Mender',          50, 0, 1.50,  4, 1),
('Referral',             55, 0, 1.50,  5, 1),
('Addis Ketema',         60, 0, 1.50,  6, 1),
('University',           60, 0, 1.50,  7, 1),
('Misrak',               65, 0, 1.50,  8, 1),
('Haik Dar',             70, 0, 1.50,  9, 1),
('Tulu Dimtu',           70, 0, 1.50, 10, 1),
('Hawela Teklehaimanot', 75, 0, 1.50, 11, 1),
('Dore Bafana',          80, 0, 1.50, 12, 1)
ON DUPLICATE KEY UPDATE
  base_fare   = VALUES(base_fare),
  sort_order  = VALUES(sort_order),
  active      = 1;

-- ── Pricing rules ──────────────────────────────────────────────────
INSERT INTO `pricing_rules` (rule_key, rule_value, description) VALUES
('base_fare',          15.00, 'Fixed base fee per order'),
('per_km_rate',         8.00, 'Additional fee per km'),
('min_fare',           30.00, 'Minimum order fare'),
('service_charge_pct',  5.00, '5% service charge on subtotal'),
('surge_multiplier',    1.50, 'Multiplier during surge hours'),
('night_multiplier',    1.30, 'Multiplier 10PM-6AM'),
('cross_zone_fee',     20.00, 'Extra fee for cross-zone delivery'),
('doc_fee',            30.00, 'Document handling fee'),
('parcel_fee',         50.00, 'Standard parcel fee'),
('food_fee',           40.00, 'Food and grocery fee'),
('bulk_fee',          120.00, 'Bulk / heavy items fee')
ON DUPLICATE KEY UPDATE rule_value=VALUES(rule_value), description=VALUES(description);

-- ── Sample orders (using Hawassa zones) ────────────────────────────
INSERT INTO `orders` (id, order_code, customer_id, courier_id, from_zone, to_zone, package_type, payment_method, fare_total, status, placed_at) VALUES
(1, 'ETD-2249', 2, 1,    'Stadium',      'Mehal Ketema', 'document', 'cash',     45,  'delivered', '2026-05-20 09:44:00'),
(2, 'ETD-2250', 2, 1,    'Tabor',        'University',   'food',     'telebirr', 65,  'delivered', '2026-05-20 09:58:00'),
(3, 'ETD-2251', 2, NULL, 'Referral',     'Misrak',       'bulk',     'cash',     130, 'pending',   '2026-05-20 10:15:00'),
(4, 'ETD-2252', 2, 1,    'Stadium',      'Haik Dar',     'parcel',   'cash',     90,  'delivered', '2026-05-20 10:18:00'),
(5, 'ETD-2253', 2, NULL, 'Mehal Ketema', 'Dore Bafana',  'food',     'telebirr', 75,  'pending',   '2026-05-20 10:31:00'),
(6, 'ETD-2254', 2, NULL, 'Tabor',        'University',   'document', 'cash',     60,  'pending',   '2026-05-20 10:38:00'),
(7, 'ETD-2255', 2, 1,    'Stadium',      'Addis Ketema', 'parcel',   'cash',     85,  'assigned',  '2026-05-20 10:42:00')
ON DUPLICATE KEY UPDATE status=VALUES(status);

-- ── Patch log entry ────────────────────────────────────────────────
INSERT INTO `patch_log` (version, notes) VALUES
('4.0.0', 'Complete migration: users + couriers + service_givers + items + orders (full columns) + payments + wallet + confirmations');

-- ══════════════════════════════════════════════════════════════════
-- SECTION 7 — VERIFICATION
-- Review these result sets in phpMyAdmin after import
-- ══════════════════════════════════════════════════════════════════

-- Row counts from user tables (no information_schema mix — avoids #1109 error)
SELECT 'users'              AS tbl, COUNT(*) AS cnt FROM `users`
UNION ALL SELECT 'couriers',              COUNT(*) FROM `couriers`
UNION ALL SELECT 'service_givers',        COUNT(*) FROM `service_givers`
UNION ALL SELECT 'delivery_zones',        COUNT(*) FROM `delivery_zones`
UNION ALL SELECT 'pricing_rules',         COUNT(*) FROM `pricing_rules`
UNION ALL SELECT 'orders',                COUNT(*) FROM `orders`
UNION ALL SELECT 'order_ratings',         COUNT(*) FROM `order_ratings`
UNION ALL SELECT 'order_assignments',     COUNT(*) FROM `order_assignments`
UNION ALL SELECT 'order_confirmations',   COUNT(*) FROM `order_confirmations`
UNION ALL SELECT 'items',                 COUNT(*) FROM `items`
UNION ALL SELECT 'order_items',           COUNT(*) FROM `order_items`
UNION ALL SELECT 'telebirr_payments',     COUNT(*) FROM `telebirr_payments`
UNION ALL SELECT 'payment_retries',       COUNT(*) FROM `payment_retries`
UNION ALL SELECT 'bank_payments',         COUNT(*) FROM `bank_payments`
UNION ALL SELECT 'user_wallets',          COUNT(*) FROM `user_wallets`
UNION ALL SELECT 'wallet_transactions',   COUNT(*) FROM `wallet_transactions`
UNION ALL SELECT 'push_tokens',           COUNT(*) FROM `push_tokens`
UNION ALL SELECT 'scheduled_orders',      COUNT(*) FROM `scheduled_orders`
UNION ALL SELECT 'patch_log',             COUNT(*) FROM `patch_log`;

-- Table list from information_schema (standalone — no user table references)
SELECT TABLE_NAME AS `table`, TABLE_ROWS AS approx_rows, CREATE_TIME AS created_at
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
ORDER BY TABLE_NAME;

-- orders column list (standalone information_schema query)
SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME   = 'orders'
ORDER BY ORDINAL_POSITION;

-- Final status line
SELECT 'Migration v4.0.0 complete' AS status, DATABASE() AS db_name, NOW() AS completed_at;
