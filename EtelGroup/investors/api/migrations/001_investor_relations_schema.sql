-- ============================================================
-- Etel Delivery & Ride — Investor Relations module
-- Lives in EtelGroup's own database (per Etel Group convention: each
-- app has its own DB on the shared MySQL server). NOTHING here touches
-- Delivery & Ride's database or any existing EtelGroup table - every
-- table below is new, prefixed ir_ (investor relations) so it can
-- never collide with EtelGroup's existing companies/contact_messages/
-- admin_activity_log/etc tables.
--
-- Run this once against EtelGroup's own database (the same one
-- EtelGroup/php/config.php's eg_db() already connects to - see
-- DB_HOST/DB_NAME/DB_USER/DB_PASS in EtelGroup/.env). Every table also
-- self-heals via CREATE TABLE IF NOT EXISTS in the PHP layer, same
-- convention as EtelGroup's own companies table, so a fresh install
-- works even if this file is never manually run - but running it once
-- is still the documented, reviewable way to provision a new install.
-- ============================================================

-- Everything the setup wizard configures: commission rate, the
-- read-only Delivery & Ride DB connection (password stored encrypted,
-- see ir_crypto_encrypt() in api/lib/Crypto.php, keyed by INV_CRYPTO_KEY
-- in EtelGroup/.env - never plaintext at rest), demo mode flag, and any
-- other single-value settings. One row per key, so adding a new
-- setting later never needs a schema migration.
CREATE TABLE IF NOT EXISTS ir_settings (
    setting_key   VARCHAR(60) NOT NULL,
    setting_value TEXT NULL,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tranche 1 and Tranche 2 - seeded below with the fixed real numbers
-- from the equity structure. Tranche 2 stays locked (is_unlocked=0)
-- until the admin manually flips it from the milestone panel.
CREATE TABLE IF NOT EXISTS ir_tranches (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tranche_number TINYINT UNSIGNED NOT NULL,
    shares_total   INT UNSIGNED NOT NULL,
    amount_total   DECIMAL(14,2) NOT NULL,
    is_unlocked    TINYINT(1) NOT NULL DEFAULT 0,
    unlocked_at    DATETIME NULL,
    unlocked_note  VARCHAR(500) NOT NULL DEFAULT '',
    unlocked_by    VARCHAR(150) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    UNIQUE KEY uq_tranche_number (tranche_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO ir_tranches (tranche_number, shares_total, amount_total, is_unlocked)
VALUES (1, 400, 400000.00, 1), (2, 600, 600000.00, 0);

-- Itemized monthly operating cost categories - NOT a single lump sum.
-- Admin can add more categories beyond the starting four at any time;
-- nothing about the count is hardcoded anywhere in the PHP layer.
CREATE TABLE IF NOT EXISTS ir_cost_categories (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name           VARCHAR(100) NOT NULL,
    monthly_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    is_active      TINYINT(1) NOT NULL DEFAULT 1,
    sort_order     INT NOT NULL DEFAULT 0,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Full audit log of every cost-category amount change - old value, new
-- value, who, when. Never overwritten or pruned.
CREATE TABLE IF NOT EXISTS ir_cost_category_history (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_id  INT UNSIGNED NOT NULL,
    old_amount   DECIMAL(14,2) NOT NULL,
    new_amount   DECIMAL(14,2) NOT NULL,
    changed_by   VARCHAR(150) NOT NULL DEFAULT '',
    changed_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_category (category_id, changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per investor. login_identifier is what they sign in with
-- (email or phone - admin's choice at creation time); password_hash is
-- a normal PHP password_hash(), fully separate from EtelGroup's own
-- admin auth and from Delivery & Ride's user accounts. is_demo rows are
-- seeded/cleared entirely by the demo-mode toggle and never mixed into
-- real distribution math unless a demo period is explicitly being run.
CREATE TABLE IF NOT EXISTS ir_investors (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name              VARCHAR(150) NOT NULL,
    contact_email     VARCHAR(190) NOT NULL DEFAULT '',
    contact_phone     VARCHAR(30) NOT NULL DEFAULT '',
    login_identifier  VARCHAR(190) NOT NULL,
    password_hash     VARCHAR(255) NOT NULL,
    is_demo           TINYINT(1) NOT NULL DEFAULT 0,
    is_active         TINYINT(1) NOT NULL DEFAULT 1,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_login (login_identifier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Each row is one purchase lot (date, shares, tranche, amount paid).
-- Payback-phase tracking is aggregate per investor (summed across all
-- their lots), not per lot, per the spec - lots exist for a clean
-- record of what was bought when, not for FIFO-style per-lot payback.
CREATE TABLE IF NOT EXISTS ir_share_purchases (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    investor_id    INT UNSIGNED NOT NULL,
    purchase_date  DATE NOT NULL,
    shares         INT UNSIGNED NOT NULL,
    tranche_number TINYINT UNSIGNED NOT NULL,
    amount_paid    DECIMAL(14,2) NOT NULL,
    is_demo        TINYINT(1) NOT NULL DEFAULT 0,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_investor (investor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A daily cache of real Delivery & Ride trip/fare data, pulled through
-- the read-only cross-DB connection. This is a CACHE, not the source of
-- truth - it exists so the dashboard has something to render instantly
-- and so a distribution run has a stable, re-showable snapshot of what
-- it calculated from, but every refresh re-pulls from the live
-- read-only connection when available. fetched_at lets the UI show
-- "as of" staleness; is_live=0 rows should never be silently presented
-- as real - the frontend must show a "not connected" state instead.
CREATE TABLE IF NOT EXISTS ir_daily_trip_cache (
    trip_date    DATE NOT NULL,
    trip_count   INT UNSIGNED NOT NULL DEFAULT 0,
    gross_fare   DECIMAL(14,2) NOT NULL DEFAULT 0,
    fetched_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (trip_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per locked monthly (or custom-range) distribution run - the
-- immutable historical record the spec calls for. Once inserted, a
-- period's totals are never recalculated in place; a correction would
-- be a new period or a manual admin note, never an UPDATE to the math
-- columns (the PHP layer enforces this, not just convention).
CREATE TABLE IF NOT EXISTS ir_distribution_periods (
    id                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    period_start              DATE NOT NULL,
    period_end                DATE NOT NULL,
    commission_rate           DECIMAL(6,4) NOT NULL,
    gross_fare_total          DECIMAL(14,2) NOT NULL,
    commission_revenue        DECIMAL(14,2) NOT NULL,
    total_costs               DECIMAL(14,2) NOT NULL,
    net_profit                DECIMAL(14,2) NOT NULL,
    pool_entitlement          DECIMAL(14,2) NOT NULL,
    sold_shares                INT UNSIGNED NOT NULL,
    unsold_shares               INT UNSIGNED NOT NULL,
    unsold_bounce_theoretical DECIMAL(14,2) NOT NULL,
    unsold_bounce_to_investors DECIMAL(14,2) NOT NULL,
    unsold_bounce_to_founder   DECIMAL(14,2) NOT NULL,
    total_investor_payout     DECIMAL(14,2) NOT NULL,
    founder_amount            DECIMAL(14,2) NOT NULL,
    is_demo                   TINYINT(1) NOT NULL DEFAULT 0,
    locked_at                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_by                 VARCHAR(150) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY idx_period (period_start, period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-investor line item within a locked period - also immutable once
-- written. This is what both the admin's historical reports and each
-- investor's own payout-history table read from.
CREATE TABLE IF NOT EXISTS ir_distribution_investor_lines (
    id                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    period_id                 INT UNSIGNED NOT NULL,
    investor_id                INT UNSIGNED NOT NULL,
    shares_at_time             INT UNSIGNED NOT NULL,
    invested_principal_at_time DECIMAL(14,2) NOT NULL,
    cumulative_received_before DECIMAL(14,2) NOT NULL,
    payback_phase               TINYINT(1) NOT NULL,
    multiplier                  DECIMAL(4,2) NOT NULL,
    base_entitlement             DECIMAL(14,2) NOT NULL,
    bounce_share                 DECIMAL(14,2) NOT NULL,
    total_payout                 DECIMAL(14,2) NOT NULL,
    cumulative_received_after   DECIMAL(14,2) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_period (period_id),
    KEY idx_investor (investor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- General audit trail for this module - settings changes, tranche
-- unlocks, investor CRUD, distribution runs, demo seed/clear. Separate
-- from EtelGroup's own admin_activity_log so this module's history
-- stays self-contained even though both share the admin's identity.
CREATE TABLE IF NOT EXISTS ir_activity_log (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor       VARCHAR(150) NOT NULL DEFAULT '',
    action      VARCHAR(60) NOT NULL,
    detail      VARCHAR(500) NOT NULL DEFAULT '',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
