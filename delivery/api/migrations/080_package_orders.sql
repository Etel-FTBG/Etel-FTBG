-- Migration 080: package delivery (a customer sends a parcel from A to B, no shop involved).
-- A package is an ordinary row in `orders` with order_kind = 'package'. It carries the chosen vehicle class (bike, moto, car, van),
-- the pickup address and the package details. Prices come from `package_rates`, which staff edit in Admin.
-- The code also adds these columns and the table on first use (api/lib/package_orders.php).
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `order_kind` VARCHAR(12) NOT NULL DEFAULT 'shop';
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `vehicle_class` VARCHAR(12) NULL DEFAULT NULL;
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `pickup_address` VARCHAR(255) NULL DEFAULT NULL;
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `pkg_size` VARCHAR(12) NULL DEFAULT NULL;
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `pkg_weight_kg` DECIMAL(7,2) NULL DEFAULT NULL;
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `pkg_desc` VARCHAR(255) NULL DEFAULT NULL;
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `pkg_value` DECIMAL(10,2) NULL DEFAULT NULL;
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `distance_km` DECIMAL(6,2) NULL DEFAULT NULL;
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `pickup_pin` VARCHAR(6) NULL DEFAULT NULL;
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `delivery_pin` VARCHAR(6) NULL DEFAULT NULL;
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `sender_name` VARCHAR(120) NULL DEFAULT '';
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `sender_phone` VARCHAR(25) NULL DEFAULT '';
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `pickup_lat` DECIMAL(10,7) NULL DEFAULT NULL;
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `pickup_lng` DECIMAL(10,7) NULL DEFAULT NULL;
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `recipient_address` VARCHAR(255) NULL DEFAULT '';

CREATE TABLE IF NOT EXISTS `package_rates` (
  `id`            INT(11)       NOT NULL AUTO_INCREMENT,
  `vehicle_class` VARCHAR(12)   NOT NULL,
  `label`         VARCHAR(60)   NOT NULL,
  `icon`          VARCHAR(8)    NOT NULL DEFAULT '',
  `base_fee`      DECIMAL(10,2) NOT NULL DEFAULT 0,
  `per_km`        DECIMAL(10,2) NOT NULL DEFAULT 0,
  `free_kg`       DECIMAL(7,2)  NOT NULL DEFAULT 0,
  `per_kg_over`   DECIMAL(10,2) NOT NULL DEFAULT 0,
  `min_fee`       DECIMAL(10,2) NOT NULL DEFAULT 0,
  `max_weight_kg` DECIMAL(8,2)  NOT NULL DEFAULT 0,
  `sort_order`    INT(11)       NOT NULL DEFAULT 0,
  `active`        TINYINT(1)    NOT NULL DEFAULT 1,
  `updated_at`    DATETIME      NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vehicle_class` (`vehicle_class`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Starting prices (ETB). Staff change them in Admin; these are only the first values.
INSERT IGNORE INTO `package_rates` (vehicle_class, label, icon, base_fee, per_km, free_kg, per_kg_over, min_fee, max_weight_kg, sort_order, active) VALUES
  ('bike', 'Bicycle',    '🚲',  40,  8,   3, 5,  50,    5, 1, 1),
  ('moto', 'Motorbike',  '🛵',  60, 12,   5, 6,  80,   25, 2, 1),
  ('car',  'Car',        '🚗', 150, 25,  20, 4, 200,  150, 3, 1),
  ('van',  'Van / pickup','🚐', 350, 45, 100, 3, 450, 1000, 4, 1);
