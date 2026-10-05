-- ============================================================
-- Add the missing service_givers.bank_account column.
--
-- sg_update_profile (ServiceGiverController.php) and the Settings page's
-- "Payment Details / Save Bank Account" button have always referenced
-- this column - the frontend and the PHP handler were both built
-- correctly, but the column itself was never actually added to the live
-- schema. Every single save attempt (Business Info AND Bank Account,
-- confirmed via the real error_log: "Unknown column 'bank_account' in
-- 'SET'", the exact same error on every attempt across multiple days)
-- has been failing for this one reason.
--
-- NOTE ON FORMATTING: every comment on its own line, every
-- statement-terminating `;` last on its line, so api/migrate.php's naive
-- line-based statement splitter parses this correctly.

ALTER TABLE `service_givers`
  ADD COLUMN IF NOT EXISTS `bank_account` VARCHAR(100) DEFAULT '' AFTER `identification_number`;
