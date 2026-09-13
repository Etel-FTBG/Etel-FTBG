-- Migration 001: Login rate limiting
-- Adds the login_attempts table used for brute-force protection.
-- Source: legacy api/login_attempts_schema.sql

CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id`           INT(11)      NOT NULL AUTO_INCREMENT,
    `identifier`   VARCHAR(120) NOT NULL COMMENT 'phone|IP hash',
    `attempted_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_identifier_time` (`identifier`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;