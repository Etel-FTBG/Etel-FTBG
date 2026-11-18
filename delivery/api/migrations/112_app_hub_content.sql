-- Migration 112: ETEL app - what the owner can change about how each app looks, and the texts of the home screen.
--
-- app_hub_content: one row per app the owner edited (names and short descriptions in English and Amharic, a small label, the order, an icon picture).
-- app_hub_settings: one row per home-screen text or contact (welcome title, tagline, support phone, Telegram, email) and the banner fields.
-- An app or text with no row keeps its built-in value. Additive and idempotent: two CREATE TABLE IF NOT EXISTS, no ALTER, no seed rows.

CREATE TABLE IF NOT EXISTS `app_hub_content` (
  `app_key`    VARCHAR(32)  NOT NULL,
  `title_en`   VARCHAR(60)  NOT NULL DEFAULT '',
  `title_am`   VARCHAR(60)  NOT NULL DEFAULT '',
  `desc_en`    VARCHAR(120) NOT NULL DEFAULT '',
  `desc_am`    VARCHAR(120) NOT NULL DEFAULT '',
  `badge`      VARCHAR(16)  NOT NULL DEFAULT '',
  `sort_order` INT(11)      NOT NULL DEFAULT 0,
  `icon_url`   VARCHAR(255) NOT NULL DEFAULT '',
  `updated_by` INT(11)      NOT NULL DEFAULT 0,
  `updated_at` DATETIME     DEFAULT NULL,
  PRIMARY KEY (`app_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `app_hub_settings` (
  `setting_key`   VARCHAR(40)  NOT NULL,
  `setting_value` VARCHAR(500) NOT NULL DEFAULT '',
  `updated_by`    INT(11)      NOT NULL DEFAULT 0,
  `updated_at`    DATETIME     DEFAULT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
