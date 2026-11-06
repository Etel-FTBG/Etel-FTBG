-- Migration 103: Etel central Support desk (one ticket inbox for every Etel app).
--
-- Additive and idempotent: CREATE TABLE IF NOT EXISTS and INSERT IGNORE only. No ALTER on any existing table,
-- no transaction statements. Safe to run twice (phpMyAdmin or api/migrate.php).
-- Same formatting convention as 101: every comment on its own line, every semicolon last on its line, single quotes only.
-- Telegram messages stay OFF until the owner switches them on in Settings (tg_enabled = 0).

CREATE TABLE IF NOT EXISTS `sup_tickets` (
  `id`                INT(11)      NOT NULL AUTO_INCREMENT,
  `ref`               VARCHAR(16)  NOT NULL DEFAULT '',
  `user_id`           INT(11)      NOT NULL,
  `app`               VARCHAR(24)  NOT NULL DEFAULT 'other',
  `category`          VARCHAR(24)  NOT NULL DEFAULT 'other',
  `subject`           VARCHAR(160) NOT NULL DEFAULT '',
  `related`           VARCHAR(60)  NOT NULL DEFAULT '',
  `status`            ENUM('open','pending','solved','closed') NOT NULL DEFAULT 'open',
  `priority`          ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  `assigned_to`       INT(11)      DEFAULT NULL,
  `last_actor`        ENUM('user','staff') NOT NULL DEFAULT 'user',
  `user_unread`       TINYINT(1)   NOT NULL DEFAULT 0,
  `staff_unread`      TINYINT(1)   NOT NULL DEFAULT 1,
  `lang`              VARCHAR(2)   NOT NULL DEFAULT 'am',
  `rating`            TINYINT(1)   DEFAULT NULL,
  `rating_note`       VARCHAR(255) NOT NULL DEFAULT '',
  `due_at`            DATETIME     DEFAULT NULL,
  `first_response_at` DATETIME     DEFAULT NULL,
  `solved_at`         DATETIME     DEFAULT NULL,
  `created_at`        DATETIME     NOT NULL,
  `updated_at`        DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sup_user` (`user_id`, `updated_at`),
  KEY `idx_sup_queue` (`status`, `priority`, `updated_at`),
  KEY `idx_sup_app` (`app`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sup_messages` (
  `id`         INT(11)     NOT NULL AUTO_INCREMENT,
  `ticket_id`  INT(11)     NOT NULL,
  `sender`     ENUM('user','staff','note','system') NOT NULL,
  `staff_id`   INT(11)     DEFAULT NULL,
  `body`       TEXT        NOT NULL,
  `created_at` DATETIME    NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sup_msg_ticket` (`ticket_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sup_canned` (
  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
  `title`      VARCHAR(80)  NOT NULL,
  `body_en`    TEXT         NOT NULL,
  `body_am`    TEXT         NOT NULL,
  `sort_order` INT(11)      NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sup_settings` (
  `setting_key`   VARCHAR(64)  NOT NULL,
  `setting_value` VARCHAR(255) NOT NULL DEFAULT '',
  `updated_by`    INT(11)      NOT NULL DEFAULT 0,
  `updated_at`    DATETIME     NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `sup_settings` (`setting_key`, `setting_value`, `updated_by`, `updated_at`) VALUES
  ('desk_enabled', '1', 0, NOW()),
  ('sla_first_hours', '8', 0, NOW()),
  ('sla_urgent_hours', '2', 0, NOW()),
  ('max_open_per_user', '5', 0, NOW()),
  ('max_new_per_hour', '5', 0, NOW()),
  ('auto_close_days', '7', 0, NOW()),
  ('csat_enabled', '1', 0, NOW()),
  ('tg_enabled', '0', 0, NOW()),
  ('tg_notify_user', '0', 0, NOW()),
  ('tg_staff_chat_id', '', 0, NOW());

INSERT INTO `sup_canned` (`title`, `body_en`, `body_am`, `sort_order`, `created_at`)
SELECT 'Looking into it', 'Thank you for contacting Etel. We are looking into this now and will update you here soon.', '', 1, NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `sup_canned`);
