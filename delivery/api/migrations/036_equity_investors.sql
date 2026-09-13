-- ============================================================
-- Etel Group — Investor & Equity Control Center
-- Backend for the investor cap table, round settings, and profit
-- distribution tool, gated behind the existing require_admin()
-- capability system (equity.view / equity.manage) so this real
-- financial data is never reachable by a generic 'admin' role
-- without an explicit grant in the Roles panel.
-- ============================================================

CREATE TABLE IF NOT EXISTS `equity_round_settings` (
  `id`                  INT(11)        NOT NULL DEFAULT 1,
  `target_amount`       DECIMAL(14,2)  NOT NULL DEFAULT 500000.00,
  `investor_equity_pct` DECIMAL(6,3)   NOT NULL DEFAULT 20.000,
  `total_shares`        INT(11)        NOT NULL DEFAULT 10000,
  `updated_by`          INT(11)        NULL,
  `updated_at`          DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Singleton row - the settings form always reads/writes id=1.
INSERT IGNORE INTO `equity_round_settings` (`id`, `target_amount`, `investor_equity_pct`, `total_shares`)
VALUES (1, 500000.00, 20.000, 10000);

CREATE TABLE IF NOT EXISTS `equity_investors` (
  `id`                INT(11)        NOT NULL AUTO_INCREMENT,
  `investor_code`     VARCHAR(40)    NOT NULL,
  `name`              VARCHAR(150)   NOT NULL,
  `contact`           VARCHAR(150)   NULL,
  `amount_invested`   DECIMAL(14,2)  NOT NULL,
  `payment_status`    ENUM('unpaid','pending','paid') NOT NULL DEFAULT 'pending',
  `agreement_status`  ENUM('draft','sent','signed') NOT NULL DEFAULT 'draft',
  `receipt_number`    VARCHAR(60)    NULL,
  `invested_at`       DATE           NULL,
  `notes`             TEXT           NULL,
  `created_by`        INT(11)        NULL,
  `created_at`        DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_investor_code` (`investor_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per profit-distribution run. Line items are stored as a
-- point-in-time snapshot (equity_profit_distribution_items) rather
-- than recomputed live from equity_investors, since an investor's
-- ownership % can change after the fact (new investors join, amounts
-- get corrected) - a past distribution's numbers must never silently
-- change because of something entered later.
CREATE TABLE IF NOT EXISTS `equity_profit_distributions` (
  `id`               INT(11)        NOT NULL AUTO_INCREMENT,
  `profit_amount`    DECIMAL(14,2)  NOT NULL,
  `investor_pool`    DECIMAL(14,2)  NOT NULL,
  `founder_pool`     DECIMAL(14,2)  NOT NULL,
  `company_amount`   DECIMAL(14,2)  NOT NULL,
  `charity_amount`   DECIMAL(14,2)  NOT NULL,
  `personal_amount`  DECIMAL(14,2)  NOT NULL,
  `note`             VARCHAR(255)   NULL,
  `created_by`       INT(11)        NULL,
  `created_at`       DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `equity_profit_distribution_items` (
  `id`                INT(11)        NOT NULL AUTO_INCREMENT,
  `distribution_id`   INT(11)        NOT NULL,
  `investor_id`        INT(11)        NULL,
  `investor_name`     VARCHAR(150)   NOT NULL,
  `amount_invested`   DECIMAL(14,2)  NOT NULL,
  `ownership_pct`     DECIMAL(8,4)   NOT NULL,
  `profit_share`      DECIMAL(14,2)  NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_epdi_distribution` (`distribution_id`),
  CONSTRAINT `fk_epdi_distribution` FOREIGN KEY (`distribution_id`) REFERENCES `equity_profit_distributions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- No new role_permissions rows needed here: 'equity.view' / 'equity.manage'
-- are plain capability strings checked by the existing require_admin($pdo,
-- $capability) helper (see boot.php). Owner/Admin already carry '*' from
-- migration 018 and are unaffected. To grant a non-Owner role (e.g.
-- Finance) access to this tab, add 'equity.view' to its capabilities
-- list from the existing Roles & Permissions admin panel - no schema
-- change needed for that.
