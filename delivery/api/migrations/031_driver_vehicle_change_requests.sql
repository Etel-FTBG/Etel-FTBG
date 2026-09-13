-- Migration 031: adds driver_vehicle_change_requests - vehicle detail
-- edits (type, make, color, year, plate) by an already-onboarded driver
-- now go into this table as a pending request instead of writing
-- directly to ride_drivers. Nothing map-facing, ride-facing, or
-- profile-facing changes until an admin explicitly approves the
-- request - a driver can no longer silently swap what vehicle they
-- claim to be driving.
--
-- Deliberately scoped to already-onboarded drivers only: a brand-new
-- driver's FIRST-ever vehicle declaration during onboarding still
-- writes directly (see driver_update_profile) - there's nothing to
-- "change" yet, and gating initial onboarding behind an approval step
-- would block a driver from ever going online in the first place.
--
-- One row per pending request; a driver can only have one open request
-- at a time (enforced in the controller, not a DB constraint, since
-- MySQL can't easily express "unique while status=pending" without a
-- generated column).

CREATE TABLE IF NOT EXISTS `driver_vehicle_change_requests` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `driver_user_id` INT UNSIGNED NOT NULL,
    `requested_vehicle_type` VARCHAR(50) NOT NULL,
    `requested_vehicle_make` VARCHAR(100) NOT NULL DEFAULT '',
    `requested_vehicle_color` VARCHAR(50) NOT NULL DEFAULT '',
    `requested_vehicle_year` VARCHAR(10) NOT NULL DEFAULT '',
    `requested_vehicle_plate` VARCHAR(50) NOT NULL DEFAULT '',
    `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    `reject_reason` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `reviewed_at` DATETIME NULL,
    `reviewed_by_user_id` INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    KEY `idx_driver_status` (`driver_user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
