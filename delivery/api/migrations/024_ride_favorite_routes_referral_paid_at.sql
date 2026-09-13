-- Migration 024: Ride favorite routes (rider "Favorites" - saved
-- pickup+dropoff pairs for quick rebooking, since this dispatch model
-- doesn't support picking a specific favorite driver in advance) and a
-- paid_at column on customer_referral_rewards so
-- etel_maybe_pay_referral_reward() (api/boot.php) can record when a
-- pending reward actually got marked paid.
--
-- Same formatting convention as prior migrations - one statement per
-- block, semicolon last on its line, for api/migrate.php's naive
-- line-based statement splitter.

CREATE TABLE IF NOT EXISTS `ride_favorite_routes` (
  `id`               INT(11)      NOT NULL AUTO_INCREMENT,
  `user_id`          INT(11)      NOT NULL,
  `label`            VARCHAR(60)  NOT NULL DEFAULT 'Saved Route',
  `pickup_address`   VARCHAR(255) NOT NULL,
  `pickup_lat`       DECIMAL(10,7) DEFAULT NULL,
  `pickup_lng`       DECIMAL(10,7) DEFAULT NULL,
  `dropoff_address`  VARCHAR(255) NOT NULL,
  `dropoff_lat`      DECIMAL(10,7) DEFAULT NULL,
  `dropoff_lng`      DECIMAL(10,7) DEFAULT NULL,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'customer_referral_rewards'
      AND COLUMN_NAME = 'paid_at'
);

SET @ddl = IF(
    @col_exists = 0,
    'ALTER TABLE `customer_referral_rewards` ADD COLUMN `paid_at` DATETIME DEFAULT NULL',
    'SELECT 1'
);

PREPARE stmt FROM @ddl;

EXECUTE stmt;

DEALLOCATE PREPARE stmt;
