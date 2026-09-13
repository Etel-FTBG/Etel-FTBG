-- Migration 003: Order pickup/delivery confirmations
-- Adds the order_confirmations table for OTP-style confirmations.
-- Source: legacy api/confirmation_schema.sql

CREATE TABLE IF NOT EXISTS `order_confirmations` (
    `id`                INT(11)       NOT NULL AUTO_INCREMENT,
    `order_id`          INT(11)       NOT NULL,
    `type`              ENUM('pickup','delivery') NOT NULL,
    `status`            ENUM('pending','confirmed','rejected') DEFAULT 'pending',
    `requested_by`      INT(11)       NOT NULL COMMENT 'courier user_id',
    `requested_at`      DATETIME      DEFAULT CURRENT_TIMESTAMP,
    `responded_by`      INT(11)       DEFAULT NULL COMMENT 'sg or customer user_id',
    `responded_at`      DATETIME      DEFAULT NULL,
    `payment_confirmed` TINYINT(1)    DEFAULT 0,
    `notes`             VARCHAR(500)  DEFAULT '',
    PRIMARY KEY (`id`),
    INDEX `idx_order_type`  (`order_id`, `type`),
    INDEX `idx_status_time` (`status`,   `requested_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;