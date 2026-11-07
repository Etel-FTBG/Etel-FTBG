-- Migration 106: Service areas (cities). People only get the services of the city they are in: map search, drivers, shops, orders and rides.
--
-- Additive and idempotent: two CREATE TABLE IF NOT EXISTS and INSERT IGNORE (never overwrites a value an admin changed).
-- No ALTER on any existing table, no transaction statements. Safe to run twice (phpMyAdmin or api/migrate.php). The app also creates these tables by itself the
-- first time it needs them, so running this file is optional.
-- Same formatting convention as 101: every comment on its own line, every semicolon last on its line, single quotes only.
-- An area is a city: a centre and a radius in km. The Amharic names are written as hex so this file stays ASCII.

CREATE TABLE IF NOT EXISTS `service_areas` (
  `id`         INT(11)       NOT NULL AUTO_INCREMENT,
  `code`       VARCHAR(40)   NOT NULL,
  `name`       VARCHAR(80)   NOT NULL,
  `name_am`    VARCHAR(80)   NOT NULL DEFAULT '',
  `center_lat` DECIMAL(10,7) NOT NULL,
  `center_lng` DECIMAL(10,7) NOT NULL,
  `radius_km`  DECIMAL(6,2)  NOT NULL DEFAULT 10,
  `active`     TINYINT(1)    NOT NULL DEFAULT 1,
  `sort_order` INT(11)       NOT NULL DEFAULT 0,
  `created_at` DATETIME      NOT NULL,
  `updated_at` DATETIME      NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_service_area_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `service_area_settings` (
  `k`          VARCHAR(40)  NOT NULL,
  `v`          VARCHAR(255) NOT NULL DEFAULT '',
  `updated_at` DATETIME     NOT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `service_areas` (`code`, `name`, `name_am`, `center_lat`, `center_lng`, `radius_km`, `active`, `sort_order`, `created_at`, `updated_at`) VALUES
  ('hawassa', 'Hawassa', CONVERT(0xE18880E18B8BE188B3 USING utf8mb4), 7.0621000, 38.4763000, 12, 1, 1, NOW(), NOW()),
  ('shashemene', 'Shashemene', CONVERT(0xE188BBE188B8E18898E18A92 USING utf8mb4), 7.2000000, 38.5900000, 10, 1, 2, NOW(), NOW());

INSERT IGNORE INTO `service_area_settings` (`k`, `v`, `updated_at`) VALUES
  ('enforce', '1', NOW());
