-- Migration 004: Payments, wallet, push tokens, scheduled orders, zones seed
-- Adds the payment/billing subsystem and a few support tables.
-- Source: legacy api/payments_wallet_schema.sql

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ── EXTEND ORDERS TABLE ────────────────────────────────────────
ALTER TABLE `orders`
    MODIFY COLUMN `payment_method` ENUM('cash','telebirr','abysinia','cbe','wallet') DEFAULT 'cash',
    ADD COLUMN IF NOT EXISTS `payment_status`   ENUM('unpaid','paid','refunded') DEFAULT 'unpaid',
    ADD COLUMN IF NOT EXISTS `delivery_fee`     DECIMAL(8,2)  DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS `service_charge`   DECIMAL(8,2)  DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS `subtotal`         DECIMAL(10,2) DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS `recipient_address` VARCHAR(255) DEFAULT '',
    ADD COLUMN IF NOT EXISTS `sender_name`      VARCHAR(120) DEFAULT '',
    ADD COLUMN IF NOT EXISTS `sender_phone`     VARCHAR(25)  DEFAULT '';

-- ── TELEBIRR PAYMENTS ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `telebirr_payments` (
    `id`                      INT(11)       NOT NULL AUTO_INCREMENT,
    `user_id`                 INT(11)       NOT NULL,
    `order_id`                INT(11)       DEFAULT NULL,
    `amount`                  DECIMAL(10,2) NOT NULL,
    `currency`                ENUM('ETB','USD') DEFAULT 'ETB',
    `phone`                   VARCHAR(20)   NOT NULL,
    `merchant_transaction_id` VARCHAR(100)  NOT NULL,
    `telebirr_transaction_id` VARCHAR(100)  DEFAULT '',
    `payment_reference`       VARCHAR(100)  DEFAULT '',
    `status`                  ENUM('pending','processing','completed','failed','cancelled') DEFAULT 'pending',
    `response_data`           JSON          DEFAULT NULL,
    `error_message`           TEXT          DEFAULT NULL,
    `retry_count`             INT(4)        DEFAULT 0,
    `created_at`              DATETIME      DEFAULT CURRENT_TIMESTAMP,
    `completed_at`            DATETIME      DEFAULT NULL,
    `updated_at`              DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_merchant_tx` (`merchant_transaction_id`),
    KEY `idx_user`   (`user_id`),
    KEY `idx_order`  (`order_id`),
    KEY `idx_phone`  (`phone`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── PAYMENT RETRIES ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `payment_retries` (
    `id`                  INT(11) NOT NULL AUTO_INCREMENT,
    `telebirr_payment_id` INT(11) NOT NULL,
    `attempt_number`      INT(4)  DEFAULT 1,
    `error_code`          VARCHAR(50) DEFAULT '',
    `error_message`       TEXT       DEFAULT NULL,
    `created_at`          DATETIME   DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_payment` (`telebirr_payment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── MANUAL BANK PAYMENTS (Abysinia / CBE) ─────────────────────
CREATE TABLE IF NOT EXISTS `bank_payments` (
    `id`           INT(11)       NOT NULL AUTO_INCREMENT,
    `user_id`      INT(11)       NOT NULL,
    `order_id`     INT(11)       DEFAULT NULL,
    `bank`         ENUM('abysinia','cbe','dashen','other') DEFAULT 'abysinia',
    `amount`       DECIMAL(10,2) NOT NULL,
    `reference_no` VARCHAR(100)  NOT NULL,
    `status`       ENUM('pending','verified','rejected') DEFAULT 'pending',
    `verified_by`  INT(11)       DEFAULT NULL,
    `notes`        TEXT          DEFAULT NULL,
    `created_at`   DATETIME      DEFAULT CURRENT_TIMESTAMP,
    `verified_at`  DATETIME      DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_user` (`user_id`),
    KEY `idx_order`(`order_id`),
    KEY `idx_ref`  (`reference_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── USER WALLETS ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `user_wallets` (
    `id`         INT(11)       NOT NULL AUTO_INCREMENT,
    `user_id`    INT(11)       NOT NULL,
    `balance`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `currency`   CHAR(3)       NOT NULL DEFAULT 'ETB',
    `created_at` DATETIME      DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── WALLET TRANSACTIONS ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `wallet_transactions` (
    `id`            INT(11)       NOT NULL AUTO_INCREMENT,
    `user_id`       INT(11)       NOT NULL,
    `type`          ENUM('credit','debit') NOT NULL,
    `amount`        DECIMAL(10,2) NOT NULL,
    `balance_after` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `reason`        VARCHAR(160)  DEFAULT '',
    `reference_id`  INT(11)       DEFAULT NULL,
    `created_at`    DATETIME      DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user` (`user_id`),
    KEY `idx_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── PUSH NOTIFICATION TOKENS ───────────────────────────────────
CREATE TABLE IF NOT EXISTS `push_tokens` (
    `id`         INT(11)     NOT NULL AUTO_INCREMENT,
    `user_id`    INT(11)     NOT NULL,
    `token`      TEXT        NOT NULL,
    `platform`   ENUM('web','android','ios') DEFAULT 'web',
    `created_at` DATETIME    DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── ORDER RATINGS (ensure columns exist) ──────────────────────
ALTER TABLE `order_ratings`
    ADD COLUMN IF NOT EXISTS `customer_id`    INT(11)    DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `courier_rating` TINYINT(1) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `food_rating`    TINYINT(1) DEFAULT NULL;

-- ── SCHEDULED ORDERS ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `scheduled_orders` (
    `id`             INT(11)   NOT NULL AUTO_INCREMENT,
    `customer_id`    INT(11)   NOT NULL,
    `scheduled_at`   DATETIME  NOT NULL,
    `repeat_daily`   TINYINT(1) DEFAULT 0,
    `order_template` JSON      NOT NULL,
    `status`         ENUM('active','paused','cancelled') DEFAULT 'active',
    `created_at`     DATETIME  DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_customer`  (`customer_id`),
    KEY `idx_scheduled` (`scheduled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET foreign_key_checks = 1;

-- ── SEED: Initial Hawassa delivery zones ───────────────────────
INSERT IGNORE INTO `delivery_zones` (name, base_fare, sort_order) VALUES
    ('Stadium',               45,  1),
    ('Mehal Ketema',          50,  2),
    ('Tabor',                 50,  3),
    ('Liqe Mender',           50,  4),
    ('Referral',              55,  5),
    ('Addis Ketema',          60,  6),
    ('University',            60,  7),
    ('Misrak',                65,  8),
    ('Haik Dar',              70,  9),
    ('Tulu Dimtu',            70, 10),
    ('Hawela Teklehaimanot',  75, 11),
    ('Dore Bafana',           80, 12);