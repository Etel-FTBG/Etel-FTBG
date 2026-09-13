-- Migration 032: adds rides.last_location_at - the timestamp of the
-- last GPS point actually accepted into the meter for this ride
-- (distinct from ride_path_points.recorded_at, which logs every
-- accepted point historically; this is just the single most recent one,
-- kept directly on rides so driver_update_location can read it in the
-- same query it already runs, with no extra lookup).
--
-- Used to replace the meter's flat 2.0 km per-ping "GPS glitch" ceiling
-- with one that scales with how much time actually passed since the
-- last accepted point. The flat ceiling was fine for normal ~5s pings
-- but silently discarded real distance whenever a driver was
-- backgrounded for several minutes (e.g. switched to another app) and
-- covered more than 2 km before the next ping landed - that entire
-- segment was being treated as a GPS glitch and dropped, undercharging
-- the fare for real distance driven.

ALTER TABLE `rides`
    ADD COLUMN `last_location_at` DATETIME NULL DEFAULT NULL;
