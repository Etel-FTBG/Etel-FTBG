-- Migration 060: user_display_prefs - per-account display size settings
-- (text / icon / button / logo size and boldness) so a person's choice
-- follows them across every device they sign in on.
--
-- NOTE ON FORMATTING: same convention as prior migrations - every
-- comment on its own line, every statement-terminating `;` last on its
-- line, so api/migrate.php's naive line-based statement splitter parses
-- this correctly. DisplayPrefsController.php also creates this table on
-- first use, so the code and this migration can land in either order.

CREATE TABLE IF NOT EXISTS `user_display_prefs` (
  `user_id`    INT(11)      NOT NULL,
  `prefs_json` VARCHAR(255) NOT NULL,
  `version`    INT(11)      NOT NULL DEFAULT 1,
  `updated_at` DATETIME     NOT NULL,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
