-- ETEL - two tables that used to be created while a request was running (audit 2026-11-09).
-- 1. upload_attempts: the per-user upload budget used to be counted in login_attempts, which mixed it with the sign-in audit trail.
-- 2. driver_goal_tiers: the driver daily-goal tiers (Admin > Pricing), which the code created on first use.
-- Fully additive and idempotent. Existing installs already have driver_goal_tiers, so nothing is seeded there.

CREATE TABLE IF NOT EXISTS `upload_attempts` (
  `id`           BIGINT(20)   NOT NULL AUTO_INCREMENT,
  `identifier`   VARCHAR(120) NOT NULL,
  `attempted_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_upload_identifier` (`identifier`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DELETE FROM `login_attempts` WHERE `identifier` LIKE 'upload:%';

CREATE TABLE IF NOT EXISTS `driver_goal_tiers` (
  `id`             INT(11)       NOT NULL AUTO_INCREMENT,
  `trips_required` INT(11)       NOT NULL,
  `bonus_etb`      DECIMAL(10,2) NOT NULL,
  `active`         TINYINT(1)    NOT NULL DEFAULT 1,
  `sort_order`     INT(11)       NOT NULL DEFAULT 0,
  `created_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_trips` (`trips_required`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `driver_goal_tiers` (`trips_required`, `bonus_etb`, `sort_order`)
SELECT d.t, d.b, d.s FROM (
  SELECT 2 AS t, 250 AS b, 0 AS s UNION ALL SELECT 4, 510, 1 UNION ALL SELECT 6, 780, 2
  UNION ALL SELECT 8, 1050, 3 UNION ALL SELECT 10, 1350, 4 UNION ALL SELECT 12, 1700, 5
) d WHERE NOT EXISTS (SELECT 1 FROM `driver_goal_tiers`);
