-- Migration 047: Growth & Goal Planner - persisted assumptions
-- Previously the Growth & Goal Planner's inputs (registered drivers,
-- trips/driver/day, avg distance, fare, wait time, surge) were only
-- ever hardcoded template defaults (20 drivers, 12 trips, etc.) baked
-- directly into admin-platform-economics.js's rendered HTML on every
-- load - nothing was ever actually saved anywhere. Within one already-
-- open admin session, typed values did survive switching between the
-- Finance & Planning sub-tabs (that's a separate, already-working fix
-- - see admin-finance-hub.js's own comments) - but nothing survived a
-- page reload, and none of Equity/Payroll/Business Plan could read the
-- same scenario the founder had just calculated, since it never left
-- Platform Economics' own in-memory JS state.
-- Single-row settings table, same shape as platform_cost_assumptions
-- from migration 037 - one saved scenario at a time, not a history.

CREATE TABLE IF NOT EXISTS `growth_goal_assumptions` (
  `id`                INT(11) NOT NULL DEFAULT 1,
  `registered_drivers` DECIMAL(10,2) NOT NULL DEFAULT 20.00,
  `trips_per_driver_day` DECIMAL(10,2) NOT NULL DEFAULT 12.00,
  `avg_distance_km`   DECIMAL(10,2) NOT NULL DEFAULT 5.00,
  `base_fare`         DECIMAL(10,2) NOT NULL DEFAULT 120.00,
  `per_km_rate`       DECIMAL(10,2) NOT NULL DEFAULT 30.00,
  `avg_wait_min`      DECIMAL(10,2) NOT NULL DEFAULT 2.00,
  `wait_rate`         DECIMAL(10,2) NOT NULL DEFAULT 3.00,
  `surge_share_pct`   DECIMAL(6,2)  NOT NULL DEFAULT 25.00,
  `surge_multiplier`  DECIMAL(6,3)  NOT NULL DEFAULT 1.200,
  `updated_by`        INT(11) NULL,
  `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `growth_goal_assumptions` (`id`) VALUES (1);
