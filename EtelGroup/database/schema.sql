-- ============================================================
-- Etel Group super-page — database schema
-- Run this once against the database named in .env (DB_NAME)
-- ============================================================

CREATE TABLE IF NOT EXISTS contact_messages (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(150)    NOT NULL,
    email       VARCHAR(190)    NOT NULL,
    subject     VARCHAR(120)    NULL,
    company     VARCHAR(150)    NULL,
    message     TEXT            NOT NULL,
    ip          VARCHAR(45)     NULL,
    status      ENUM('new','read','replied') NOT NULL DEFAULT 'new',
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_status (status),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
