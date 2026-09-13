-- Migration 010: ride issue reporting (replaces the showRideIssues()
-- "coming in Phase 2" toast stub in ride.html with a real, submittable
-- report tied to the specific ride and rider).
--
-- NOTE ON FORMATTING: same convention as 007/008/009 - every comment on
-- its own line, every statement-terminating `;` last on its line, so
-- api/migrate.php's naive line-based statement splitter parses this
-- correctly.

CREATE TABLE IF NOT EXISTS `ride_issues` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `ride_id`     INT(11)      DEFAULT NULL,
  `reporter_id` INT(11)      NOT NULL,
  `category`    VARCHAR(40)  NOT NULL,
  `details`     TEXT         DEFAULT NULL,
  `status`      VARCHAR(20)  NOT NULL DEFAULT 'open',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ride`     (`ride_id`),
  KEY `idx_reporter` (`reporter_id`),
  KEY `idx_status`   (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
