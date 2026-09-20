-- ═══════════════════════════════════════════════════════════════════
-- MIGRATION: Add driver_vehicles (NFC tap-to-register)
-- Run this once on the existing database (e.g. via phpMyAdmin) before
-- deploying the "tap the driver's NFC card to auto-register" feature.
--
-- Remembers which plate/vehicle type belongs to a given phone number.
-- Populated the first time a controller manually registers a vehicle
-- WITH a driver phone (see includes/queue_logic.php's
-- madeyaRememberDriverVehicle()), and read by the NFC auto-register
-- endpoint (api/service_nfc_register.php) on every later tap of that
-- same driver's EtelID card - no separate admin screen needed for a
-- first version.
--
-- phone_last9 (not the raw phone) is what's matched against, since a
-- phone typed as '0912345678', '+251912345678' or '912345678' must all
-- resolve to the same driver - same convention already used by
-- includes/etelid_bridge.php's own phone matching.
-- ═══════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS driver_vehicles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    phone VARCHAR(20) NOT NULL,
    phone_last9 VARCHAR(9) NOT NULL,
    etel_id VARCHAR(30) NULL,
    plate_number VARCHAR(20) NOT NULL,
    vehicle_type_id INT UNSIGNED NULL,
    driver_name VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_phone_last9 (phone_last9),
    INDEX idx_etel_id (etel_id),
    FOREIGN KEY (vehicle_type_id) REFERENCES vehicle_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
