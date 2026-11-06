-- Migration 102: Agents programme phase 4 - weekly digests and the new tier / insight / nudge settings.
--
-- Additive and idempotent: one CREATE TABLE IF NOT EXISTS and INSERT IGNORE (never overwrites a value an admin changed).
-- No ALTER on any existing table, no transaction statements. Safe to run twice (phpMyAdmin or api/migrate.php).
-- Same formatting convention as 101: every comment on its own line, every semicolon last on its line, single quotes only.

CREATE TABLE IF NOT EXISTS `fa_digests` (
  `id`         INT(11)  NOT NULL AUTO_INCREMENT,
  `week_start` DATE     NOT NULL,
  `payload`    MEDIUMTEXT,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fa_digest_week` (`week_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `fa_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES
('tier_window_days', '90', NOW()),
('tier_silver_min_riders', '5', NOW()),
('tier_silver_min_repeat_pct', '20', NOW()),
('tier_gold_min_riders', '15', NOW()),
('tier_gold_min_repeat_pct', '35', NOW()),
('tier_max_reject_pct', '20', NOW()),
('tier_max_risk', '40', NOW()),
('dormant_days', '14', NOW()),
('nudge_cooldown_days', '7', NOW()),
('cap_alert_pct', '80', NOW()),
('risk_w_shared_identity', '25', NOW()),
('digest_enabled', '1', NOW()),
('ai_summary_enabled', '0', NOW());
