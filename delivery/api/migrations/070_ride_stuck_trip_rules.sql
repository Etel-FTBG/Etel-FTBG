-- Migration 070: settings and state for the stuck-trip sweeper and the
-- billable waiting-time cap (lib/ride_sweeper.php, lib/ride_fare.php).
-- All values are minutes; 0 turns a rule off. Safe to re-run.
--
--   ride_wait_cap_minutes        most minutes of waiting ever billed on one ride
--   ride_auto_complete_minutes   arrived + cash rides are completed after this
--   ride_idle_end_minutes        in-progress rides with no movement are ended after this

CREATE TABLE IF NOT EXISTS `pricing_rules` (
    `id`          INT(11)      NOT NULL AUTO_INCREMENT,
    `rule_key`    VARCHAR(60)  NOT NULL,
    `rule_value`  DECIMAL(8,2) NOT NULL,
    `description` VARCHAR(160) DEFAULT '',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_key` (`rule_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `pricing_rules` (rule_key, rule_value, description) VALUES
    ('ride_wait_cap_minutes',      30.00,  'Most minutes of waiting billed on one ride (0 = no cap)'),
    ('ride_auto_complete_minutes', 30.00,  'Arrived cash rides auto-complete after this many minutes (0 = off)'),
    ('ride_idle_end_minutes',      180.00, 'In-progress rides with no movement are auto-ended after this many minutes (0 = off)')
ON DUPLICATE KEY UPDATE
    rule_key = VALUES(rule_key);

CREATE TABLE IF NOT EXISTS `ride_sweeper_state` (
    `id`       TINYINT  NOT NULL PRIMARY KEY,
    `last_run` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `ride_sweeper_state` (id, last_run) VALUES (1, '2000-01-01 00:00:00');
