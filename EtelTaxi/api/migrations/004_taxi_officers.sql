-- Traffic officers verified via EtelID, same security boundary as
-- taxi_admins: signing in with EtelID only works if that EtelID's phone
-- already matches an EXISTING officer account, created deliberately by
-- a super admin (admin_register_officer) - it never lets any EtelID
-- holder become an officer on their own.
CREATE TABLE IF NOT EXISTS `taxi_officers` (
    `id`         INT(11)      NOT NULL AUTO_INCREMENT,
    `full_name`  VARCHAR(150) NOT NULL,
    `phone`      VARCHAR(20)  NOT NULL,
    `etel_id`    VARCHAR(50)  NULL,
    `created_at` DATETIME     NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_officer_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `taxi_officer_sessions` (
    `id`         INT(11)      NOT NULL AUTO_INCREMENT,
    `officer_id` INT(11)      NOT NULL,
    `token`      VARCHAR(64)  NOT NULL,
    `expires_at` DATETIME     NOT NULL,
    `created_at` DATETIME     NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_officer_token` (`token`),
    KEY `idx_officer` (`officer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
