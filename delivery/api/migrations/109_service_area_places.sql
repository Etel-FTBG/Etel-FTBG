-- Migration 109: Quick zones per city (service_area_places). The address shortcuts riders tap to set pickup or drop-off.
--
-- Additive and idempotent: one CREATE TABLE IF NOT EXISTS. No ALTER on any existing table, no transaction statements. Safe to run twice.
-- The app also creates this table by itself the first time it needs it (and seeds Hawassa's twelve zones once), so running this file is optional.
-- Same formatting convention as 101: every comment on its own line, every semicolon last on its line, single quotes only.

CREATE TABLE IF NOT EXISTS `service_area_places` (
  `id`         INT(11)       NOT NULL AUTO_INCREMENT,
  `area_code`  VARCHAR(40)   NOT NULL,
  `name`       VARCHAR(80)   NOT NULL,
  `name_am`    VARCHAR(80)   NOT NULL DEFAULT '',
  `lat`        DECIMAL(10,7) NOT NULL,
  `lng`        DECIMAL(10,7) NOT NULL,
  `active`     TINYINT(1)    NOT NULL DEFAULT 1,
  `sort_order` INT(11)       NOT NULL DEFAULT 0,
  `created_at` DATETIME      NOT NULL,
  `updated_at` DATETIME      NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_place_area` (`area_code`, `active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
