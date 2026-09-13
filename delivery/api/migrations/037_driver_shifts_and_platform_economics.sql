-- ============================================================
-- Driver shift tracking + platform cost assumptions
-- ============================================================

-- Nothing previously tracked how long a driver was actually online -
-- driver_go_online/driver_go_offline only flipped a boolean. Without
-- a real duration, "net ETB per working hour" (the KPI the driver
-- playbook calls the most important one) was impossible to compute
-- honestly - it would have had to be guessed. This table logs real
-- shift start/end so that KPI is a real number from now on.
CREATE TABLE IF NOT EXISTS `driver_shifts` (
  `id`               INT(11)   NOT NULL AUTO_INCREMENT,
  `driver_user_id`   INT(11)   NOT NULL,
  `started_at`       DATETIME  NOT NULL,
  `ended_at`         DATETIME  NULL,
  `online_seconds`   INT(11)   UNSIGNED NULL,
  PRIMARY KEY (`id`),
  KEY `idx_driver_shifts_driver_started` (`driver_user_id`, `started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Singleton settings row the founder edits in the admin Platform
-- Economics tool - these are real business cost inputs, not
-- estimates this system can know on its own.
CREATE TABLE IF NOT EXISTS `platform_cost_assumptions` (
  `id`                            INT(11) NOT NULL DEFAULT 1,
  `payment_processing_pct`        DECIMAL(6,3) NOT NULL DEFAULT 0.000,
  `monthly_customer_acquisition`  DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `monthly_hosting_cost`          DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `monthly_support_cost`          DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `monthly_incentive_budget`      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `monthly_refunds_estimate`      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `monthly_admin_overhead`        DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `updated_by`                    INT(11) NULL,
  `updated_at`                    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `platform_cost_assumptions` (`id`) VALUES (1);
