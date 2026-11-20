-- Migration 115: which "your document is about to expire" reminders were already sent to which driver.
-- One row per driver, document, expiry date and stage (days left: 14, 7, 3, 1, 0, or -1 = already expired), so a reminder is sent once and never repeated.
-- When the driver renews (a new expiry date) the key is new, so reminders start again for the new date.
-- Additive and idempotent: one CREATE TABLE IF NOT EXISTS, no ALTER, no seed rows.

CREATE TABLE IF NOT EXISTS `driver_doc_reminders` (
  `driver_user_id` INT(11)     NOT NULL,
  `doc_type`       VARCHAR(30) NOT NULL,
  `expiry_date`    DATE        NOT NULL,
  `stage`          SMALLINT    NOT NULL,
  `sent_at`        DATETIME    DEFAULT NULL,
  PRIMARY KEY (`driver_user_id`, `doc_type`, `expiry_date`, `stage`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
