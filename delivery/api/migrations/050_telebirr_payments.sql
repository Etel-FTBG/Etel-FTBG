-- ============================================================
-- Manual Telebirr payment confirmation.
--
-- Telebirr's USSD flow (*150#) has no live API integration here - the
-- customer dials it themselves and the founder verifies payment
-- happened by checking his own Telebirr merchant SMS/dashboard
-- separately, the same way bank transfers (Abysinia/CBE) already work
-- in this system with no automated confirmation either. This table
-- just gives that manual process a real, auditable record instead of
-- happening entirely outside the app - a customer's checkout no
-- longer 404s, and the founder gets a real list to work through
-- instead of cross-referencing SMS messages against a spreadsheet.
--
-- NOTE ON FORMATTING: every comment on its own line, every
-- statement-terminating `;` last on its line, so api/migrate.php's naive
-- line-based statement splitter parses this correctly.

CREATE TABLE IF NOT EXISTS `telebirr_payments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `order_id` INT NOT NULL,
  `customer_id` INT NOT NULL,
  `phone` VARCHAR(20) NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `status` ENUM('pending','completed','rejected') NOT NULL DEFAULT 'pending',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `confirmed_at` DATETIME NULL DEFAULT NULL,
  `confirmed_by` INT NULL DEFAULT NULL,
  KEY `idx_telebirr_order` (`order_id`),
  KEY `idx_telebirr_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
