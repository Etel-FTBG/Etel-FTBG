-- Migration 016: service giver staff (Owner / Cashier roles)
--
-- Adds a cashier login tier under a service giver business. The account
-- that registered the business (service_givers.user_id) is always the
-- Owner. Rows in sg_staff are additional logins (own phone + password,
-- via the normal login action, same 'service_giver' role at the auth
-- level) scoped to that business as Cashier - see sg_get_context() in
-- ServiceGiverController.php for how a request resolves owner vs cashier.
--
-- NOTE ON FORMATTING: every comment on its own line, every
-- statement-terminating `;` last on its line, so api/migrate.php's naive
-- line-based statement splitter parses this correctly.

CREATE TABLE IF NOT EXISTS `sg_staff` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `service_giver_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `staff_role` VARCHAR(20) NOT NULL DEFAULT 'cashier',
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_sg_staff_user` (`user_id`),
  KEY `idx_sg_staff_business` (`service_giver_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
