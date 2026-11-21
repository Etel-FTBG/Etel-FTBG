-- Migration 117: signed-in devices (one row per sign-in token the server has seen).
-- token_hash = sha256 of the token's fixed parts (never the token itself). A row is created the first time a token is used, so every
-- phone that is signed in today shows up without signing in again. revoked_at is set when the person (or an admin) signs that device out:
-- the token is refused from then on. Rows older than 8 days are removed (a token only lives 7 days).
-- The server also creates this table by itself on first use, so running this file is optional. Additive and idempotent.

CREATE TABLE IF NOT EXISTS `auth_sessions` (
  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
  `user_id`    INT(11)      NOT NULL,
  `token_hash` CHAR(64)     NOT NULL,
  `label`      VARCHAR(80)  NOT NULL DEFAULT '',
  `ip`         VARCHAR(45)  NOT NULL DEFAULT '',
  `created_at` DATETIME     DEFAULT NULL,
  `last_seen`  DATETIME     DEFAULT NULL,
  `revoked_at` DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token_hash` (`token_hash`),
  KEY `idx_user` (`user_id`, `revoked_at`, `last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
