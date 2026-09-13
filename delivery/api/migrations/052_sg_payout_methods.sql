-- ============================================================
-- Real, distinct payout methods for Service Givers.
--
-- Previously there was only a single generic `bank_account` column
-- with no way to say WHICH bank it belonged to, and no way to register
-- a Telebirr payout number at all (that tile was a static "Coming
-- soon" placeholder in the Settings UI). This adds:
--   - payout_bank: which bank bank_account actually belongs to
--     (mirrors the same 'abysinia'/'cbe' choices customers already see
--     at checkout, so the vocabulary is consistent across the app)
--   - telebirr_number: a real, independent payout channel
--
-- Both stay nullable/empty by default - a business only fills in
-- whichever payout method(s) it actually uses, exactly like
-- bank_account already worked before this.
--
-- NOTE ON FORMATTING: every comment on its own line, every
-- statement-terminating `;` last on its line, so api/migrate.php's naive
-- line-based statement splitter parses this correctly.

ALTER TABLE `service_givers`
  ADD COLUMN `payout_bank` ENUM('abysinia','cbe') NULL DEFAULT NULL AFTER `bank_account`;

ALTER TABLE `service_givers`
  ADD COLUMN `telebirr_number` VARCHAR(20) NULL DEFAULT NULL AFTER `payout_bank`;
