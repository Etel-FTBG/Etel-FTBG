-- Migration 092: browser error reports.
--
-- js/etel-beacon.js (rider and driver apps) posts uncaught errors and server-error answers to api/client-error.php; each
-- distinct error is one row with a counter. The table is also created on the first report, so this migration only makes
-- it show up in Migrations. Admins read it in Rides > System health.
--
-- NOTE ON FORMATTING: every comment on its own line, every
-- statement-terminating `;` last on its line, so api/migrate.php's naive
-- line-based statement splitter parses this correctly.

CREATE TABLE IF NOT EXISTS `client_errors` (
  `id` CHAR(32) NOT NULL,
  `app` VARCHAR(20) NOT NULL DEFAULT '',
  `kind` VARCHAR(12) NOT NULL DEFAULT '',
  `page` VARCHAR(120) NOT NULL DEFAULT '',
  `message` VARCHAR(300) NOT NULL DEFAULT '',
  `source` VARCHAR(160) NOT NULL DEFAULT '',
  `line_no` INT NOT NULL DEFAULT 0,
  `stack` VARCHAR(1200) NOT NULL DEFAULT '',
  `rid` VARCHAR(16) NOT NULL DEFAULT '',
  `ua` VARCHAR(200) NOT NULL DEFAULT '',
  `user_id` INT NOT NULL DEFAULT 0,
  `hits` INT NOT NULL DEFAULT 1,
  `first_seen` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_client_errors_seen` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
