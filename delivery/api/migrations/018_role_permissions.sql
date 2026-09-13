-- ============================================================
-- Delivery - real role-based permissions
-- Previously, any EtelGroup-assigned role (Dispatcher, Support, ...)
-- collapsed into the single binary role='admin', giving full access
-- regardless of the specific role name. This adds a second column
-- (staff_role) that preserves the actual assigned role, plus a table
-- defining what each role name is actually allowed to do.
-- ============================================================

ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `staff_role` VARCHAR(100) NULL AFTER `role`;

CREATE TABLE IF NOT EXISTS `role_permissions` (
  `id`           INT(11)      NOT NULL AUTO_INCREMENT,
  `role_name`    VARCHAR(100) NOT NULL,
  `capabilities` TEXT         NOT NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_name` (`role_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sensible starting defaults for the role names already suggested in
-- EtelGroup's Employees panel. "*" means unrestricted (same as the
-- original hardcoded admin accounts). The owner can edit any of these
-- (or add new role names) from Delivery admin's new Roles panel.
INSERT IGNORE INTO `role_permissions` (`role_name`, `capabilities`) VALUES
  ('Owner',         '["*"]'),
  ('Admin',         '["*"]'),
  ('Manager',       '["*"]'),
  ('Dispatcher',    '["orders.view","orders.manage","rides.view","rides.manage","users.view","service_givers.view"]'),
  ('Support',       '["orders.view","rides.view","users.view","sos.view","sos.manage"]'),
  ('Finance',       '["finance.view","finance.manage","orders.view","analytics.view"]'),
  ('Driver Support','["users.view","rides.view","sos.view","sos.manage"]'),
  ('Traffic Officer','["orders.view"]');
