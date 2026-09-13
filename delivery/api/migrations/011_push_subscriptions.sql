-- Migration 011: push_subscriptions table for real Web Push notifications
-- (drivers/couriers get woken up by the OS/browser even when the app
-- isn't open, unlike the in-page ringtone which only works while the
-- app is actually in the foreground).
--
-- NOTE ON FORMATTING: same convention as prior migrations - every
-- comment on its own line, every statement-terminating `;` last on its
-- line, so api/migrate.php's naive line-based statement splitter parses
-- this correctly.

CREATE TABLE IF NOT EXISTS `push_subscriptions` (
  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
  `user_id`    INT(11)      NOT NULL,
  `endpoint`   TEXT         NOT NULL,
  `endpoint_hash` CHAR(64)  NOT NULL,
  `p256dh`     VARCHAR(255) NOT NULL,
  `auth`       VARCHAR(255) NOT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_endpoint` (`user_id`, `endpoint_hash`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
