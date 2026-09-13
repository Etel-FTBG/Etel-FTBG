-- Adds coordinates to delivery_zones so a customer's GPS position can be
-- matched to the nearest zone automatically (see delivery_nearest_zone()
-- in lib/delivery_pricing.php), instead of always requiring them to
-- know and type the exact zone name. Nullable - existing zones start
-- with no coordinates until seed-zone-coords.php (run once) geocodes
-- each zone's name into a real lat/lng via Google's Geocoding API.
ALTER TABLE `delivery_zones`
    ADD COLUMN `lat` DECIMAL(9,6) NULL AFTER `name`,
    ADD COLUMN `lng` DECIMAL(9,6) NULL AFTER `lat`;
