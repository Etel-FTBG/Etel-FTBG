-- Migration 113: Google Play readiness.
--
-- legal_docs: the owner's own text for the Privacy policy and the Terms (one row each: 'privacy', 'terms'). With no row the built-in pages stay as they are.
-- account_deletion_requests: what the team decided on each "Delete my account" request (the request itself is a support message, customer_messages).
-- Additive and idempotent: two CREATE TABLE IF NOT EXISTS, no ALTER, no seed rows.

CREATE TABLE IF NOT EXISTS `legal_docs` (
  `doc_key`    VARCHAR(16)  NOT NULL,
  `title`      VARCHAR(80)  NOT NULL DEFAULT '',
  `body`       MEDIUMTEXT   NOT NULL,
  `updated_by` INT(11)      NOT NULL DEFAULT 0,
  `updated_at` DATETIME     DEFAULT NULL,
  PRIMARY KEY (`doc_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `account_deletion_requests` (
  `message_id` INT(11)      NOT NULL,
  `user_id`    INT(11)      NOT NULL DEFAULT 0,
  `status`     VARCHAR(12)  NOT NULL DEFAULT 'done',
  `note`       VARCHAR(200) NOT NULL DEFAULT '',
  `handled_by` INT(11)      NOT NULL DEFAULT 0,
  `handled_at` DATETIME     DEFAULT NULL,
  PRIMARY KEY (`message_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
