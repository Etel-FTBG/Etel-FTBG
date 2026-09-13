-- ═══════════════════════════════════════════════════════════
-- ETEL Delivery v3.1.2 — Final Password Fix
-- phpMyAdmin → etel_delivery → SQL tab → paste → Go
-- ═══════════════════════════════════════════════════════════

-- Update/insert admin user (password: admin1234)
INSERT INTO `users` (id, name, phone, password_hash, role, active)
VALUES (1, 'Etel Admin', '0900000000', '$2y$10$6eiAe1TyDWg9Bhj9mKAq1.I.DQMYBZ27NOSnfcZuVn6wqN2F2ba62', 'admin', 1)
ON DUPLICATE KEY UPDATE
  password_hash = '$2y$10$6eiAe1TyDWg9Bhj9mKAq1.I.DQMYBZ27NOSnfcZuVn6wqN2F2ba62',
  role          = 'admin',
  active        = 1;

-- Update/insert demo customer (password: etel1234)
INSERT INTO `users` (id, name, phone, password_hash, role, active)
VALUES (2, 'Selam Tadesse', '+251911000001', '$2y$10$ixdMcYmkIuQhvg29BNY0k.m7gc2F2cciu4GknR73kCEfovAjIm4CW', 'customer', 1)
ON DUPLICATE KEY UPDATE
  password_hash = '$2y$10$ixdMcYmkIuQhvg29BNY0k.m7gc2F2cciu4GknR73kCEfovAjIm4CW',
  role          = 'customer',
  active        = 1;

-- Update/insert demo courier (password: etel1234)
INSERT INTO `users` (id, name, phone, password_hash, role, active)
VALUES (3, 'Dawit Tesfaye', '+251911000002', '$2y$10$ixdMcYmkIuQhvg29BNY0k.m7gc2F2cciu4GknR73kCEfovAjIm4CW', 'courier', 1)
ON DUPLICATE KEY UPDATE
  password_hash = '$2y$10$ixdMcYmkIuQhvg29BNY0k.m7gc2F2cciu4GknR73kCEfovAjIm4CW',
  role          = 'courier',
  active        = 1;

-- Courier profile
INSERT INTO `couriers` (user_id, courier_code, vehicle_type, home_zone, zone, status, rating, total_deliveries, active)
VALUES (3, 'C-00001', 'motorcycle', 'Bole', 'Bole', 'online', 4.90, 312, 1)
ON DUPLICATE KEY UPDATE status='online', rating=4.90, active=1;

-- Zones
INSERT INTO `delivery_zones` (id, name, base_fare, surge_active, surge_mult, sort_order) VALUES
(1,'Bole',50,1,1.50,1),(2,'Piassa',40,0,1.50,2),(3,'Kazanchis',45,0,1.50,3),
(4,'CMC',55,1,1.50,4),(5,'Megenagna',50,0,1.50,5),(6,'Sarbet',45,0,1.50,6),
(7,'Gerji',60,1,1.50,7),(8,'Kaliti',65,0,1.50,8),(9,'Lideta',40,0,1.50,9),
(10,'Urael',45,0,1.50,10),(11,'Mexico',50,0,1.50,11),(12,'Gofa',55,0,1.50,12)
ON DUPLICATE KEY UPDATE base_fare=VALUES(base_fare);

-- Pricing rules
INSERT INTO `pricing_rules` (rule_key, rule_value, description) VALUES
('base_fare',15,'Fixed base fee'),('per_km_rate',8,'Per km'),
('min_fare',30,'Minimum fare'),('surge_multiplier',1.5,'Surge x'),
('night_multiplier',1.3,'Night rate'),('cross_zone_fee',20,'Cross zone'),
('doc_fee',30,'Document'),('parcel_fee',50,'Parcel'),
('food_fee',40,'Food'),('bulk_fee',120,'Bulk')
ON DUPLICATE KEY UPDATE rule_value=VALUES(rule_value);

-- Verify
SELECT id, name, phone, role, active, LEFT(password_hash, 20) AS hash_preview FROM `users` ORDER BY id;
