-- Migration 009: driver announcements (unifies "Pro news" / "Support
-- notifications" / "Partner messages" - these were three separate
-- toast-only stubs in driver.html but are really the same underlying
-- pattern: admin posts a message, drivers/couriers read it) and referral
-- tracking columns on couriers (shared by both Ride drivers and Delivery
-- couriers).
--
-- NOTE ON FORMATTING: same convention as 007/008 - every comment on its
-- own line, every statement-terminating `;` last on its line, so
-- api/migrate.php's naive line-based statement splitter parses this
-- correctly.

CREATE TABLE IF NOT EXISTS `driver_announcements` (
  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
  `category`   VARCHAR(20)  NOT NULL DEFAULT 'news',
  `title`      VARCHAR(200) NOT NULL,
  `body`       TEXT         NOT NULL,
  `active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `created_by` INT(11)      DEFAULT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_category` (`category`),
  KEY `idx_active`   (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `driver_announcement_reads` (
  `id`               INT(11)  NOT NULL AUTO_INCREMENT,
  `announcement_id`  INT(11)  NOT NULL,
  `user_id`          INT(11)  NOT NULL,
  `read_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_announcement_user` (`announcement_id`, `user_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- referral_code / referred_by on couriers (shared table for both Ride
-- drivers and Delivery couriers - see api/seeds/complete_migration.sql).
-- Added the safe/idempotent way via information_schema, matching 008,
-- since this DB may already have this table with real rows in it.

SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'couriers'
      AND COLUMN_NAME = 'referral_code'
);

SET @ddl = IF(
    @col_exists = 0,
    'ALTER TABLE `couriers` ADD COLUMN `referral_code` VARCHAR(12) DEFAULT NULL AFTER `courier_code`, ADD COLUMN `referred_by` VARCHAR(12) DEFAULT NULL AFTER `referral_code`',
    'SELECT 1'
);

PREPARE stmt FROM @ddl;

EXECUTE stmt;

DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'couriers'
      AND INDEX_NAME = 'uq_referral_code'
);

SET @ddl2 = IF(
    @idx_exists = 0,
    'ALTER TABLE `couriers` ADD UNIQUE KEY `uq_referral_code` (`referral_code`)',
    'SELECT 1'
);

PREPARE stmt2 FROM @ddl2;

EXECUTE stmt2;

DEALLOCATE PREPARE stmt2;

-- Seed a first welcome announcement so the list isn't empty on launch.
-- Guarded by a row-count check (not ON DUPLICATE KEY UPDATE - there's no
-- unique constraint on title to trigger it, so that would silently insert
-- a duplicate row every time this migration accidentally re-runs).

SET @already_seeded = (SELECT COUNT(*) FROM `driver_announcements`);

SET @seed_sql = IF(
    @already_seeded = 0,
    'INSERT INTO `driver_announcements` (category, title, body, active) VALUES (''partner'', ''Welcome to ETEL Ride & Delivery'', ''Thank you for joining the ETEL Group partner network. Check this tab for updates, tips, and important announcements from our team.'', 1)',
    'SELECT 1'
);

PREPARE stmt3 FROM @seed_sql;

EXECUTE stmt3;

DEALLOCATE PREPARE stmt3;
