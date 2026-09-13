-- Migration 014: admin-editable bank transfer accounts
--
-- customer.js hardcoded "Account No: 1234567890" (Abysinia) and
-- "9876543210" (CBE) directly in the bank-transfer checkout UI - if those
-- were ever left as placeholders rather than real account numbers, every
-- customer choosing bank transfer was being shown a fake account to wire
-- real money to, with no way to fix it short of a code deploy. This moves
-- both accounts into an admin-editable table (same DB-first pattern as
-- delivery_zones/delivery_tiers/ride_fare_rules), seeded with the exact
-- values that were previously hardcoded so behavior is unchanged until an
-- admin actually edits them via the Pricing panel.
--
-- NOTE ON FORMATTING: every comment on its own line, every
-- statement-terminating `;` last on its line, so api/migrate.php's naive
-- line-based statement splitter parses this correctly.

CREATE TABLE IF NOT EXISTS `bank_accounts` (
    `id`             INT(11)      NOT NULL AUTO_INCREMENT,
    `bank_key`       VARCHAR(20)  NOT NULL,
    `display_name`   VARCHAR(60)  NOT NULL,
    `account_name`   VARCHAR(120) NOT NULL DEFAULT 'ETEL Hawassa',
    `account_number` VARCHAR(40)  NOT NULL DEFAULT '',
    `active`         TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_bank_key` (`bank_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `bank_accounts` (bank_key, display_name, account_name, account_number, active) VALUES
    ('abysinia', 'Abysinia Bank Transfer', 'ETEL Hawassa', '1234567890', 1),
    ('cbe',      'CBE Transfer',           'ETEL Hawassa', '9876543210', 1)
ON DUPLICATE KEY UPDATE
    bank_key = bank_key;
