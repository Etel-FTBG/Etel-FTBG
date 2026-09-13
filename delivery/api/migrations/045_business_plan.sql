-- ============================================================
-- Business Plan & Capital Requirement Planning
-- ============================================================
-- Lets the founder save a real hiring plan (persists across
-- sessions, unlike chat-based advice) and free-text planning notes.
-- The capital requirement calculator itself reuses REAL data already
-- live elsewhere (platform_cost_assumptions, equity_round_settings,
-- the payroll tax bracket math already in admin-payroll.js) rather
-- than duplicating those numbers - only the hire list and notes are
-- new persisted data.

CREATE TABLE IF NOT EXISTS `business_hires_plan` (
  `id`               INT(11)        NOT NULL AUTO_INCREMENT,
  `role_name`        VARCHAR(150)   NOT NULL,
  `monthly_salary`   DECIMAL(12,2)  NOT NULL,
  `headcount`        INT(11)        NOT NULL DEFAULT 1,
  `notes`            VARCHAR(255)   NULL,
  `sort_order`        INT(11)        NOT NULL DEFAULT 0,
  `created_by`       INT(11)        NULL,
  `created_at`       DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Singleton free-text notes area - for anything that doesn't fit the
-- structured hire list (the hiring roadmap narrative, reminders,
-- links, whatever the founder wants to keep saved on the admin page
-- instead of losing it in old chat history).
CREATE TABLE IF NOT EXISTS `business_plan_notes` (
  `id`           INT(11)   NOT NULL DEFAULT 1,
  `content`      LONGTEXT  NULL,
  `updated_by`   INT(11)   NULL,
  `updated_at`   DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `business_plan_notes` (`id`, `content`) VALUES (1, '');
