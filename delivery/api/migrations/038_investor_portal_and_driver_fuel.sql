-- ============================================================
-- Investor self-service portal access + driver fuel management
-- ============================================================

-- Each investor gets a unique, revocable access token instead of a
-- full login account - simplest secure-enough approach for a small,
-- known set of real people the founder personally adds. The admin
-- shares the resulting link (e.g. via WhatsApp); regenerating the
-- token instantly invalidates any old link. Feature flags let the
-- founder control exactly what each investor can see, independently -
-- e.g. a newer investor might only see their own stake, while a
-- longtime one also sees the cap table and growth planner.
ALTER TABLE `equity_investors`
  ADD COLUMN `access_token` VARCHAR(64) NULL UNIQUE AFTER `notes`,
  ADD COLUMN `can_view_captable` TINYINT(1) NOT NULL DEFAULT 1 AFTER `access_token`,
  ADD COLUMN `can_view_distributions` TINYINT(1) NOT NULL DEFAULT 1 AFTER `can_view_captable`,
  ADD COLUMN `can_view_growth_planner` TINYINT(1) NOT NULL DEFAULT 0 AFTER `can_view_distributions`;

-- Backfill existing investors with a real token so current rows
-- become usable immediately, not just newly-added ones.
UPDATE `equity_investors` SET `access_token` = SHA2(CONCAT(id, '-', investor_code, '-', RAND()), 256)
  WHERE `access_token` IS NULL;

-- Real fuel purchase log, so "fuel cost per km" can become an actual
-- measured number per driver instead of always using the ~8 ETB/km
-- system-wide estimate from the recruitment playbook.
CREATE TABLE IF NOT EXISTS `driver_fuel_logs` (
  `id`              INT(11)        NOT NULL AUTO_INCREMENT,
  `driver_user_id`  INT(11)        NOT NULL,
  `liters`          DECIMAL(6,2)   NOT NULL,
  `cost_etb`        DECIMAL(10,2)  NOT NULL,
  `odometer_km`     DECIMAL(10,1)  NULL,
  `logged_at`       DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_driver_fuel_logs_driver` (`driver_user_id`, `logged_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
