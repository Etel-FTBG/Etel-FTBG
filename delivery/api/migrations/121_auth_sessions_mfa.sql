-- Migration 121: "mfa_ok" on the signed-in devices table = this session was made by an EtelID sign-in whose authenticator code was checked. The admin two-step rule
-- counts that as the admin's second step, so a person with EtelID two-step is asked for one code, not two. A password-only sign-in is never marked.
-- The server also adds this column by itself on first use, so running this file is optional. Additive and idempotent.
-- (One statement, `;` last on its line: api/migrate.php splits statements line by line.)

ALTER TABLE `auth_sessions` ADD COLUMN IF NOT EXISTS `mfa_ok` TINYINT(1) NOT NULL DEFAULT 0;
