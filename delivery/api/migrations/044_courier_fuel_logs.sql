-- ============================================================
-- Courier fuel management - mirrors driver_fuel_logs. Lets a courier
-- log real fuel purchases so real cost/km can be measured instead of
-- always assuming a flat estimate.
-- ============================================================
CREATE TABLE IF NOT EXISTS `courier_fuel_logs` (
  `id`               INT(11)       NOT NULL AUTO_INCREMENT,
  `courier_user_id`  INT(11)       NOT NULL,
  `liters`           DECIMAL(8,2)  NOT NULL,
  `cost_etb`         DECIMAL(10,2) NOT NULL,
  `odometer_km`      DECIMAL(10,1) NULL,
  `notes`            VARCHAR(255)  NULL,
  `logged_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_courier_fuel_logs_courier_date` (`courier_user_id`, `logged_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
