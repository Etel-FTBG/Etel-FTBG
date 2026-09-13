-- Migration 023: backs the customer Profile menu items that were pure
-- decoration with no handler at all (My Favorites, Saved Addresses,
-- Referral & Rewards, Settings & Privacy) plus the Messages nav tab,
-- which had no view-section in customer.html and rendered a black
-- screen. Also adds the per-user Etel Pay access override table so an
-- admin can grant selected members access ahead of the global
-- etelpay_live flag (see AdminController.php admin_toggle_feature).
--
-- NOTE ON FORMATTING: same convention as 007/008/009 - every comment on
-- its own line, every statement-terminating `;` last on its line, so
-- api/migrate.php's naive line-based statement splitter parses this
-- correctly.

CREATE TABLE IF NOT EXISTS `customer_favorites` (
  `id`                 INT(11)      NOT NULL AUTO_INCREMENT,
  `user_id`            INT(11)      NOT NULL,
  `service_giver_id`   INT(11)      NOT NULL,
  `created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_sg` (`user_id`, `service_giver_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `customer_addresses` (
  `id`            INT(11)      NOT NULL AUTO_INCREMENT,
  `user_id`       INT(11)      NOT NULL,
  `label`         VARCHAR(60)  NOT NULL DEFAULT 'Home',
  `address_line`  VARCHAR(255) NOT NULL,
  `zone`          VARCHAR(80)  DEFAULT NULL,
  `lat`           DECIMAL(10,7) DEFAULT NULL,
  `lng`           DECIMAL(10,7) DEFAULT NULL,
  `is_default`    TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Customer-facing Messages tab: a real inbox rather than a mock. Two
-- kinds of rows share this table - 'system' rows are inserted by the
-- backend (e.g. a welcome message), 'customer' rows are sent by the
-- member via the composer. Order-status updates are surfaced in the
-- same list by the read endpoint joining live `orders` data at read
-- time (see customer_get_messages) rather than being duplicated into
-- this table on every status change, so there's no new write path to
-- wire into the existing courier/service-giver status-update code.

CREATE TABLE IF NOT EXISTS `customer_messages` (
  `id`         INT(11)                          NOT NULL AUTO_INCREMENT,
  `user_id`    INT(11)                          NOT NULL,
  `sender`     ENUM('customer','system','support') NOT NULL DEFAULT 'customer',
  `subject`    VARCHAR(150)                     DEFAULT NULL,
  `body`       TEXT                             NOT NULL,
  `is_read`    TINYINT(1)                       NOT NULL DEFAULT 0,
  `created_at` DATETIME                         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_created` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `customer_referral_rewards` (
  `id`                 INT(11)      NOT NULL AUTO_INCREMENT,
  `referrer_user_id`   INT(11)      NOT NULL,
  `referred_user_id`   INT(11)      NOT NULL,
  `reward_etb`         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `status`             VARCHAR(20)  NOT NULL DEFAULT 'pending',
  `created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_referred` (`referred_user_id`),
  KEY `idx_referrer` (`referrer_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-user Etel Pay access override. Lets an admin flip wallet access on
-- for specific members (e.g. internal testers, premium members) before
-- the global `etelpay_live` flag in etel-features.json goes live for
-- everyone, or explicitly deny it for someone after it's globally live.
-- `allowed`=1 forces it on for that user, `allowed`=0 forces it off,
-- absence of a row means "use the global flag" (see get_feature_flags).

CREATE TABLE IF NOT EXISTS `etelpay_user_overrides` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `user_id`     INT(11)      NOT NULL,
  `allowed`     TINYINT(1)   NOT NULL DEFAULT 1,
  `note`        VARCHAR(200) DEFAULT NULL,
  `updated_by`  VARCHAR(100) DEFAULT NULL,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- referral_code / referred_by_code on users (shared by every role - the
-- existing referral_code column on `couriers` from migration 009 is a
-- different program for driver referrals, so this is deliberately a
-- separate column rather than reusing that one). Added the safe,
-- idempotent way via information_schema, matching 008/009, since this
-- table already has real rows.

SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'referral_code'
);

SET @ddl = IF(
    @col_exists = 0,
    'ALTER TABLE `users` ADD COLUMN `referral_code` VARCHAR(12) DEFAULT NULL, ADD COLUMN `referred_by_code` VARCHAR(12) DEFAULT NULL',
    'SELECT 1'
);

PREPARE stmt FROM @ddl;

EXECUTE stmt;

DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND INDEX_NAME = 'uq_users_referral_code'
);

SET @ddl2 = IF(
    @idx_exists = 0,
    'ALTER TABLE `users` ADD UNIQUE KEY `uq_users_referral_code` (`referral_code`)',
    'SELECT 1'
);

PREPARE stmt2 FROM @ddl2;

EXECUTE stmt2;

DEALLOCATE PREPARE stmt2;

-- Settings & Privacy: a small JSON blob per user (notification
-- preferences, language) rather than a wide table of individual
-- boolean columns - this screen is expected to grow more toggles over
-- time and a JSON column avoids a migration per new toggle.

SET @col_exists2 = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'settings_json'
);

SET @ddl3 = IF(
    @col_exists2 = 0,
    'ALTER TABLE `users` ADD COLUMN `settings_json` TEXT DEFAULT NULL',
    'SELECT 1'
);

PREPARE stmt3 FROM @ddl3;

EXECUTE stmt3;

DEALLOCATE PREPARE stmt3;
