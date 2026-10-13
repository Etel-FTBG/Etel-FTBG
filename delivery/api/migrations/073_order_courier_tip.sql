-- Courier tip: money the customer adds at checkout, 100% the courier's. Included in orders.fare_total.
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `courier_tip` DECIMAL(10,2) NOT NULL DEFAULT 0;
