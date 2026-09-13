-- ============================================================
-- Investor EtelID login access (upgrade from link-only access)
-- ============================================================

-- Ties an equity_investors row to a real users.id, so that person can
-- open their investor view any time just by logging into whichever
-- app they already use (driver, courier, service-giver, customer)
-- with their normal EtelID - no separate link to keep track of.
-- Nullable and admin-set only: sharing a phone number with an
-- investor row is not enough on its own to prove someone is that
-- investor, so linking is always a deliberate action in the Equity
-- tab, never inferred automatically from a phone match.
ALTER TABLE `equity_investors`
  ADD COLUMN `user_id` INT(11) NULL AFTER `id`,
  ADD KEY `idx_equity_investors_user` (`user_id`);
