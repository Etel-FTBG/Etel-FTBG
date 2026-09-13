-- ============================================================
-- Separate, independent bank accounts per bank (not one shared
-- "payout_bank + bank_account" pair), and a place to store uploaded
-- logo images for each payment method.
--
-- A business can now have a CBE account AND an Abysinia account AND a
-- Telebirr number all configured at once, each shown as its own card
-- (same pattern Telebirr already used) instead of a single dropdown
-- forcing a choice between banks. The old payout_bank/bank_account
-- columns are left in place (harmless, unused going forward) rather
-- than dropped, since a payout change request may already reference
-- them.
--
-- payment_method_logos lets the founder upload a real logo image per
-- method (telebirr/cbe/abysinia/wallet) via the admin panel - every
-- screen that shows a payment method icon checks this table first and
-- only falls back to the custom-drawn badge if nothing's been
-- uploaded yet.
--
-- NOTE ON FORMATTING: every comment on its own line, every
-- statement-terminating `;` last on its line, so api/migrate.php's naive
-- line-based statement splitter parses this correctly.

ALTER TABLE `service_givers`
  ADD COLUMN `cbe_account` VARCHAR(100) NULL DEFAULT NULL AFTER `telebirr_number`;

ALTER TABLE `service_givers`
  ADD COLUMN `abysinia_account` VARCHAR(100) NULL DEFAULT NULL AFTER `cbe_account`;

ALTER TABLE `sg_payout_change_requests`
  ADD COLUMN `old_cbe_account` VARCHAR(100) NULL DEFAULT NULL AFTER `old_telebirr_number`;

ALTER TABLE `sg_payout_change_requests`
  ADD COLUMN `new_cbe_account` VARCHAR(100) NULL DEFAULT NULL AFTER `new_telebirr_number`;

ALTER TABLE `sg_payout_change_requests`
  ADD COLUMN `old_abysinia_account` VARCHAR(100) NULL DEFAULT NULL AFTER `old_cbe_account`;

ALTER TABLE `sg_payout_change_requests`
  ADD COLUMN `new_abysinia_account` VARCHAR(100) NULL DEFAULT NULL AFTER `new_cbe_account`;

CREATE TABLE IF NOT EXISTS `payment_method_logos` (
  `method_key` VARCHAR(30) PRIMARY KEY,
  `logo_url` VARCHAR(512) NOT NULL,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` INT NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
