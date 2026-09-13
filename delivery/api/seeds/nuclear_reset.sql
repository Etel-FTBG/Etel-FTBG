-- ═══════════════════════════════════════════════════════════
-- ETEL Delivery — Nuclear Reset + Fresh Import
-- phpMyAdmin → etel_delivery → SQL tab → paste → Go
-- ═══════════════════════════════════════════════════════════

SET foreign_key_checks = 0;

DROP TABLE IF EXISTS `order_ratings`;
DROP TABLE IF EXISTS `order_status_log`;
DROP TABLE IF EXISTS `payments`;
DROP TABLE IF EXISTS `courier_earnings`;
DROP TABLE IF EXISTS `sessions`;
DROP TABLE IF EXISTS `patch_log`;
DROP TABLE IF EXISTS `orders`;
DROP TABLE IF EXISTS `couriers`;
DROP TABLE IF EXISTS `delivery_zones`;
DROP TABLE IF EXISTS `zones`;
DROP TABLE IF EXISTS `pricing_rules`;
DROP TABLE IF EXISTS `users`;
DROP VIEW IF EXISTS `v_orders_full`;
DROP VIEW IF EXISTS `v_daily_revenue`;

SET foreign_key_checks = 1;

-- ── USERS ─────────────────────────────────────────────────
CREATE TABLE `users` (
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

-- ── COURIERS ──────────────────────────────────────────────
CREATE TABLE `couriers` (
  `id`               INT(11)       NOT NULL AUTO_INCREMENT,
  `user_id`          INT(11)       NOT NULL,
  `courier_code`     VARCHAR(20)   NOT NULL,
  `vehicle_type`     VARCHAR(30)   DEFAULT 'motorcycle',
  `plate_number`     VARCHAR(20)   DEFAULT '',
  `home_zone`        VARCHAR(60)   DEFAULT 'Bole',
  `zone`             VARCHAR(60)   DEFAULT 'Bole',
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
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── DELIVERY ZONES ────────────────────────────────────────
CREATE TABLE `delivery_zones` (
  `id`           INT(11)      NOT NULL AUTO_INCREMENT,
  `name`         VARCHAR(60)  NOT NULL,
  `base_fare`    INT(6)       NOT NULL DEFAULT 40,
  `surge_active` TINYINT(1)   DEFAULT 0,
  `surge_mult`   DECIMAL(4,2) DEFAULT 1.50,
  `sort_order`   INT(4)       DEFAULT 0,
  `active`       TINYINT(1)   DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── PRICING RULES ─────────────────────────────────────────
CREATE TABLE `pricing_rules` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `rule_key`    VARCHAR(60)  NOT NULL,
  `rule_value`  DECIMAL(8,2) NOT NULL,
  `description` VARCHAR(160) DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_key` (`rule_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── ORDERS ────────────────────────────────────────────────
CREATE TABLE `orders` (
  `id`              INT(11)       NOT NULL AUTO_INCREMENT,
  `order_code`      VARCHAR(30)   NOT NULL,
  `customer_id`     INT(11)       NOT NULL,
  `courier_id`      INT(11)       DEFAULT NULL,
  `from_zone`       VARCHAR(60)   NOT NULL,
  `to_zone`         VARCHAR(60)   NOT NULL,
  `package_type`    VARCHAR(20)   DEFAULT 'parcel',
  `payment_method`  VARCHAR(20)   DEFAULT 'cash',
  `fare_total`      DECIMAL(10,2) DEFAULT 0.00,
  `status`          VARCHAR(30)   DEFAULT 'pending',
  `notes`           TEXT          DEFAULT NULL,
  `recipient_name`  VARCHAR(120)  DEFAULT '',
  `recipient_phone` VARCHAR(25)   DEFAULT '',
  `placed_at`       DATETIME      DEFAULT CURRENT_TIMESTAMP,
  `delivered_at`    DATETIME      DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_code` (`order_code`),
  KEY `idx_customer` (`customer_id`),
  KEY `idx_courier`  (`courier_id`),
  KEY `idx_status`   (`status`),
  KEY `idx_placed`   (`placed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── ORDER RATINGS ─────────────────────────────────────────
CREATE TABLE `order_ratings` (
  `id`       INT(11)    NOT NULL AUTO_INCREMENT,
  `order_id` INT(11)    NOT NULL,
  `stars`    TINYINT(1) NOT NULL DEFAULT 5,
  `comment`  TEXT       DEFAULT NULL,
  `rated_at` DATETIME   DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── PATCH LOG ─────────────────────────────────────────────
CREATE TABLE `patch_log` (
  `id`         INT(11)     NOT NULL AUTO_INCREMENT,
  `version`    VARCHAR(20) NOT NULL,
  `applied_at` DATETIME    DEFAULT CURRENT_TIMESTAMP,
  `applied_by` INT(11)     DEFAULT NULL,
  `notes`      TEXT        DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ════════════════════════════════════════════════════════════
-- SEED — passwords verified correct
-- admin1234 → hash below   etel1234 → hash below
-- ════════════════════════════════════════════════════════════

INSERT INTO `users` (id, name, phone, password_hash, role, active) VALUES
(1, 'Etel Admin',    '0900000000',    '$2y$10$j877OCvpklrSPDdirlS1hO0/b1x8zjSA6FGqcaXnI5fLzJZc8tQIC', 'admin',    1),
(2, 'Selam Tadesse', '+251911000001', '$2y$10$CZ6rhOlEze5Tgbn3k2NFB.tJIrexk5G55MrXfVIHVCXZTEirz8J1W',  'customer', 1),
(3, 'Dawit Tesfaye', '+251911000002', '$2y$10$CZ6rhOlEze5Tgbn3k2NFB.tJIrexk5G55MrXfVIHVCXZTEirz8J1W',  'courier',  1);

INSERT INTO `couriers` (user_id, courier_code, vehicle_type, home_zone, zone, status, rating, total_deliveries, active)
VALUES (3, 'C-00001', 'motorcycle', 'Bole', 'Bole', 'online', 4.90, 312, 1);

INSERT INTO `delivery_zones` (id, name, base_fare, surge_active, surge_mult, sort_order) VALUES
(1,'Mehal Ketema',45,1,1.40,1),(2,'Tabor',40,0,1.40,2),(3,'Addis Ketema',45,0,1.40,3),
(4,'Haik Dar',50,1,1.40,4),(5,'Tulu Dimtu',50,0,1.40,5),(6,'Misrak',45,0,1.40,6),
(7,'Referral',55,0,1.40,7),(8,'Hawela Teklehaimanot',60,0,1.40,8),(9,'Dore Bafana',55,0,1.40,9),
(10,'Stadium',45,1,1.40,10),(11,'Liqe Mender',40,0,1.40,11),(12,'University',50,0,1.40,12);

INSERT INTO `pricing_rules` (rule_key, rule_value, description) VALUES
('base_fare',15,'Fixed base fee'),('per_km_rate',8,'Per km'),
('min_fare',30,'Minimum fare'),('surge_multiplier',1.5,'Surge'),
('night_multiplier',1.3,'Night rate'),('cross_zone_fee',20,'Cross zone'),
('doc_fee',30,'Document'),('parcel_fee',50,'Parcel'),
('food_fee',40,'Food'),('bulk_fee',120,'Bulk');

INSERT INTO `orders` (order_code,customer_id,courier_id,from_zone,to_zone,package_type,payment_method,fare_total,status,placed_at) VALUES
('ETD-2249',2,1,'Lideta','Urael','document','etelpay',45,'delivered','2026-05-20 09:44:00'),
('ETD-2250',2,1,'Piassa','CMC','food','telebirr',65,'delivered','2026-05-20 09:58:00'),
('ETD-2251',2,NULL,'Gerji','Mexico','bulk','cash',130,'pending','2026-05-20 10:15:00'),
('ETD-2252',2,1,'Urael','Bole','parcel','etelpay',90,'delivered','2026-05-20 10:18:00'),
('ETD-2253',2,NULL,'Megenagna','Sarbet','food','telebirr',75,'pending','2026-05-20 10:31:00'),
('ETD-2254',2,NULL,'CMC','Piassa','document','cash',60,'pending','2026-05-20 10:38:00'),
('ETD-2255',2,1,'Bole','Kazanchis','parcel','etelpay',85,'assigned','2026-05-20 10:42:00');

-- ── VERIFY ────────────────────────────────────────────────
SELECT id, name, phone, role, active,
       LEFT(password_hash, 20) AS hash_ok
FROM `users` ORDER BY id;
