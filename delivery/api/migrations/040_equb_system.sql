-- ============================================================
-- Digital Equb (Ethiopian rotating savings & credit association)
-- Real money moves automatically through the existing wallet system
-- (user_wallets / wallet_transactions) - every group is designed so
-- a payout can NEVER happen until every active member's contribution
-- for that round has actually cleared. No partial/best-effort payouts.
-- ============================================================

CREATE TABLE IF NOT EXISTS `equb_groups` (
  `id`                      INT(11)        NOT NULL AUTO_INCREMENT,
  `name`                    VARCHAR(120)   NOT NULL,
  `created_by`              INT(11)        NOT NULL,
  `contribution_amount`     DECIMAL(10,2)  NOT NULL,
  `frequency`               ENUM('weekly','biweekly','monthly') NOT NULL DEFAULT 'weekly',
  `max_members`             INT(11)        NOT NULL,
  `payout_order`            ENUM('lottery','join_order') NOT NULL DEFAULT 'lottery',
  `status`                  ENUM('forming','active','at_risk','completed','cancelled') NOT NULL DEFAULT 'forming',
  `current_round`           INT(11)        NOT NULL DEFAULT 0,
  `total_rounds`            INT(11)        NOT NULL DEFAULT 0,
  `next_contribution_date`  DATE           NULL,
  `grace_days_used`         INT(11)        NOT NULL DEFAULT 0,
  `created_at`              DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `started_at`              DATETIME       NULL,
  PRIMARY KEY (`id`),
  KEY `idx_equb_groups_status_date` (`status`, `next_contribution_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `equb_members` (
  `id`            INT(11)   NOT NULL AUTO_INCREMENT,
  `group_id`      INT(11)   NOT NULL,
  `user_id`       INT(11)   NOT NULL,
  `join_order`    INT(11)   NOT NULL,
  `payout_round`  INT(11)   NULL,
  `status`        ENUM('active','left','defaulted') NOT NULL DEFAULT 'active',
  `joined_at`     DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_equb_member_group_user` (`group_id`, `user_id`),
  KEY `idx_equb_members_group` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `equb_contributions` (
  `id`                     INT(11)        NOT NULL AUTO_INCREMENT,
  `group_id`               INT(11)        NOT NULL,
  `member_id`              INT(11)        NOT NULL,
  `round_number`           INT(11)        NOT NULL,
  `amount`                 DECIMAL(10,2)  NOT NULL,
  `status`                 ENUM('pending','paid','missed') NOT NULL DEFAULT 'pending',
  `wallet_transaction_id`  INT(11)        NULL,
  `paid_at`                DATETIME       NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_equb_contrib_round_member` (`group_id`, `round_number`, `member_id`),
  KEY `idx_equb_contrib_group_round` (`group_id`, `round_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `equb_payouts` (
  `id`                     INT(11)        NOT NULL AUTO_INCREMENT,
  `group_id`               INT(11)        NOT NULL,
  `round_number`           INT(11)        NOT NULL,
  `recipient_member_id`    INT(11)        NOT NULL,
  `amount`                 DECIMAL(10,2)  NOT NULL,
  `wallet_transaction_id`  INT(11)        NULL,
  `paid_at`                DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_equb_payout_round` (`group_id`, `round_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
