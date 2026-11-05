-- Migration 101: Etel Ride "Agents" referral programme (field agents).
--
-- Fully additive and idempotent: only CREATE TABLE IF NOT EXISTS and
-- INSERT IGNORE. It does NOT alter rides, users or any existing table, and it
-- contains no transaction statements (DDL cannot be rolled back in MySQL).
-- Safe to run twice, in phpMyAdmin (Import or SQL tab) or via api/migrate.php.
--
-- NOTE ON FORMATTING: same convention as 007/008/009 - every comment on its
-- own line, every statement-terminating semicolon last on its line, single
-- quotes for strings (ANSI_QUOTES safe), backticks for identifiers.

CREATE TABLE IF NOT EXISTS `fa_settings` (
  `setting_key`   VARCHAR(60)  NOT NULL,
  `setting_value` VARCHAR(255) NOT NULL DEFAULT '',
  `updated_by`    INT(11)      DEFAULT NULL,
  `updated_at`    DATETIME     DEFAULT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `fa_agents` (
  `id`               INT(11)      NOT NULL AUTO_INCREMENT,
  `user_id`          INT(11)      NOT NULL,
  `etel_id`          VARCHAR(24)  DEFAULT NULL,
  `full_name`        VARCHAR(120) NOT NULL DEFAULT '',
  `phone`            VARCHAR(25)  NOT NULL DEFAULT '',
  `venue_name`       VARCHAR(120) NOT NULL DEFAULT '',
  `venue_type`       VARCHAR(30)  NOT NULL DEFAULT 'other',
  `venue_zone`       VARCHAR(80)  NOT NULL DEFAULT '',
  `payout_phone`     VARCHAR(25)  NOT NULL DEFAULT '',
  `code`             VARCHAR(12)  DEFAULT NULL,
  `status`           ENUM('pending','approved','suspended','rejected') NOT NULL DEFAULT 'pending',
  `age_status`       ENUM('unchecked','auto_verified','manual_verified','failed') NOT NULL DEFAULT 'unchecked',
  `age_note`         VARCHAR(200) NOT NULL DEFAULT '',
  `age_checked_by`   INT(11)      DEFAULT NULL,
  `age_checked_at`   DATETIME     DEFAULT NULL,
  `tier`             ENUM('bronze','silver','gold') NOT NULL DEFAULT 'bronze',
  `tier_updated_at`  DATETIME     DEFAULT NULL,
  `risk_score`       TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
  `risk_reasons`     TEXT,
  `device_hash`      CHAR(64)     DEFAULT NULL,
  `decided_by`       INT(11)      DEFAULT NULL,
  `decided_at`       DATETIME     DEFAULT NULL,
  `decision_reason`  VARCHAR(255) NOT NULL DEFAULT '',
  `last_referral_at` DATETIME     DEFAULT NULL,
  `created_at`       DATETIME     NOT NULL,
  `updated_at`       DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fa_user` (`user_id`),
  UNIQUE KEY `uq_fa_code` (`code`),
  KEY `idx_fa_status` (`status`),
  KEY `idx_fa_payout_phone` (`payout_phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `fa_referrals` (
  `id`            INT(11)      NOT NULL AUTO_INCREMENT,
  `agent_id`      INT(11)      NOT NULL,
  `rider_user_id` INT(11)      NOT NULL,
  `device_hash`   CHAR(64)     DEFAULT NULL,
  `ip_hash`       CHAR(64)     DEFAULT NULL,
  `claimed_at`    DATETIME     NOT NULL,
  `status`        ENUM('claimed','qualified','rejected','expired') NOT NULL DEFAULT 'claimed',
  `first_ride_id` INT(11)      DEFAULT NULL,
  `first_ride_at` DATETIME     DEFAULT NULL,
  `driver_id`     INT(11)      DEFAULT NULL,
  `reject_code`   VARCHAR(40)  NOT NULL DEFAULT '',
  `risk_score`    TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
  `risk_reasons`  TEXT,
  `created_at`    DATETIME     NOT NULL,
  `updated_at`    DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fa_rider` (`rider_user_id`),
  KEY `idx_fa_ref_agent` (`agent_id`, `claimed_at`),
  KEY `idx_fa_ref_status` (`status`),
  KEY `idx_fa_ref_device` (`device_hash`),
  KEY `idx_fa_ref_driver` (`agent_id`, `driver_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `fa_earnings` (
  `id`             INT(11)      NOT NULL AUTO_INCREMENT,
  `referral_id`    INT(11)      NOT NULL,
  `agent_id`       INT(11)      NOT NULL,
  `kind`           ENUM('first_trip','repeat3','clawback') NOT NULL,
  `parent_id`      INT(11)      NOT NULL DEFAULT 0,
  `amount`         DECIMAL(10,2) NOT NULL DEFAULT 0,
  `ride_id`        INT(11)      DEFAULT NULL,
  `ride_ids`       VARCHAR(255) NOT NULL DEFAULT '',
  `status`         ENUM('pending','payable','held','paid','rejected','clawed_back') NOT NULL DEFAULT 'pending',
  `reason_code`    VARCHAR(40)  NOT NULL DEFAULT '',
  `reason_note`    VARCHAR(255) NOT NULL DEFAULT '',
  `earned_at`      DATETIME     NOT NULL,
  `payable_at`     DATETIME     DEFAULT NULL,
  `risk_score`     TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
  `risk_reasons`   TEXT,
  `decided_by`     INT(11)      DEFAULT NULL,
  `decided_at`     DATETIME     DEFAULT NULL,
  `payout_item_id` INT(11)      NOT NULL DEFAULT 0,
  `created_at`     DATETIME     NOT NULL,
  `updated_at`     DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fa_earning` (`referral_id`, `kind`, `parent_id`),
  KEY `idx_fa_earn_agent` (`agent_id`, `status`),
  KEY `idx_fa_earn_due` (`status`, `payable_at`),
  KEY `idx_fa_earn_item` (`payout_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `fa_payout_batches` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `status`      ENUM('draft','exported','paid','cancelled') NOT NULL DEFAULT 'draft',
  `item_count`  INT(11)      NOT NULL DEFAULT 0,
  `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `note`        VARCHAR(255) NOT NULL DEFAULT '',
  `created_by`  INT(11)      DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL,
  `exported_at` DATETIME     DEFAULT NULL,
  `paid_at`     DATETIME     DEFAULT NULL,
  `paid_by`     INT(11)      DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_fa_batch_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `fa_payout_items` (
  `id`           INT(11)      NOT NULL AUTO_INCREMENT,
  `batch_id`     INT(11)      NOT NULL,
  `agent_id`     INT(11)      NOT NULL,
  `amount`       DECIMAL(10,2) NOT NULL DEFAULT 0,
  `payout_phone` VARCHAR(25)  NOT NULL DEFAULT '',
  `tx_ref`       VARCHAR(60)  NOT NULL DEFAULT '',
  `status`       ENUM('pending','paid','failed') NOT NULL DEFAULT 'pending',
  `created_at`   DATETIME     NOT NULL,
  `paid_at`      DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_fa_item_batch` (`batch_id`),
  KEY `idx_fa_item_agent` (`agent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `fa_audit_log` (
  `id`         BIGINT(20)   NOT NULL AUTO_INCREMENT,
  `created_at` DATETIME     NOT NULL,
  `actor_type` VARCHAR(12)  NOT NULL DEFAULT 'system',
  `actor_id`   INT(11)      NOT NULL DEFAULT 0,
  `action`     VARCHAR(60)  NOT NULL,
  `entity`     VARCHAR(30)  NOT NULL DEFAULT '',
  `entity_id`  INT(11)      NOT NULL DEFAULT 0,
  `reason`     VARCHAR(255) NOT NULL DEFAULT '',
  `detail`     TEXT,
  `prev_hash`  CHAR(64)     NOT NULL DEFAULT '',
  `row_hash`   CHAR(64)     NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_fa_audit_entity` (`entity`, `entity_id`),
  KEY `idx_fa_audit_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `fa_notifications` (
  `id`           INT(11)      NOT NULL AUTO_INCREMENT,
  `agent_id`     INT(11)      NOT NULL,
  `kind`         VARCHAR(40)  NOT NULL,
  `params`       VARCHAR(500) NOT NULL DEFAULT '',
  `channel`      VARCHAR(12)  NOT NULL DEFAULT 'portal',
  `delivered_at` DATETIME     DEFAULT NULL,
  `read_at`      DATETIME     DEFAULT NULL,
  `created_at`   DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_fa_notif_agent` (`agent_id`, `read_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default settings. INSERT IGNORE: re-running never overwrites a value an admin has changed.
-- The programme starts DISABLED (program_enabled = 0) so deploying changes nothing until you switch it on.
INSERT IGNORE INTO `fa_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES
('program_enabled', '0', NOW()),
('bonus_first_etb', '20', NOW()),
('bonus_repeat_etb', '30', NOW()),
('repeat_trips_required', '3', NOW()),
('repeat_window_days', '30', NOW()),
('qualify_window_days', '30', NOW()),
('min_trip_km', '1', NOW()),
('min_trip_minutes', '3', NOW()),
('require_digital_payment', '0', NOW()),
('hold_days', '7', NOW()),
('hold_days_silver', '7', NOW()),
('hold_days_gold', '7', NOW()),
('clawback_window_days', '30', NOW()),
('daily_cap_etb', '100', NOW()),
('weekly_cap_etb', '400', NOW()),
('monthly_budget_etb', '0', NOW()),
('budget_alert_pct', '80', NOW()),
('min_payout_etb', '50', NOW()),
('risk_hold_threshold', '60', NOW()),
('risk_high_threshold', '80', NOW()),
('risk_w_same_device', '45', NOW()),
('risk_w_repeat_pair', '30', NOW()),
('risk_w_burst', '25', NOW()),
('risk_w_short_trip', '15', NOW()),
('risk_w_odd_hours', '10', NOW()),
('risk_w_code_reuse', '20', NOW()),
('risk_w_agent_history', '15', NOW()),
('risk_burst_count', '5', NOW()),
('risk_burst_minutes', '60', NOW()),
('risk_pair_threshold', '3', NOW()),
('risk_short_trip_km', '1.5', NOW()),
('risk_odd_hour_start', '0', NOW()),
('risk_odd_hour_end', '4', NOW());
