-- Migration 110: Agents control centre - one row per agent for the controls an admin sets by hand.
--
-- payout_hold stops this agent from being picked for a payout batch (earnings still count and stay payable).
-- daily_cap_etb is this agent's own daily earnings limit; 0 means use the programme setting (Settings > Caps).
-- note is an internal note for admins only (never shown to the agent).
-- Additive and idempotent: one CREATE TABLE IF NOT EXISTS, no ALTER, no seed rows. Safe to run twice.

CREATE TABLE IF NOT EXISTS `fa_agent_controls` (
  `agent_id`      INT(11)       NOT NULL,
  `payout_hold`   TINYINT(1)    NOT NULL DEFAULT 0,
  `hold_reason`   VARCHAR(160)  NOT NULL DEFAULT '',
  `daily_cap_etb` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `note`          VARCHAR(500)  NOT NULL DEFAULT '',
  `updated_by`    INT(11)       NOT NULL DEFAULT 0,
  `updated_at`    DATETIME      DEFAULT NULL,
  PRIMARY KEY (`agent_id`),
  KEY `idx_fa_ctl_hold` (`payout_hold`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
