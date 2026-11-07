-- ETEL Field Agents - partner onboarding (an agent registers a courier, a driver or a shop for the owner to approve).
-- Fully additive and idempotent: only CREATE TABLE IF NOT EXISTS.
-- The agent's submission is kept here as staged data. Nothing becomes a real account until the owner approves it.

CREATE TABLE IF NOT EXISTS `fa_partners` (
  `id`            INT(11)      NOT NULL AUTO_INCREMENT,
  `agent_id`      INT(11)      NOT NULL,
  `kind`          ENUM('courier','driver','shop') NOT NULL,
  `status`        ENUM('draft','submitted','changes','approved','rejected') NOT NULL DEFAULT 'draft',
  `name`          VARCHAR(120) NOT NULL DEFAULT '',
  `phone`         VARCHAR(20)  NOT NULL DEFAULT '',
  `phone_last9`   VARCHAR(9)   NOT NULL DEFAULT '',
  `id_number`     VARCHAR(60)  NOT NULL DEFAULT '',
  `data`          MEDIUMTEXT,
  `items`         MEDIUMTEXT,
  `item_count`    INT(11)      NOT NULL DEFAULT 0,
  `user_id`       INT(11)      DEFAULT NULL,
  `sg_id`         INT(11)      DEFAULT NULL,
  `risk_score`    TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
  `risk_flags`    TEXT,
  `fee_amount`    DECIMAL(10,2) NOT NULL DEFAULT 0,
  `earning_id`    INT(11)      DEFAULT NULL,
  `decision_note` VARCHAR(255) NOT NULL DEFAULT '',
  `decided_by`    INT(11)      DEFAULT NULL,
  `decided_at`    DATETIME     DEFAULT NULL,
  `submitted_at`  DATETIME     DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL,
  `updated_at`    DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_fap_agent` (`agent_id`, `status`),
  KEY `idx_fap_status` (`status`, `submitted_at`),
  KEY `idx_fap_phone` (`phone_last9`),
  KEY `idx_fap_idnum` (`id_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
