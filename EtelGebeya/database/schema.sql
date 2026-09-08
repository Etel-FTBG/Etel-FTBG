-- =============================================================================
-- Etel Gebeya — Database schema (MySQL 5.7+ / MariaDB 10.3+, utf8mb4)
-- =============================================================================
-- This file is the single source of truth. The installer runs it.
-- All tables are prefixed with `eg_` (configurable in config/app.php).
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- Users + auth
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `eg_users` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name`       VARCHAR(120) NOT NULL,
  `email`           VARCHAR(190) NOT NULL,
  `phone`           VARCHAR(32)  DEFAULT NULL,
  `password_hash`   VARCHAR(255) NOT NULL,
  `locale`          ENUM('en','am') NOT NULL DEFAULT 'en',
  `avatar`          VARCHAR(255) DEFAULT NULL,
  `status`          ENUM('active','banned','pending') NOT NULL DEFAULT 'active',
  `email_verified_at` DATETIME  DEFAULT NULL,
  `last_login_at`   DATETIME    DEFAULT NULL,
  `created_at`      DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email` (`email`),
  KEY `idx_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `eg_user_roles` (
  `user_id` INT UNSIGNED NOT NULL,
  `role`    ENUM('customer','vendor','courier','admin') NOT NULL,
  `granted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`,`role`),
  CONSTRAINT `fk_ur_user` FOREIGN KEY (`user_id`) REFERENCES `eg_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eg_addresses` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `label`      VARCHAR(60)  DEFAULT NULL,           -- "Home", "Work"
  `full_name`  VARCHAR(120) NOT NULL,
  `phone`      VARCHAR(32)  NOT NULL,
  `region`     VARCHAR(80)  NOT NULL,              -- Addis Ababa, Oromia, Amhara...
  `city`       VARCHAR(80)  NOT NULL,
  `subcity`    VARCHAR(80)  DEFAULT NULL,
  `woreda`     VARCHAR(40)  DEFAULT NULL,
  `kebele`     VARCHAR(40)  DEFAULT NULL,
  `street`     VARCHAR(255) DEFAULT NULL,
  `house_no`   VARCHAR(60)  DEFAULT NULL,
  `lat`        DECIMAL(10,7) DEFAULT NULL,
  `lng`        DECIMAL(10,7) DEFAULT NULL,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `fk_addr_user` FOREIGN KEY (`user_id`) REFERENCES `eg_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eg_login_attempts` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`        VARCHAR(190) NOT NULL,
  `ip`           VARCHAR(64)  NOT NULL,
  `success`      TINYINT(1)   NOT NULL DEFAULT 0,
  `attempted_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ip_time` (`ip`,`attempted_at`),
  KEY `idx_email_time` (`email`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Vendors
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `eg_vendors` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        INT UNSIGNED NOT NULL,
  `shop_name`      VARCHAR(160) NOT NULL,
  `shop_slug`      VARCHAR(180) NOT NULL,
  `tagline_en`     VARCHAR(255) DEFAULT NULL,
  `tagline_am`     VARCHAR(255) DEFAULT NULL,
  `description_en` TEXT,
  `description_am` TEXT,
  `logo`           VARCHAR(255) DEFAULT NULL,
  `banner`         VARCHAR(255) DEFAULT NULL,
  `business_type`  ENUM('individual','company','cooperative') NOT NULL DEFAULT 'individual',
  `tin_number`     VARCHAR(64)  DEFAULT NULL,
  `trade_license`  VARCHAR(255) DEFAULT NULL,            -- uploaded doc path
  `phone`          VARCHAR(32)  DEFAULT NULL,
  `email`          VARCHAR(190) DEFAULT NULL,
  `region`         VARCHAR(80)  DEFAULT NULL,
  `city`           VARCHAR(80)  DEFAULT NULL,
  `subcity`        VARCHAR(80)  DEFAULT NULL,
  `status`         ENUM('pending','approved','suspended','rejected') NOT NULL DEFAULT 'pending',
  `commission_pct` DECIMAL(5,2) NOT NULL DEFAULT 10.00,  -- platform commission
  `rating_avg`     DECIMAL(3,2) NOT NULL DEFAULT 0.00,
  `rating_count`   INT UNSIGNED NOT NULL DEFAULT 0,
  `approved_at`    DATETIME    DEFAULT NULL,
  `created_at`     DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user` (`user_id`),
  UNIQUE KEY `uq_slug` (`shop_slug`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_v_user` FOREIGN KEY (`user_id`) REFERENCES `eg_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Catalog
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `eg_categories` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_id`  INT UNSIGNED DEFAULT NULL,
  `name_en`    VARCHAR(120) NOT NULL,
  `name_am`    VARCHAR(120) NOT NULL,
  `slug`       VARCHAR(140) NOT NULL,
  `icon`       VARCHAR(80)  DEFAULT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_slug` (`slug`),
  KEY `idx_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eg_products` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `vendor_id`       INT UNSIGNED NOT NULL,
  `category_id`     INT UNSIGNED DEFAULT NULL,
  `name_en`         VARCHAR(255) NOT NULL,
  `name_am`         VARCHAR(255) DEFAULT NULL,
  `slug`            VARCHAR(280) NOT NULL,
  `description_en`  TEXT,
  `description_am`  TEXT,
  `price`           DECIMAL(12,2) NOT NULL,
  `compare_price`   DECIMAL(12,2) DEFAULT NULL,        -- strike-through / original
  `cost_price`      DECIMAL(12,2) DEFAULT NULL,        -- for vendor profit calc
  `currency`        VARCHAR(3)   NOT NULL DEFAULT 'ETB',
  `sku`             VARCHAR(80)  DEFAULT NULL,
  `stock_qty`       INT NOT NULL DEFAULT 0,
  `low_stock_at`    INT NOT NULL DEFAULT 5,
  `track_stock`     TINYINT(1) NOT NULL DEFAULT 1,
  `weight_kg`       DECIMAL(8,3) DEFAULT NULL,
  `status`          ENUM('draft','pending','published','rejected','archived') NOT NULL DEFAULT 'draft',
  `rejection_reason` VARCHAR(255) DEFAULT NULL,
  `rating_avg`      DECIMAL(3,2) NOT NULL DEFAULT 0.00,
  `rating_count`    INT UNSIGNED NOT NULL DEFAULT 0,
  `sold_count`      INT UNSIGNED NOT NULL DEFAULT 0,
  `view_count`      INT UNSIGNED NOT NULL DEFAULT 0,
  `is_featured`     TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_slug` (`slug`),
  KEY `idx_vendor` (`vendor_id`),
  KEY `idx_category` (`category_id`),
  KEY `idx_status` (`status`),
  FULLTEXT KEY `ft_search` (`name_en`,`description_en`,`name_am`,`description_am`),
  CONSTRAINT `fk_p_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `eg_vendors`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_p_cat`    FOREIGN KEY (`category_id`) REFERENCES `eg_categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eg_product_images` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` INT UNSIGNED NOT NULL,
  `path`       VARCHAR(255) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_product` (`product_id`),
  CONSTRAINT `fk_pi_p` FOREIGN KEY (`product_id`) REFERENCES `eg_products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eg_product_variants` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` INT UNSIGNED NOT NULL,
  `name`       VARCHAR(120) NOT NULL,        -- "Color: Red, Size: L"
  `sku`        VARCHAR(80)  DEFAULT NULL,
  `price_diff` DECIMAL(12,2) NOT NULL DEFAULT 0,  -- added to base price
  `stock_qty`  INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_product` (`product_id`),
  CONSTRAINT `fk_pv_p` FOREIGN KEY (`product_id`) REFERENCES `eg_products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Cart + Wishlist (cart is DB-persisted for logged-in users; session fallback for guests)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `eg_cart_items` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `variant_id` INT UNSIGNED DEFAULT NULL,
  `qty`        INT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_product_variant` (`user_id`,`product_id`,`variant_id`),
  CONSTRAINT `fk_ci_user` FOREIGN KEY (`user_id`) REFERENCES `eg_users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ci_prod` FOREIGN KEY (`product_id`) REFERENCES `eg_products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eg_wishlist` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_prod` (`user_id`,`product_id`),
  CONSTRAINT `fk_wl_user` FOREIGN KEY (`user_id`) REFERENCES `eg_users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wl_prod` FOREIGN KEY (`product_id`) REFERENCES `eg_products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Orders
-- -----------------------------------------------------------------------------
-- An order is the customer's purchase. It can have many vendor sub-orders.
CREATE TABLE IF NOT EXISTS `eg_orders` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`            VARCHAR(20)  NOT NULL,                            -- EG-YYYYMMDD-XXXXX
  `customer_id`     INT UNSIGNED NOT NULL,
  `address_id`      INT UNSIGNED DEFAULT NULL,
  `subtotal`        DECIMAL(12,2) NOT NULL DEFAULT 0,
  `shipping_fee`    DECIMAL(12,2) NOT NULL DEFAULT 0,
  `tax`             DECIMAL(12,2) NOT NULL DEFAULT 0,
  `discount`        DECIMAL(12,2) NOT NULL DEFAULT 0,
  `total`           DECIMAL(12,2) NOT NULL DEFAULT 0,
  `currency`        VARCHAR(3) NOT NULL DEFAULT 'ETB',
  `status`          ENUM('pending','awaiting_payment','paid','processing','partially_shipped','shipped','delivered','cancelled','refunded','failed') NOT NULL DEFAULT 'pending',
  `payment_method`  ENUM('cod','telebirr','cbe','chapa','bank_transfer') DEFAULT NULL,
  `payment_status`  ENUM('unpaid','pending','paid','partial','refunded','failed') NOT NULL DEFAULT 'unpaid',
  `notes`           TEXT,
  `locale`          ENUM('en','am') NOT NULL DEFAULT 'en',
  `placed_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `paid_at`         DATETIME DEFAULT NULL,
  `completed_at`    DATETIME DEFAULT NULL,
  `cancelled_at`    DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_code` (`code`),
  KEY `idx_customer` (`customer_id`),
  KEY `idx_status` (`status`),
  KEY `idx_payment_status` (`payment_status`),
  KEY `idx_placed_at` (`placed_at`),
  CONSTRAINT `fk_o_cust` FOREIGN KEY (`customer_id`) REFERENCES `eg_users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Each order is split by vendor — this is the per-vendor fulfillment unit.
CREATE TABLE IF NOT EXISTS `eg_order_vendors` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`        INT UNSIGNED NOT NULL,
  `vendor_id`       INT UNSIGNED NOT NULL,
  `subtotal`        DECIMAL(12,2) NOT NULL DEFAULT 0,
  `shipping_fee`    DECIMAL(12,2) NOT NULL DEFAULT 0,
  `commission_pct`  DECIMAL(5,2) NOT NULL DEFAULT 10.00,
  `commission_amt`  DECIMAL(12,2) NOT NULL DEFAULT 0,
  `vendor_earnings` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `status`          ENUM('pending','processing','shipped','delivered','cancelled','returned') NOT NULL DEFAULT 'pending',
  `courier_id`      INT UNSIGNED DEFAULT NULL,
  `tracking_code`   VARCHAR(64)  DEFAULT NULL,
  `delivered_at`    DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`),
  KEY `idx_vendor` (`vendor_id`),
  KEY `idx_courier` (`courier_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_ov_order`  FOREIGN KEY (`order_id`)  REFERENCES `eg_orders`(`id`)  ON DELETE CASCADE,
  CONSTRAINT `fk_ov_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `eg_vendors`(`id`),
  CONSTRAINT `fk_ov_cour`   FOREIGN KEY (`courier_id`) REFERENCES `eg_users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eg_order_items` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`   INT UNSIGNED NOT NULL,
  `vendor_sub_id` INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `variant_id` INT UNSIGNED DEFAULT NULL,
  `name`       VARCHAR(255) NOT NULL,                    -- snapshot
  `price`      DECIMAL(12,2) NOT NULL,
  `qty`        INT NOT NULL,
  `line_total` DECIMAL(12,2) NOT NULL,
  `image`      VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`),
  KEY `idx_sub` (`vendor_sub_id`),
  CONSTRAINT `fk_oi_order` FOREIGN KEY (`order_id`) REFERENCES `eg_orders`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_oi_sub`   FOREIGN KEY (`vendor_sub_id`) REFERENCES `eg_order_vendors`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_oi_prod`  FOREIGN KEY (`product_id`) REFERENCES `eg_products`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eg_order_status_log` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`   INT UNSIGNED NOT NULL,
  `status`     VARCHAR(40) NOT NULL,
  `note`       VARCHAR(255) DEFAULT NULL,
  `by_user_id` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`),
  CONSTRAINT `fk_osl_o` FOREIGN KEY (`order_id`) REFERENCES `eg_orders`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Payments
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `eg_payments` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`         INT UNSIGNED NOT NULL,
  `method`           ENUM('cod','telebirr','cbe','chapa','bank_transfer') NOT NULL,
  `status`           ENUM('initiated','pending','paid','failed','refunded') NOT NULL DEFAULT 'initiated',
  `amount`           DECIMAL(12,2) NOT NULL,
  `currency`         VARCHAR(3) NOT NULL DEFAULT 'ETB',
  `txn_ref`          VARCHAR(120) DEFAULT NULL,         -- gateway txn id
  `gateway_response` JSON DEFAULT NULL,
  `paid_at`          DATETIME DEFAULT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`),
  KEY `idx_status` (`status`),
  KEY `idx_txn` (`txn_ref`),
  CONSTRAINT `fk_pay_o` FOREIGN KEY (`order_id`) REFERENCES `eg_orders`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eg_vendor_payouts` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `vendor_id`  INT UNSIGNED NOT NULL,
  `amount`     DECIMAL(12,2) NOT NULL,
  `method`     ENUM('bank','telebirr','cbe','cash') NOT NULL DEFAULT 'bank',
  `status`     ENUM('pending','approved','paid','rejected') NOT NULL DEFAULT 'pending',
  `reference`  VARCHAR(120) DEFAULT NULL,
  `note`       VARCHAR(255) DEFAULT NULL,
  `requested_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_vendor` (`vendor_id`),
  CONSTRAINT `fk_vp_v` FOREIGN KEY (`vendor_id`) REFERENCES `eg_vendors`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Reviews
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `eg_reviews` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` INT UNSIGNED NOT NULL,
  `customer_id` INT UNSIGNED NOT NULL,
  `rating`     TINYINT NOT NULL,                        -- 1..5
  `title`      VARCHAR(160) DEFAULT NULL,
  `body`       TEXT,
  `status`     ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_product` (`customer_id`,`product_id`),
  KEY `idx_product` (`product_id`),
  CONSTRAINT `fk_rv_p` FOREIGN KEY (`product_id`) REFERENCES `eg_products`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rv_u` FOREIGN KEY (`customer_id`) REFERENCES `eg_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Settings
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `eg_settings` (
  `key_name` VARCHAR(120) NOT NULL,
  `value`    TEXT,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
