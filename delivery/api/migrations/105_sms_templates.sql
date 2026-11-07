-- Migration 105: Agents programme - the customer text messages (SMS) can be edited by an admin, in English, Amharic or both.
--
-- Additive and idempotent: one CREATE TABLE IF NOT EXISTS and INSERT IGNORE (never overwrites a value an admin changed).
-- No ALTER on any existing table, no transaction statements. Safe to run twice (phpMyAdmin or api/migrate.php). The app also creates this table by itself
-- the first time the Messages screen is saved, so running this file is optional.
-- Same formatting convention as 101: every comment on its own line, every semicolon last on its line, single quotes only.
-- One row per message kind and language variant; no row means the built-in wording is used.

CREATE TABLE IF NOT EXISTS `fa_sms_templates` (
  `id`         INT(11)       NOT NULL AUTO_INCREMENT,
  `kind`       VARCHAR(20)   NOT NULL,
  `lang`       VARCHAR(8)    NOT NULL,
  `body`       VARCHAR(1000) NOT NULL,
  `updated_by` INT(11)       NOT NULL DEFAULT 0,
  `updated_at` DATETIME      NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fa_sms_tpl` (`kind`, `lang`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `fa_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES
  ('agent_booking_sms_lang', '0', NOW());
