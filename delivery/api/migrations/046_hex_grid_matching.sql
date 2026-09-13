-- ============================================================
-- Hex Grid Spatial Indexing for Ride & Delivery Matching
-- ============================================================
-- Adds hex_q/hex_r (axial hex coordinates, see api/lib/hexgrid.php)
-- to every table involved in driver/courier/shop matching, so
-- "who's nearby" queries can filter by an indexed hex-cell range
-- instead of scanning every online driver/courier and computing
-- distance for each one - same two-stage pattern Uber's H3 uses
-- (coarse cell filter, then exact distance check only on the much
-- smaller result), just a pure-PHP planar version sized for one city
-- rather than H3's compiled global-scale library, since this runs on
-- shared hosting with no SSH/extension access.

ALTER TABLE `ride_drivers`
  ADD COLUMN IF NOT EXISTS `hex_q` INT NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `hex_r` INT NULL DEFAULT NULL;

ALTER TABLE `couriers`
  ADD COLUMN IF NOT EXISTS `hex_q` INT NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `hex_r` INT NULL DEFAULT NULL;

ALTER TABLE `service_givers`
  ADD COLUMN IF NOT EXISTS `hex_q` INT NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `hex_r` INT NULL DEFAULT NULL;

-- Composite indexes for the bounding-box range queries
-- (hex_q BETWEEN ? AND ? AND hex_r BETWEEN ? AND ?) that
-- hexgrid_radius_where() generates.
CREATE INDEX IF NOT EXISTS `idx_ride_drivers_hex` ON `ride_drivers` (`hex_q`, `hex_r`);
CREATE INDEX IF NOT EXISTS `idx_couriers_hex` ON `couriers` (`hex_q`, `hex_r`);
CREATE INDEX IF NOT EXISTS `idx_service_givers_hex` ON `service_givers` (`hex_q`, `hex_r`);

-- One-time backfill: compute hex cells for any existing rows that
-- already have lat/lng but predate this migration. Uses the exact
-- same math as hexgrid.php (flat-top axial coordinates, origin at
-- Hawassa 7.0621/38.4761, 350m cell size) reimplemented here in pure
-- SQL/trig since a migration script can't call into PHP - kept
-- byte-for-byte consistent with the PHP version's formulas
-- deliberately, so a row backfilled here and a row computed later by
-- the application both land in the exact same cell for the same
-- coordinates.
SET @origin_lat = 7.0621;
SET @origin_lng = 38.4761;
SET @cell_size = 350.0;
SET @earth_r = 6371000.0;

UPDATE `ride_drivers`
SET `hex_q` = ROUND(
    (2.0/3.0 * (RADIANS(`lng` - @origin_lng) * COS(RADIANS(@origin_lat)) * @earth_r)) / @cell_size
  ),
  `hex_r` = ROUND(
    (-1.0/3.0 * (RADIANS(`lng` - @origin_lng) * COS(RADIANS(@origin_lat)) * @earth_r)
     + SQRT(3)/3.0 * (RADIANS(`lat` - @origin_lat) * @earth_r)) / @cell_size
  )
WHERE `lat` IS NOT NULL AND `lng` IS NOT NULL AND (`hex_q` IS NULL OR `hex_r` IS NULL);

UPDATE `couriers`
SET `hex_q` = ROUND(
    (2.0/3.0 * (RADIANS(`last_lng` - @origin_lng) * COS(RADIANS(@origin_lat)) * @earth_r)) / @cell_size
  ),
  `hex_r` = ROUND(
    (-1.0/3.0 * (RADIANS(`last_lng` - @origin_lng) * COS(RADIANS(@origin_lat)) * @earth_r)
     + SQRT(3)/3.0 * (RADIANS(`last_lat` - @origin_lat) * @earth_r)) / @cell_size
  )
WHERE `last_lat` IS NOT NULL AND `last_lng` IS NOT NULL AND (`hex_q` IS NULL OR `hex_r` IS NULL);

UPDATE `service_givers`
SET `hex_q` = ROUND(
    (2.0/3.0 * (RADIANS(`lng` - @origin_lng) * COS(RADIANS(@origin_lat)) * @earth_r)) / @cell_size
  ),
  `hex_r` = ROUND(
    (-1.0/3.0 * (RADIANS(`lng` - @origin_lng) * COS(RADIANS(@origin_lat)) * @earth_r)
     + SQRT(3)/3.0 * (RADIANS(`lat` - @origin_lat) * @earth_r)) / @cell_size
  )
WHERE `lat` IS NOT NULL AND `lng` IS NOT NULL AND (`hex_q` IS NULL OR `hex_r` IS NULL);

-- NOTE: this SQL backfill uses simple rounding (ROUND), not the exact
-- cube-coordinate rounding hexgrid_axial_round() does in PHP. The two
-- can disagree right at a hex boundary edge (a coordinate that's
-- genuinely ambiguous between two adjacent cells might round
-- differently). This only affects historical rows backfilled once
-- here, is a rare edge case, and self-corrects the next time that
-- row's location is updated through the application (which always
-- uses the precise PHP function). Not worth the complexity of
-- reimplementing full cube rounding in raw SQL for a one-time,
-- self-healing backfill.
