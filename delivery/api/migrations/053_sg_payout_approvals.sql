-- ============================================================
-- Payout method change requests, requiring admin approval.
--
-- A Service Giver changing their bank account, payout bank, or
-- Telebirr number no longer writes directly to service_givers - it
-- creates a pending request here instead. The business keeps operating
-- on its OLD payout details (still live on service_givers) until the
-- founder reviews and approves the change. Non-payout fields (business
-- name, type, zone, address, logo, coordinates) are NOT gated by this -
-- they still save immediately via sg_update_profile, same as before.
--
-- NOTE ON FORMATTING: every comment on its own line, every
-- statement-terminating `;` last on its line, so api/migrate.php's naive
-- line-based statement splitter parses this correctly.

CREATE TABLE IF NOT EXISTS `sg_payout_change_requests` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `service_giver_id` INT NOT NULL,
  `requested_by` INT NOT NULL,
  `old_bank_account` VARCHAR(100) NULL DEFAULT NULL,
  `old_payout_bank` VARCHAR(20) NULL DEFAULT NULL,
  `old_telebirr_number` VARCHAR(20) NULL DEFAULT NULL,
  `new_bank_account` VARCHAR(100) NULL DEFAULT NULL,
  `new_payout_bank` VARCHAR(20) NULL DEFAULT NULL,
  `new_telebirr_number` VARCHAR(20) NULL DEFAULT NULL,
  `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `admin_note` VARCHAR(255) NULL DEFAULT NULL,
  `requested_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `reviewed_by` INT NULL DEFAULT NULL,
  `reviewed_at` DATETIME NULL DEFAULT NULL,
  KEY `idx_payout_req_sg` (`service_giver_id`),
  KEY `idx_payout_req_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
