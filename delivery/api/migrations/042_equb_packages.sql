-- ============================================================
-- Equb Packages - admin-curated tiers users select from
-- ============================================================
-- Admin still controls everything that matters (which contribution
-- amounts/terms exist at all) - users pick from this approved menu
-- rather than inventing arbitrary group terms themselves. Selecting a
-- package either joins an existing forming group spun up from that
-- package, or auto-creates a new one from the template if none has
-- room yet - so users get instant self-service without needing an
-- admin to hand-create every single group instance.
CREATE TABLE IF NOT EXISTS `equb_packages` (
  `id`                    INT(11)        NOT NULL AUTO_INCREMENT,
  `name`                  VARCHAR(120)   NOT NULL,
  `tier`                  ENUM('bronze','silver','gold','platinum') NOT NULL DEFAULT 'bronze',
  `description`           VARCHAR(255)   NULL,
  `contribution_amount`   DECIMAL(10,2)  NOT NULL,
  `frequency`             ENUM('weekly','biweekly','monthly') NOT NULL DEFAULT 'weekly',
  `max_members`           INT(11)        NOT NULL,
  `payout_order`          ENUM('lottery','join_order') NOT NULL DEFAULT 'lottery',
  `active`                TINYINT(1)     NOT NULL DEFAULT 1,
  `created_by`            INT(11)        NOT NULL,
  `created_at`            DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Links a group back to the package it was spun up from, if any -
-- NULL for groups the admin created directly outside the package flow.
ALTER TABLE `equb_groups` ADD COLUMN `package_id` INT(11) NULL AFTER `id`;
