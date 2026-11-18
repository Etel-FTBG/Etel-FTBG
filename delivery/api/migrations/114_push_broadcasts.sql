-- Migration 114: messages the owner sends to phones from Admin (ETEL app > Send a message) and what happened to each.
-- One row per message: who it was for, how many people had a phone that could receive it, how many it reached, how many failed.
-- Additive and idempotent: one CREATE TABLE IF NOT EXISTS, no ALTER, no seed rows.

CREATE TABLE IF NOT EXISTS `push_broadcasts` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `title`       VARCHAR(80)  NOT NULL DEFAULT '',
  `body`        VARCHAR(300) NOT NULL DEFAULT '',
  `audience`    VARCHAR(16)  NOT NULL DEFAULT 'all',
  `target_user_id` INT(11)   NOT NULL DEFAULT 0,
  `recipients`  INT(11)      NOT NULL DEFAULT 0,
  `reached`     INT(11)      NOT NULL DEFAULT 0,
  `failed`      INT(11)      NOT NULL DEFAULT 0,
  `status`      VARCHAR(10)  NOT NULL DEFAULT 'sending',
  `created_by`  INT(11)      NOT NULL DEFAULT 0,
  `created_at`  DATETIME     DEFAULT NULL,
  `sent_at`     DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
