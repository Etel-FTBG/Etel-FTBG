-- ============================================================
-- EtelTaxi - granular role-based permissions
-- Mirrors the same system just built for Delivery: previously ANY
-- EtelGroup-assigned role at 'etel-transport' collapsed into the
-- single 'super_admin' role, giving full access regardless of the
-- specific role name (Manager, Dispatcher, Finance, ...). This adds
-- real tracking of the actual role plus a permissions table defining
-- what each role name can do.
-- ============================================================

ALTER TABLE `taxi_admins` ADD COLUMN IF NOT EXISTS `staff_role` VARCHAR(100) NULL AFTER `role`;
ALTER TABLE `taxi_admins` ADD COLUMN IF NOT EXISTS `is_etelgroup_managed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `staff_role`;

CREATE TABLE IF NOT EXISTS `taxi_role_permissions` (
  `id`           INT(11)      NOT NULL AUTO_INCREMENT,
  `role_name`    VARCHAR(100) NOT NULL,
  `capabilities` TEXT         NOT NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_taxi_role_name` (`role_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `taxi_role_permissions` (`role_name`, `capabilities`) VALUES
  ('Owner',         '["*"]'),
  ('Admin',         '["*"]'),
  ('Manager',       '["*"]'),
  ('Dispatcher',    '["drivers.view","drivers.manage","associations.view","payments.view"]'),
  ('Support',       '["drivers.view","associations.view","payments.view"]'),
  ('Finance',       '["payments.view","payments.manage","finance.view","finance.manage"]'),
  ('Driver Support','["drivers.view","drivers.manage"]');
