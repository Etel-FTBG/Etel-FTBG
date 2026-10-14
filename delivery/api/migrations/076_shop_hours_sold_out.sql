-- Migration 076: shop opening hours and "sold out today".
-- service_givers.opens_at / closes_at: the hours a shop takes orders (NULL = no hours, only the online switch decides).
--   A window that ends before it starts (22:00 -> 02:00) runs overnight.
-- items.sold_out_date: the item is sold out while this is today's date, so it comes back by itself tomorrow.
-- Used by api/lib/shop_hours.php. The code also creates these columns on first use when this migration was not run.
ALTER TABLE `service_givers` ADD COLUMN IF NOT EXISTS `opens_at` TIME NULL DEFAULT NULL;
ALTER TABLE `service_givers` ADD COLUMN IF NOT EXISTS `closes_at` TIME NULL DEFAULT NULL;
ALTER TABLE `items` ADD COLUMN IF NOT EXISTS `sold_out_date` DATE NULL DEFAULT NULL;
