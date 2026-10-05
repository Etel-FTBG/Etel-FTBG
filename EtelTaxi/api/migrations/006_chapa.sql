ALTER TABLE `taxi_dues_payments` MODIFY COLUMN `payment_method` ENUM('etelpay_wallet','telebirr','cbe','abysinia','cash','chapa') NOT NULL;
ALTER TABLE `taxi_dues_payments` MODIFY COLUMN `status` ENUM('pending_verification','verified','rejected','awaiting_chapa') NOT NULL DEFAULT 'pending_verification';
