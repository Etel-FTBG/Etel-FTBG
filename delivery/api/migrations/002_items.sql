-- Migration 002: Items + order items
-- Adds product catalog tables for service givers.
-- Source: legacy api/items_schema.sql

CREATE TABLE IF NOT EXISTS `items` (
    `id`               INT AUTO_INCREMENT PRIMARY KEY,
    `service_giver_id` INT NOT NULL,
    `item_code`        VARCHAR(20) UNIQUE NOT NULL,
    `name`             VARCHAR(255) NOT NULL,
    `description`      TEXT,
    `category`         ENUM('food', 'grocery', 'package', 'pharmacy', 'flower') NOT NULL,
    `price`            DECIMAL(10,2) NOT NULL,
    `original_price`   DECIMAL(10,2),
    `image_url`        VARCHAR(500),
    `is_available`     BOOLEAN DEFAULT 1,
    `stock_quantity`   INT DEFAULT 0,
    `preparation_time` INT DEFAULT 30 COMMENT 'Minutes',
    `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`service_giver_id`) REFERENCES `service_givers`(`id`) ON DELETE CASCADE,
    INDEX `idx_category`        (`category`),
    INDEX `idx_available`       (`is_available`),
    INDEX `idx_service_giver`   (`service_giver_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `order_items` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `order_id`   INT NOT NULL,
    `item_id`    INT NOT NULL,
    `item_name`  VARCHAR(255) NOT NULL,
    `quantity`   INT NOT NULL DEFAULT 1,
    `unit_price` DECIMAL(10,2) NOT NULL,
    `subtotal`   DECIMAL(10,2) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`item_id`)  REFERENCES `items`(`id`)    ON DELETE RESTRICT,
    INDEX `idx_order` (`order_id`),
    INDEX `idx_item`  (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;