-- Migration 061: users.etelid_limited - marks a Delivery/Ride account whose
-- linked EtelID is a limited (Ride-only) account, i.e. it was created with the
-- simplified Quick Register and has no profile photo / Fayda ID on file yet.
-- boot.php refuses limited users everything except Ride (orders, service
-- givers, equb, courier) until EtelID reports the profile complete.
--
-- NOTE ON FORMATTING: same convention as prior migrations - every comment on
-- its own line, every statement-terminating semicolon last on its line, so
-- api/migrate.php's naive line-based splitter parses this correctly.
-- etelid_bridge.php also adds this column on first use, so the code and the
-- migration can be deployed in either order.

ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `etelid_limited` TINYINT(1) NOT NULL DEFAULT 0;
