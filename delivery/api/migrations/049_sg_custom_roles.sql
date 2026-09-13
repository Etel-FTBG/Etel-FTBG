-- ============================================================
-- Custom staff roles for Service Giver businesses.
--
-- Builds on the existing sg_staff table (016_sg_staff.sql), which only
-- ever supported a single hardcoded 'cashier' tier. Each business can
-- now define its own role names (e.g. "Kitchen", "Delivery Coordinator",
-- "Pharmacist") with its own capability set, the same pattern Delivery
-- already uses for EtelGroup staff roles (role_permissions table) but
-- scoped per sg_id instead of being global - a role named "Manager" at
-- one restaurant has nothing to do with a role named "Manager" at a
-- different one.
--
-- NOTE ON FORMATTING: every comment on its own line, every
-- statement-terminating `;` last on its line, so api/migrate.php's naive
-- line-based statement splitter parses this correctly.

CREATE TABLE IF NOT EXISTS `sg_roles` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `service_giver_id` INT NOT NULL,
  `role_name` VARCHAR(40) NOT NULL,
  `capabilities` TEXT NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_sg_role_name` (`service_giver_id`, `role_name`),
  KEY `idx_sg_roles_business` (`service_giver_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `sg_staff`
  ADD COLUMN `role_id` INT NULL DEFAULT NULL AFTER `staff_role`;

ALTER TABLE `sg_staff`
  ADD KEY `idx_sg_staff_role` (`role_id`);
