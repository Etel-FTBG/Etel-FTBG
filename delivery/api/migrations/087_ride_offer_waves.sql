-- Migration 087: ride offer waves (nearest drivers see a new request first).
--
-- A new request is now shown to the nearest ride_offer_wave1_size drivers at once, to the nearest
-- ride_offer_wave2_size after ride_offer_wave_secs seconds, and to everyone in range after twice that.
-- Set ride_offer_wave_secs to 0 to switch waves off (every driver sees every request at once, as before).
-- The table remembers that the wider wake-up push for a ride has been sent, so it goes out only once.
--
-- NOTE ON FORMATTING: every comment on its own line, every
-- statement-terminating `;` last on its line, so api/migrate.php's naive
-- line-based statement splitter parses this correctly.

INSERT IGNORE INTO pricing_rules (rule_key, rule_value, description) VALUES ('ride_offer_wave1_size', 3, 'Drivers who see a new ride request first (nearest to the pickup)');

INSERT IGNORE INTO pricing_rules (rule_key, rule_value, description) VALUES ('ride_offer_wave2_size', 8, 'Drivers who see a new ride request after one wave length');

INSERT IGNORE INTO pricing_rules (rule_key, rule_value, description) VALUES ('ride_offer_wave_secs', 20, 'Seconds between offer waves; everyone sees the ride after twice this. 0 = no waves');

CREATE TABLE IF NOT EXISTS `ride_offer_log` (
  `ride_id` INT NOT NULL,
  `escalated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ride_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
