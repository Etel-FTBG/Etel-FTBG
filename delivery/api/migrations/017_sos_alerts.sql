-- Migration 017: SOS / panic alerts for drivers and riders.
--
-- A driver or rider on an active trip can tap SOS to immediately notify
-- admin with their role, last known location, and (if available) the
-- ride they are on. Admin sees these as a real-time "intruder alert"
-- style banner in admin.html (js/admin-sos.js polls admin_get_sos_alerts).
--
-- NOTE ON FORMATTING: same convention as prior migrations - every comment
-- on its own line, every statement-terminating `;` last on its line, so
-- api/migrate.php's naive line-based statement splitter parses this
-- correctly.

CREATE TABLE IF NOT EXISTS `sos_alerts` (
  `id`          INT(11)       NOT NULL AUTO_INCREMENT,
  `ride_id`     INT(11)       DEFAULT NULL,
  `reporter_id` INT(11)       NOT NULL,
  `role`        VARCHAR(20)   NOT NULL DEFAULT 'rider',
  `lat`         DECIMAL(10,7) DEFAULT NULL,
  `lng`         DECIMAL(10,7) DEFAULT NULL,
  `message`     VARCHAR(500)  DEFAULT NULL,
  `status`      VARCHAR(20)   NOT NULL DEFAULT 'open',
  `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` DATETIME      DEFAULT NULL,
  `resolved_by` INT(11)       DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ride`     (`ride_id`),
  KEY `idx_reporter` (`reporter_id`),
  KEY `idx_status`   (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
