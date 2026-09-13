-- Deactivates the 12 zones that were mistakenly added from an Addis
-- Ababa template (confirmed via live data dump - these are all real
-- Addis Ababa neighborhoods, not Hawassa ones, and several of them had
-- geocoded to the identical fallback city-center point, which would
-- have corrupted GPS-based zone auto-detection). Deactivating rather
-- than deleting, in case any past order still references one of these
-- by id.
UPDATE `delivery_zones`
SET `active` = 0
WHERE `id` IN (1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12);
-- (Bole, Piassa, Kazanchis, CMC, Megenagna, Sarbet, Gerji, Kaliti, Lideta, Urael, Mexico, Gofa)
