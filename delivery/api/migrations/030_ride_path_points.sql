-- Migration 030: adds ride_path_points, a breadcrumb trail of GPS points
-- recorded during a trip.
--
-- Until now, driver_update_location only ever kept the driver's LATEST
-- position (rides.last_lat/last_lng) plus a running total distance -
-- the actual sequence of points travelled was discarded on every single
-- ping, overwritten by the next one. There was no way to look back at
-- "everywhere the driver actually went" after the fact, or even live
-- mid-trip - only a straight line from wherever you are now to the
-- pickup or dropoff point.
--
-- A dedicated INSERT-only table (rather than a JSON column on rides
-- that would need read-modify-write on every 5-second ping) scales
-- cleanly for a ride with potentially hundreds of recorded points, and
-- keeps this entirely separate from the existing meter/fare columns on
-- rides so nothing about pricing depends on it.
--
-- This starts recording going forward only - existing completed rides
-- have no path history and never will, since the points themselves
-- were never captured at the time.

CREATE TABLE IF NOT EXISTS `ride_path_points` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ride_id` INT UNSIGNED NOT NULL,
    `lat` DECIMAL(10,7) NOT NULL,
    `lng` DECIMAL(10,7) NOT NULL,
    `recorded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ride_id` (`ride_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
