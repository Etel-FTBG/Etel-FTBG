-- Migration 091: rider price offers and driver counter-offers.
--
-- The rider may name their own price for a trip with a destination; drivers accept it or send one higher counter price;
-- the rider picks one and the price is fixed (api/lib/ride_price_offer.php). The columns rides.rider_offer and
-- rides.agreed_fare are added on first use of the feature, so this migration creates the counter table and the
-- settings (all editable in pricing_rules).
--
-- NOTE ON FORMATTING: every comment on its own line, every
-- statement-terminating `;` last on its line, so api/migrate.php's naive
-- line-based statement splitter parses this correctly.

CREATE TABLE IF NOT EXISTS `ride_counter_offers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ride_id` INT NOT NULL,
  `driver_id` INT NOT NULL,
  `price` DECIMAL(10,2) NOT NULL,
  `status` VARCHAR(10) NOT NULL DEFAULT 'pending',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL,
  UNIQUE KEY `uq_counter_ride_driver` (`ride_id`, `driver_id`),
  KEY `idx_counter_ride` (`ride_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO pricing_rules (rule_key, rule_value, description) VALUES ('ride_offer_enabled', 1, 'Riders may offer their own price (1 = on, 0 = off)');

INSERT IGNORE INTO pricing_rules (rule_key, rule_value, description) VALUES ('ride_offer_min_pct', 70, 'Lowest price a rider can offer, as a percent of the quoted fare');

INSERT IGNORE INTO pricing_rules (rule_key, rule_value, description) VALUES ('ride_offer_max_pct', 200, 'Highest price a rider can offer or a driver can ask, as a percent of the quoted fare');

INSERT IGNORE INTO pricing_rules (rule_key, rule_value, description) VALUES ('ride_counter_max', 5, 'Most drivers that can have a counter-offer open on one trip');

INSERT IGNORE INTO pricing_rules (rule_key, rule_value, description) VALUES ('ride_counter_ttl_secs', 90, 'Seconds a driver counter-offer stays open for the rider');
