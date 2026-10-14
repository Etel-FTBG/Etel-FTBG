-- Courier pay by distance (Admin > Pricing > Courier pay): the amount saved on an order the first time the courier's
-- app sees it, plus a short note (distance, or who changed it). NULL = the courier is paid the delivery fee.
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `courier_pay` DECIMAL(10,2) NULL DEFAULT NULL;
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `courier_pay_note` VARCHAR(255) NULL DEFAULT NULL;
