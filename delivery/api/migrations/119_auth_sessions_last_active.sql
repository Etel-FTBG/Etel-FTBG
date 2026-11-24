-- Migration 119: "last_active" on the signed-in devices table = the last time the PERSON used the app (a tap, a key press), as reported by the app itself.
-- last_seen (migration 117) moves on every request, including the background refreshes of the shop / courier / driver apps, so it cannot tell "in use" from
-- "left open". A session that has never reported activity (an app version without the idle script) is never signed out for being idle.
-- The server also adds this column by itself on first use, so running this file is optional. Additive and idempotent.
-- (One statement, `;` last on its line: api/migrate.php splits statements line by line.)

ALTER TABLE `auth_sessions` ADD COLUMN IF NOT EXISTS `last_active` DATETIME NULL DEFAULT NULL;
