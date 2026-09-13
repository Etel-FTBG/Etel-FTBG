-- ============================================================
-- Equb participant permissions - admin-controlled access
-- ============================================================
-- Mirrors the investor EtelID-link model: being permitted to use
-- Equb at all is a deliberate admin action (by phone lookup), not
-- something any EtelID holder gets automatically. Once permitted, a
-- person can freely create/join/browse groups among other permitted
-- users - the gate is at "can you use Equb at all", not per-group.
CREATE TABLE IF NOT EXISTS `equb_participants` (
  `id`            INT(11)   NOT NULL AUTO_INCREMENT,
  `user_id`       INT(11)   NOT NULL,
  `status`        ENUM('permitted','revoked') NOT NULL DEFAULT 'permitted',
  `permitted_by`  INT(11)   NULL,
  `permitted_at`  DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_equb_participant_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
