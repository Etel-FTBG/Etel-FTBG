-- Migration 085: lock the System Test demo accounts.
--
-- The system test used to create 0900000001-0900000004 with the fixed
-- password "demotest1234", which is written in the code. Those accounts exist
-- on the live site, so anyone who knew the password could sign in as a demo
-- customer, driver, shop owner or courier. This gives them an unusable
-- password hash (nothing verifies against it) and bumps token_version so any
-- token already issued stops working. The system test itself works on the
-- database directly and does not sign in, and new demo accounts are now
-- created with a random password, so nothing else changes.
--
-- NOTE ON FORMATTING: every comment on its own line, every
-- statement-terminating `;` last on its line, so api/migrate.php's naive
-- line-based statement splitter parses this correctly.

UPDATE `users` SET `password_hash` = '!locked', `token_version` = `token_version` + 1 WHERE `phone` IN ('0900000001', '0900000002', '0900000003', '0900000004') AND `name` LIKE '%(System Test)%';
