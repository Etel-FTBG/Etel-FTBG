-- Migration 065: ratings & reviews upgrade.
-- order_ratings gets quick tags and an optional public reply from the shop.
-- Then the stored shop rating (service_givers.average_rating / rating_count) is backfilled from
-- the ratings that already exist - nothing ever updated it before, so shop cards showed no rating.
-- New ratings keep it up to date (api/lib/reviews.php), which also adds the order_ratings
-- columns on first use, so code and migration can deploy in either order.
--
-- NOTE ON FORMATTING: every comment on its own line, every statement-terminating
-- semicolon last on its line (api/migrate.php splits by line).

ALTER TABLE `order_ratings` ADD COLUMN IF NOT EXISTS `tags` VARCHAR(255) NULL DEFAULT NULL;

ALTER TABLE `order_ratings` ADD COLUMN IF NOT EXISTS `shop_reply` VARCHAR(500) NULL DEFAULT NULL;

ALTER TABLE `order_ratings` ADD COLUMN IF NOT EXISTS `shop_replied_at` DATETIME NULL DEFAULT NULL;

UPDATE `service_givers` sg SET sg.`average_rating` = COALESCE((SELECT ROUND(AVG(r.`stars`), 2) FROM `order_ratings` r JOIN `order_assignments` oa ON oa.`order_id` = r.`order_id` WHERE oa.`service_giver_id` = sg.`id`), sg.`average_rating`), sg.`rating_count` = (SELECT COUNT(*) FROM `order_ratings` r JOIN `order_assignments` oa ON oa.`order_id` = r.`order_id` WHERE oa.`service_giver_id` = sg.`id`);
