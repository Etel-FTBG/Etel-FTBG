-- ============================================================
-- ETEL TAXI - Association permit + monthly dues system  v1.0
-- Digitizes the paper receipt book + placard system shown in the
-- association's real records: a driver registers with an association,
-- gets assigned a route/station, and owes a monthly due (default
-- ETB 100). This replaces the paper receipt as proof of payment - a
-- traffic officer can look up a plate/driver's current status instead
-- of the driver having to carry and produce the paper slip.
-- ============================================================

-- One row per association (e.g. "Batele Taxi 1" serving the
-- "Awtee - Piassa" route, per the real placard). A city can have many
-- associations, each with its own route and its own due amount.
CREATE TABLE IF NOT EXISTS `taxi_associations` (
    `id`                 INT(11)      NOT NULL AUTO_INCREMENT,
    `name_am`            VARCHAR(150) NOT NULL,
    `name_en`            VARCHAR(150) NULL,
    `route_am`           VARCHAR(200) NULL,
    `route_en`           VARCHAR(200) NULL,
    `monthly_due_amount` DECIMAL(10,2) NOT NULL DEFAULT 100.00,
    `penalty_amount`     DECIMAL(10,2) NOT NULL DEFAULT 100.00,
    `grace_period_days`  INT(11)      NOT NULL DEFAULT 5,
    `active`             TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`         DATETIME     NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per driver. etel_id links to a real EtelID account if the
-- driver has one (recognized automatically via the shared etel_sso
-- session, same bridge Delivery/Ride/EtelAI already use); pin_hash lets
-- a driver without EtelID still register and log in with just a phone
-- number and a PIN, since not every driver will have signed up for
-- EtelID yet.
CREATE TABLE IF NOT EXISTS `taxi_drivers` (
    `id`              INT(11)      NOT NULL AUTO_INCREMENT,
    `association_id`  INT(11)      NOT NULL,
    `full_name`       VARCHAR(150) NOT NULL,
    `phone`           VARCHAR(20)  NOT NULL,
    `etel_id`         VARCHAR(50)  NULL,
    `pin_hash`        VARCHAR(255) NULL,
    `vehicle_plate`   VARCHAR(30)  NOT NULL,
    `vehicle_type`    VARCHAR(30)  NULL,
    `fcm_token`       VARCHAR(255) NULL,
    `status`          ENUM('active','suspended') NOT NULL DEFAULT 'active',
    `joined_at`       DATETIME     NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_phone` (`phone`),
    KEY `idx_association` (`association_id`),
    KEY `idx_plate` (`vehicle_plate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per driver per month owed. period_month is always the 1st of
-- the month being paid FOR (e.g. 2026-08-01 means "August's due").
-- status starts 'pending_verification' for bank/Telebirr reference
-- submissions (a human at the association confirms the money actually
-- arrived before it counts as paid) - this mirrors Delivery's own
-- bank-transfer checkout flow, which has worked well there.
-- payment_method 'etelpay_wallet' is a placeholder until EtelPay's own
-- codebase is available to integrate against for real - see
-- api/lib/taxi_payments.php for exactly where that plugs in.
CREATE TABLE IF NOT EXISTS `taxi_dues_payments` (
    `id`               INT(11)      NOT NULL AUTO_INCREMENT,
    `driver_id`        INT(11)      NOT NULL,
    `period_month`     DATE         NOT NULL,
    `amount`           DECIMAL(10,2) NOT NULL,
    `penalty_amount`   DECIMAL(10,2) NOT NULL DEFAULT 0,
    `payment_method`   ENUM('etelpay_wallet','telebirr','cbe','abysinia','cash') NOT NULL,
    `reference_number` VARCHAR(100) NULL,
    `status`           ENUM('pending_verification','verified','rejected') NOT NULL DEFAULT 'pending_verification',
    `submitted_at`     DATETIME     NOT NULL,
    `verified_by`      INT(11)      NULL,
    `verified_at`      DATETIME     NULL,
    `notes`            VARCHAR(255) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_driver_period` (`driver_id`, `period_month`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Association staff / admins who can verify payments and manage
-- drivers. association_id NULL = oversees every association (a
-- super-admin, e.g. Dagi); set = scoped to just that one association's
-- staff.
CREATE TABLE IF NOT EXISTS `taxi_admins` (
    `id`              INT(11)      NOT NULL AUTO_INCREMENT,
    `association_id`  INT(11)      NULL,
    `full_name`       VARCHAR(150) NOT NULL,
    `phone`           VARCHAR(20)  NOT NULL,
    `pin_hash`        VARCHAR(255) NOT NULL,
    `role`            ENUM('super_admin','staff') NOT NULL DEFAULT 'staff',
    `created_at`      DATETIME     NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_admin_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Driver sessions for phone+PIN login (drivers without EtelID yet).
-- Same DB-backed token pattern as EtelID's own Etelid_sessions table -
-- proven, simple, no separate signing secret to manage.
CREATE TABLE IF NOT EXISTS `taxi_sessions` (
    `id`         INT(11)      NOT NULL AUTO_INCREMENT,
    `driver_id`  INT(11)      NOT NULL,
    `token`      VARCHAR(64)  NOT NULL,
    `expires_at` DATETIME     NOT NULL,
    `created_at` DATETIME     NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_token` (`token`),
    KEY `idx_driver` (`driver_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Admin sessions - separate from driver sessions so an admin token can
-- never be confused for a driver token.
CREATE TABLE IF NOT EXISTS `taxi_admin_sessions` (
    `id`         INT(11)      NOT NULL AUTO_INCREMENT,
    `admin_id`   INT(11)      NOT NULL,
    `token`      VARCHAR(64)  NOT NULL,
    `expires_at` DATETIME     NOT NULL,
    `created_at` DATETIME     NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_admin_token` (`token`),
    KEY `idx_admin` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Same admin-editable pattern as Delivery's bank_accounts table (see
-- that project's migration 014) - deliberately no hardcoded account
-- numbers anywhere in code, so a wrong/placeholder number can never
-- ship the way it briefly did there.
CREATE TABLE IF NOT EXISTS `taxi_bank_accounts` (
    `id`             INT(11)      NOT NULL AUTO_INCREMENT,
    `bank_key`       VARCHAR(20)  NOT NULL,
    `display_name`   VARCHAR(60)  NOT NULL,
    `account_name`   VARCHAR(120) NOT NULL,
    `account_number` VARCHAR(40)  NOT NULL DEFAULT '',
    `active`         TINYINT(1)   NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_bank_key` (`bank_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `taxi_bank_accounts` (bank_key, display_name, account_name, account_number, active) VALUES
    ('telebirr',  'Telebirr',       '', '', 0),
    ('cbe',       'CBE Transfer',   '', '', 0),
    ('abysinia',  'Abysinia Bank',  '', '', 0)
ON DUPLICATE KEY UPDATE bank_key = bank_key;

-- Avoids sending the same "due soon" / "grace period" / "penalty
-- applied" push notification to the same driver more than once per
-- period.
CREATE TABLE IF NOT EXISTS `taxi_notifications_log` (
    `id`          INT(11)     NOT NULL AUTO_INCREMENT,
    `driver_id`   INT(11)     NOT NULL,
    `period_month` DATE       NOT NULL,
    `notif_type`  ENUM('due_soon','grace_period','penalty_applied') NOT NULL,
    `sent_at`     DATETIME    NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_driver_period_type` (`driver_id`, `period_month`, `notif_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One example association + route, matching the real placard shown -
-- delete or edit via the admin panel once real associations are added.
-- INSERT...SELECT...WHERE NOT EXISTS keeps this idempotent without
-- needing a unique constraint on name_am (associations could
-- legitimately share a name in different towns later).
INSERT INTO `taxi_associations` (name_am, name_en, route_am, route_en, monthly_due_amount, penalty_amount, grace_period_days, active, created_at)
SELECT 'ባጠሌ ታክሲ 1', 'Batele Taxi 1', 'ከአኦተ ፒያሳ', 'Awtee - Piassa', 100.00, 100.00, 5, 1, NOW()
WHERE NOT EXISTS (SELECT 1 FROM `taxi_associations` WHERE name_am = 'ባጠሌ ታክሲ 1');
