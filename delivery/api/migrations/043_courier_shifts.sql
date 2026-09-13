-- ============================================================
-- Courier shift tracking - mirrors driver_shifts. Couriers currently
-- have no real online/offline toggle at all (the "Online" banner in
-- the app is static text, not backed by any server state change) and
-- no way to compute a real net-ETB-per-hour KPI. This fixes both.
-- ============================================================
CREATE TABLE IF NOT EXISTS `courier_shifts` (
  `id`                INT(11)   NOT NULL AUTO_INCREMENT,
  `courier_user_id`   INT(11)   NOT NULL,
  `started_at`        DATETIME  NOT NULL,
  `ended_at`          DATETIME  NULL,
  `online_seconds`    INT(11)   UNSIGNED NULL,
  PRIMARY KEY (`id`),
  KEY `idx_courier_shifts_courier_started` (`courier_user_id`, `started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
