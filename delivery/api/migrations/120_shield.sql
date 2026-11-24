-- Migration 120: Etel Shield (api/lib/shield*.php) - the security log and the list of blocked connections.
--   shield_events: one row per thing the shield noticed or stopped (rate limit, attack pattern, scanner, ban). Rows older than the keep-days setting are removed.
--   shield_bans:   a connection (IP address) that is blocked until a time. The shield keeps a copy in a small file so normal requests never touch the database.
-- The server also creates both tables by itself on first use, so running this file is optional. Additive and idempotent.
-- (One statement per block, `;` last on its line: api/migrate.php splits statements line by line.)

CREATE TABLE IF NOT EXISTS `shield_events` (
  `id`      BIGINT(20)   NOT NULL AUTO_INCREMENT,
  `at`      DATETIME     NOT NULL,
  `ip`      VARCHAR(45)  NOT NULL DEFAULT '',
  `rule`    VARCHAR(40)  NOT NULL DEFAULT '',
  `mode`    VARCHAR(10)  NOT NULL DEFAULT '',
  `action`  VARCHAR(60)  NOT NULL DEFAULT '',
  `detail`  VARCHAR(255) NOT NULL DEFAULT '',
  `ua`      VARCHAR(120) NOT NULL DEFAULT '',
  `country` VARCHAR(2)   NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_at` (`at`),
  KEY `idx_ip` (`ip`, `at`),
  KEY `idx_rule` (`rule`, `at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `shield_bans` (
  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
  `ip`         VARCHAR(45)  NOT NULL,
  `reason`     VARCHAR(120) NOT NULL DEFAULT '',
  `until_at`   DATETIME     NOT NULL,
  `created_at` DATETIME     NOT NULL,
  `created_by` VARCHAR(40)  NOT NULL DEFAULT 'shield',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ip` (`ip`),
  KEY `idx_until` (`until_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
