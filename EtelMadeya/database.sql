-- ═══════════════════════════════════════════════════════════════════
-- ETEL MADEYA GAS QUEUE - DATABASE SCHEMA
-- ═══════════════════════════════════════════════════════════════════

CREATE DATABASE IF NOT EXISTS `Etel-Madeya` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `Etel-Madeya`;

-- ─── CONTROLLERS TABLE ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS controllers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    station_name VARCHAR(120) NOT NULL DEFAULT 'Etel Madeya Gas Station',
    station_address VARCHAR(255),
    station_lat DECIMAL(10,7) DEFAULT 0,
    station_lng DECIMAL(10,7) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    odd_even_rule ENUM('all','odd','even') DEFAULT 'all',
    fuel_allocated DECIMAL(10,2) DEFAULT 0,
    fuel_used DECIMAL(10,2) DEFAULT 0,
    liters_allocated DECIMAL(10,2) DEFAULT 0,
    liters_used DECIMAL(10,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    INDEX idx_username (username),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── VEHICLE TYPES TABLE ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS vehicle_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    name_am VARCHAR(50),
    icon VARCHAR(10),
    sort_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_active (is_active),
    INDEX idx_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── CONTROLLER VEHICLE TYPES (per-station assignment) ──────────────
-- Which vehicle types a station is allowed to register/serve today.
-- No rows for a controller_id = unrestricted (all active types allowed) -
-- this is the backward-compatible default for every controller that
-- existed before this table, so nothing breaks until an admin actively
-- assigns a restricted set via the Admin Panel.
CREATE TABLE IF NOT EXISTS controller_vehicle_types (
    controller_id INT UNSIGNED NOT NULL,
    vehicle_type_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (controller_id, vehicle_type_id),
    FOREIGN KEY (controller_id) REFERENCES controllers(id) ON DELETE CASCADE,
    FOREIGN KEY (vehicle_type_id) REFERENCES vehicle_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── QUEUE TABLE ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS queue (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    controller_id INT UNSIGNED NOT NULL,
    vehicle_type_id INT UNSIGNED NOT NULL,
    plate_number VARCHAR(20) NOT NULL,
    plate_parity VARCHAR(10) DEFAULT '',
    driver_name VARCHAR(100),
    driver_phone VARCHAR(20),
    token VARCHAR(8) NOT NULL,
    queue_type ENUM('normal','vip','waiting') DEFAULT 'normal',
    position INT DEFAULT 0,
    status ENUM('queued','serving','served','cancelled') DEFAULT 'queued',
    priority_tier TINYINT DEFAULT 7,
    fuel_amount_gallons DECIMAL(10,2) DEFAULT 0,
    fuel_amount_liters DECIMAL(10,2) DEFAULT 0,
    registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    served_at TIMESTAMP NULL,
    batch_id INT UNSIGNED DEFAULT 0,
    notes TEXT,
    FOREIGN KEY (controller_id) REFERENCES controllers(id) ON DELETE CASCADE,
    FOREIGN KEY (vehicle_type_id) REFERENCES vehicle_types(id),
    INDEX idx_controller (controller_id),
    INDEX idx_vehicle_type (vehicle_type_id),
    INDEX idx_plate (plate_number),
    INDEX idx_status (status),
    INDEX idx_queue_type (queue_type),
    INDEX idx_position (position),
    INDEX idx_batch (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── REPORT TABLE (Served Vehicles History) ────────────────────────
CREATE TABLE IF NOT EXISTS report (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    controller_id INT UNSIGNED NOT NULL,
    vehicle_type_id INT UNSIGNED NOT NULL,
    plate_number VARCHAR(20) NOT NULL,
    plate_parity VARCHAR(10) DEFAULT '',
    driver_name VARCHAR(100),
    driver_phone VARCHAR(20),
    token VARCHAR(8) NOT NULL,
    queue_type ENUM('normal','vip','waiting') DEFAULT 'normal',
    position INT DEFAULT 0,
    status VARCHAR(20) DEFAULT 'served',
    priority_tier TINYINT DEFAULT 7,
    fuel_amount_gallons DECIMAL(10,2) DEFAULT 0,
    fuel_amount_liters DECIMAL(10,2) DEFAULT 0,
    registered_at TIMESTAMP NULL,
    served_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    report_date DATE NOT NULL,
    notes TEXT,
    FOREIGN KEY (controller_id) REFERENCES controllers(id) ON DELETE CASCADE,
    FOREIGN KEY (vehicle_type_id) REFERENCES vehicle_types(id),
    INDEX idx_controller (controller_id),
    INDEX idx_plate (plate_number),
    INDEX idx_date (report_date),
    INDEX idx_served (served_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── FUEL STOCK TABLE ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS fuel_stock (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    controller_id INT UNSIGNED NOT NULL,
    fuel_type ENUM('benzene','diesel','kerosene') NOT NULL,
    current_stock_gallons DECIMAL(10,2) DEFAULT 0,
    current_stock_liters DECIMAL(10,2) DEFAULT 0,
    allocated_quota_gallons DECIMAL(10,2) DEFAULT 0,
    allocated_quota_liters DECIMAL(10,2) DEFAULT 0,
    used_today_gallons DECIMAL(10,2) DEFAULT 0,
    used_today_liters DECIMAL(10,2) DEFAULT 0,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (controller_id) REFERENCES controllers(id) ON DELETE CASCADE,
    UNIQUE KEY unique_controller_fuel (controller_id, fuel_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── FUEL LOG TABLE ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS fuel_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    controller_id INT UNSIGNED NOT NULL,
    fuel_type ENUM('benzene','diesel','kerosene') NOT NULL,
    action ENUM('add','allocate','use','reset','set') NOT NULL,
    amount_gallons DECIMAL(10,2) NOT NULL,
    amount_liters DECIMAL(10,2) NOT NULL,
    previous_stock_gallons DECIMAL(10,2),
    previous_stock_liters DECIMAL(10,2),
    new_stock_gallons DECIMAL(10,2),
    new_stock_liters DECIMAL(10,2),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (controller_id) REFERENCES controllers(id) ON DELETE CASCADE,
    INDEX idx_controller (controller_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── ADMIN USERS TABLE ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS admin_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    role ENUM('admin', 'manager', 'staff') DEFAULT 'admin',
    is_active TINYINT(1) DEFAULT 1,
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── ACTIVITY LOG TABLE ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS activity_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id INT UNSIGNED NOT NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES admin_users(id) ON DELETE CASCADE,
    INDEX idx_admin (admin_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── SETTINGS TABLE ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════
-- INITIAL DATA
-- ═══════════════════════════════════════════════════════════════════

-- ─── INSERT DEFAULT VEHICLE TYPES ───────────────────────────────────
INSERT INTO vehicle_types (name, name_am, icon, sort_order) VALUES
('bajaj', 'ባጃጅ', '🛺', 1),
('taxi', 'ታክሲ', '🚕', 2),
('private', 'የግል መኪና', '🚗', 3),
('minibus', 'ሚኒባስ', '🚐', 4),
('truck', 'ጭነት', '🚚', 5),
('motorcycle', 'ሞተርሳይክል', '🏍️', 6);

-- ─── INSERT DEFAULT CONTROLLER ──────────────────────────────────────
-- Username: madeya, Password: CHANGE_ME (CHANGE THIS IMMEDIATELY!)
INSERT INTO controllers (username, password, full_name, phone, station_name) VALUES
('madeya', '$2y$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/LewY5GyYIxIvoK5jO', 'Station Controller', '+251-911-000-000', 'Etel Madeya Gas Station');

-- ─── INSERT DEFAULT ADMIN USER ──────────────────────────────────────
-- Username: admin, Password: CHANGE_ME (CHANGE THIS IMMEDIATELY!)
INSERT INTO admin_users (username, password, full_name, email, role, is_active) VALUES
('admin', '$2y$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/LewY5GyYIxIvoK5jO', 'Administrator', 'admin@etelmadeya.com', 'admin', 1);

-- ─── INSERT DEFAULT SETTINGS ────────────────────────────────────────
INSERT INTO settings (setting_key, setting_value) VALUES
('station_name', 'Etel Madeya Gas Station'),
('station_name_am', 'የኢትል ማደያ የነዳጅ ጣቢያ'),
('phone', '+251-911-000-000'),
('email', 'info@etelmadeya.com'),
('alert_threshold', '30'),
('max_queue_size', '500'),
('enable_vip_queue', '1'),
('enable_waiting_queue', '1'),
('enable_priority_tiers', '1'),
('fuel_capacity_liters', '0'),
('fuel_stock_liters', '0');

-- ═══════════════════════════════════════════════════════════════════
-- END OF SCHEMA
-- ═══════════════════════════════════════════════════════════════════
