-- Migration 116: suspected fake GPS from drivers.
-- One row per sighting: kind 'mock' = the phone itself said the position comes from a mock-location app (Android),
-- kind 'jump' = the position jumped farther than a vehicle can travel in the time between two pings.
-- At most one row per driver and kind every 10 minutes (the app pings every 5 seconds), so the table stays small.
-- Additive and idempotent: one CREATE TABLE IF NOT EXISTS, no ALTER, no seed rows.

CREATE TABLE IF NOT EXISTS `driver_gps_flags` (
  `id`             INT(11)       NOT NULL AUTO_INCREMENT,
  `driver_user_id` INT(11)       NOT NULL,
  `kind`           VARCHAR(10)   NOT NULL,
  `detail`         VARCHAR(160)  NOT NULL DEFAULT '',
  `lat`            DECIMAL(10,7) DEFAULT NULL,
  `lng`            DECIMAL(10,7) DEFAULT NULL,
  `created_at`     DATETIME      DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_driver_kind` (`driver_user_id`, `kind`, `created_at`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
