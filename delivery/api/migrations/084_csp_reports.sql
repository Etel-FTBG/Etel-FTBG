-- Migration 084: Content-Security-Policy violation reports (api/csp-report.php). One row per distinct violation with a counter.
-- The endpoint also creates the table on first use.
CREATE TABLE IF NOT EXISTS `csp_reports` (
  `id` CHAR(32) NOT NULL,
  `page` VARCHAR(200) NOT NULL DEFAULT '',
  `directive` VARCHAR(60) NOT NULL DEFAULT '',
  `blocked` VARCHAR(200) NOT NULL DEFAULT '',
  `source` VARCHAR(200) NOT NULL DEFAULT '',
  `line_no` INT NOT NULL DEFAULT 0,
  `sample` VARCHAR(120) NOT NULL DEFAULT '',
  `hits` INT NOT NULL DEFAULT 1,
  `first_seen` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
