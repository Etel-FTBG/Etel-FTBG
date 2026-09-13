-- Migration 005: password_resets (OTP reset table)
-- Adds the password_resets table used by r3.php for OTP-based password reset.
-- NOTE: this table was being referenced by code but was never defined in any
-- SQL file. Adding it here to fill that gap so fresh installs work out of the box.

CREATE TABLE IF NOT EXISTS `password_resets` (
    `id`         INT(11)       NOT NULL AUTO_INCREMENT,
    `phone`      VARCHAR(25)   NOT NULL,
    `otp_hash`   VARCHAR(255)  NOT NULL,
    `attempts`   INT(4)        NOT NULL DEFAULT 0,
    `used`       TINYINT(1)    NOT NULL DEFAULT 0,
    `expires_at` DATETIME      NOT NULL,
    `created_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_phone_time` (`phone`, `created_at`),
    KEY `idx_active`     (`used`,   `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;