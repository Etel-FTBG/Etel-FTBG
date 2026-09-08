-- ============================================================
-- EtelGebeya - granular role-based permissions
-- Adds the same is_etelgroup_managed tracking that Delivery and
-- EtelTaxi now have (without it, an account auto-created by an older
-- version of the bridge is indistinguishable from a true independently-
-- created admin once its role is removed - see the Delivery incident
-- this pattern was built to prevent). role_permissions defines what
-- each role name (already stored in gebeya_admins.role) can do.
-- ============================================================

ALTER TABLE `gebeya_admins` ADD COLUMN IF NOT EXISTS `is_etelgroup_managed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `role`;

CREATE TABLE IF NOT EXISTS `gebeya_role_permissions` (
  `id`           INT(11)      NOT NULL AUTO_INCREMENT,
  `role_name`    VARCHAR(100) NOT NULL,
  `capabilities` TEXT         NOT NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_gebeya_role_name` (`role_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `gebeya_role_permissions` (`role_name`, `capabilities`) VALUES
  ('Owner',         '["*"]'),
  ('Admin',         '["*"]'),
  ('Manager',       '["*"]'),
  ('Dispatcher',    '["orders.view","orders.manage","vendors.view"]'),
  ('Support',       '["orders.view","support.view","support.manage","users.view"]'),
  ('Finance',       '["finance.view","finance.manage","analytics.view"]'),
  ('Driver Support','["vendors.view","vendors.manage"]');
