-- ═══════════════════════════════════════════════════════════════
-- ETEL Delivery v3.0.0 — Seed Data ONLY
-- Run this in phpMyAdmin → etel_delivery → SQL tab
-- ═══════════════════════════════════════════════════════════════

-- Step 1: Check what columns exist in users table and add 'active' if missing
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `active` TINYINT(1) NOT NULL DEFAULT 1;

-- Step 2: Insert demo users (using only safe columns)
INSERT INTO `users` (id, name, phone, password_hash, role, active)
VALUES
  (1, 'Etel Admin',       '0900000000',    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin',    1),
  (2, 'Selam Tadesse',    '+251911000001', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'customer', 1),
  (3, 'Dawit Tesfaye',    '+251911000002', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'courier',  1)
ON DUPLICATE KEY UPDATE name=VALUES(name), role=VALUES(role), active=1;

-- Step 3: Insert courier profile for Dawit
INSERT INTO `couriers` (user_id, courier_code, vehicle_type, home_zone, status, rating, total_deliveries, active)
VALUES (3, 'C-00001', 'motorcycle', 'Bole', 'online', 4.90, 312, 1)
ON DUPLICATE KEY UPDATE status='online', rating=4.90;

-- Step 4: Zones (safe re-insert)
INSERT INTO `delivery_zones` (id, name, base_fare, surge_active, surge_mult, sort_order) VALUES
(1,'Bole',50,1,1.50,1),(2,'Piassa',40,0,1.50,2),(3,'Kazanchis',45,0,1.50,3),
(4,'CMC',55,1,1.50,4),(5,'Megenagna',50,0,1.50,5),(6,'Sarbet',45,0,1.50,6),
(7,'Gerji',60,1,1.50,7),(8,'Kaliti',65,0,1.50,8),(9,'Lideta',40,0,1.50,9),
(10,'Urael',45,0,1.50,10),(11,'Mexico',50,0,1.50,11),(12,'Gofa',55,0,1.50,12)
ON DUPLICATE KEY UPDATE base_fare=VALUES(base_fare), surge_active=VALUES(surge_active);

-- Step 5: Pricing rules
INSERT INTO `pricing_rules` (rule_key, rule_value, description) VALUES
('base_fare',      15,  'Fixed base fee per order'),
('per_km_rate',    8,   'Additional fee per estimated km'),
('min_fare',       30,  'Minimum order fare'),
('surge_multiplier',1.5,'Multiplier during surge'),
('night_multiplier',1.3,'Multiplier 10PM–6AM'),
('cross_zone_fee', 20,  'Extra fee for cross-zone deliveries'),
('doc_fee',        30,  'Document handling fee'),
('parcel_fee',     50,  'Standard parcel fee'),
('food_fee',       40,  'Food & grocery fee'),
('bulk_fee',       120, 'Bulk/heavy items fee')
ON DUPLICATE KEY UPDATE rule_value=VALUES(rule_value);

-- Step 6: Sample orders
INSERT INTO `orders` (id, order_code, customer_id, courier_id, from_zone, to_zone, package_type, payment_method, fare_total, status, placed_at) VALUES
(1,'ETD-2249',2,1,'Lideta','Urael','document','etelpay',45,'delivered','2026-05-20 09:44:00'),
(2,'ETD-2250',2,1,'Piassa','CMC','food','telebirr',65,'delivered','2026-05-20 09:58:00'),
(3,'ETD-2251',2,NULL,'Gerji','Mexico','bulk','cash',130,'pending','2026-05-20 10:15:00'),
(4,'ETD-2252',2,1,'Urael','Bole','parcel','etelpay',90,'delivered','2026-05-20 10:18:00'),
(5,'ETD-2253',2,NULL,'Megenagna','Sarbet','food','telebirr',75,'pending','2026-05-20 10:31:00'),
(6,'ETD-2254',2,NULL,'CMC','Piassa','document','cash',60,'pending','2026-05-20 10:38:00'),
(7,'ETD-2255',2,1,'Bole','Kazanchis','parcel','etelpay',85,'assigned','2026-05-20 10:42:00')
ON DUPLICATE KEY UPDATE status=VALUES(status);

SELECT 'Seed complete ✓' AS result;
