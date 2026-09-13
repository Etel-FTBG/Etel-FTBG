-- ============================================================
-- Founder capital, tracked separately from investor equity.
--
-- equity_investors.amount_invested was being summed into the
-- dashboard's "Total Raised" figure with no distinction for WHOSE
-- money it was - a founder contribution mistakenly entered as an
-- "investor" row got counted as if a third party had bought into the
-- 20% pool. This column gives founder capital its own honest home,
-- outside the investor cap-table math entirely (it never affects
-- price_per_share, ownership_pct, or equity_issued_pct - those stay
-- driven purely by real equity_investors rows against target_amount).
-- ============================================================

ALTER TABLE `equity_round_settings`
  ADD COLUMN `founder_capital_contributed` DECIMAL(14,2) NOT NULL DEFAULT 0.00
  AFTER `total_shares`;
