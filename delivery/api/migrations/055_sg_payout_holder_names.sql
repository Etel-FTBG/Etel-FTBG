-- ============================================================
-- Account holder name per payout method.
--
-- Bank/Telebirr account numbers alone aren't enough for the founder to
-- confirm a payout change is legitimate - a holder name that doesn't
-- match the business owner's name is exactly the kind of thing this
-- approval queue exists to catch. Adds a name field alongside each
-- existing account-number field, and the matching old/new pairs on the
-- approval-request table so admin can see both together when reviewing.
--
-- NOTE ON FORMATTING: every comment on its own line, every
-- statement-terminating `;` last on its line, so api/migrate.php's naive
-- line-based statement splitter parses this correctly.

ALTER TABLE `service_givers`
  ADD COLUMN `cbe_account_name` VARCHAR(100) NULL DEFAULT NULL AFTER `cbe_account`;

ALTER TABLE `service_givers`
  ADD COLUMN `abysinia_account_name` VARCHAR(100) NULL DEFAULT NULL AFTER `abysinia_account`;

ALTER TABLE `service_givers`
  ADD COLUMN `telebirr_account_name` VARCHAR(100) NULL DEFAULT NULL AFTER `telebirr_number`;

ALTER TABLE `sg_payout_change_requests`
  ADD COLUMN `old_cbe_account_name` VARCHAR(100) NULL DEFAULT NULL AFTER `old_cbe_account`;

ALTER TABLE `sg_payout_change_requests`
  ADD COLUMN `new_cbe_account_name` VARCHAR(100) NULL DEFAULT NULL AFTER `new_cbe_account`;

ALTER TABLE `sg_payout_change_requests`
  ADD COLUMN `old_abysinia_account_name` VARCHAR(100) NULL DEFAULT NULL AFTER `old_abysinia_account`;

ALTER TABLE `sg_payout_change_requests`
  ADD COLUMN `new_abysinia_account_name` VARCHAR(100) NULL DEFAULT NULL AFTER `new_abysinia_account`;

ALTER TABLE `sg_payout_change_requests`
  ADD COLUMN `old_telebirr_account_name` VARCHAR(100) NULL DEFAULT NULL AFTER `old_telebirr_number`;

ALTER TABLE `sg_payout_change_requests`
  ADD COLUMN `new_telebirr_account_name` VARCHAR(100) NULL DEFAULT NULL AFTER `new_telebirr_number`;
