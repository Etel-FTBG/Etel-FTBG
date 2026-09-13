-- Migration 035: launch_checklist_state - persistence for the Admin
-- panel's new Launch Readiness tab. Only stores which checklist item_keys
-- are checked (and by whom, when) - the checklist items themselves are
-- static content in js/admin-launch-checklist.js, not database rows.

CREATE TABLE IF NOT EXISTS `launch_checklist_state` (
    `item_key`   VARCHAR(100) NOT NULL,
    `checked`    TINYINT(1) NOT NULL DEFAULT 0,
    `checked_at` DATETIME DEFAULT NULL,
    `checked_by` INT DEFAULT NULL,
    PRIMARY KEY (`item_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
