-- ============================================================
-- Etel Group super-page — companies table
-- Powers the dynamic company grid, launcher grid, nav dropdown, and
-- mobile nav on the public site (previously hardcoded in index.html),
-- plus the admin Companies panel to manage them.
-- Run once against the database named in .env (DB_NAME).
-- ============================================================

CREATE TABLE IF NOT EXISTS companies (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug             VARCHAR(60)  NOT NULL,
    name             VARCHAR(100) NOT NULL,
    tagline          VARCHAR(100) NOT NULL DEFAULT '',
    tagline_am       VARCHAR(100) NOT NULL DEFAULT '',
    description_short VARCHAR(300) NOT NULL DEFAULT '',
    description_long  VARCHAR(500) NOT NULL DEFAULT '',
    url              VARCHAR(255) NOT NULL DEFAULT '',
    logo_path        VARCHAR(255) NULL,
    icon_key         VARCHAR(30)  NOT NULL DEFAULT 'generic',
    dot_color        VARCHAR(20)  NOT NULL DEFAULT '#D4AF37',
    tags             VARCHAR(200) NOT NULL DEFAULT '',
    status           ENUM('live','coming_soon') NOT NULL DEFAULT 'coming_soon',
    sort_order       INT NOT NULL DEFAULT 0,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_slug (slug),
    KEY idx_status (status),
    KEY idx_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed with the companies already live on the site today, in their
-- existing display order and colors, so switching to dynamic
-- rendering is a no-visible-change deploy.
INSERT INTO companies (slug, name, tagline, tagline_am, description_short, description_long, url, icon_key, dot_color, tags, status, sort_order) VALUES
('etel-madeya', 'Etel Madeya', 'Gas & Fuel', 'ጋዝ እና ነዳጅ', 'Gas and fuel delivery and cylinder exchange.', 'Gas and fuel delivery with cylinder exchange, tracked orders, and same-day service.', 'https://ethelmarket.com/etel.madeya/', 'fuel', '#C8922A', 'Delivery,Cylinders', 'live', 10),
('etel-market', 'Etel Market', 'E-commerce', 'ገበያ', 'General e-commerce marketplace.', 'General e-commerce marketplace for everyday goods across the region.', 'https://ethelmarket.com/EtelGebeya/', 'cart', '#3b82f6', 'Marketplace,Shopping', 'live', 20),
('etel-delivery', 'Etel Delivery', 'Logistics', 'ማድረሻ', 'Package and food delivery logistics.', 'Package and food delivery logistics with live tracking for drivers and customers.', 'https://ethelmarket.com/delivery/', 'truck', '#22c55e', 'Logistics,Tracking', 'live', 30),
('etel-pay', 'Etel Pay', 'Fintech', 'ክፍያ', 'Digital wallet and payments.', 'Digital wallet, merchant payments, and transfers across the Etel Group ecosystem.', 'https://ethelmarket.com/Etel.Pay/', 'wallet', '#f59e0b', 'Wallet,Payments', 'live', 40),
('etel-ai', 'Etel AI', 'Artificial Intelligence', 'ሰው ሠራሽ አስተውሎት', 'Cross-company AI assistant.', 'Cross-company AI assistant embedded across every Etel Group product.', 'https://ethelmarket.com/EtelAi/', 'ai', '#D4AF37', 'AI,Assistant', 'live', 50),
('etel-id', 'Etel ID', 'Digital Identity', 'ዲጂታል መታወቂያ', 'One identity across Etel Group.', 'One digital identity for every Etel Group service - sign in once, use everywhere.', 'https://ethelmarket.com/EtelId/', 'id', '#14b8a6', 'Identity,SSO', 'live', 60),
('etel-transport', 'Etel Transport', 'Taxi Permits', 'ታክሲ ፈቃድ', 'Association permits & monthly dues, digitized.', 'Association permits and monthly dues, digitized. QR-based permit verification for traffic officers, plus driver and association dashboards.', 'https://ethelmarket.com/EtelTaxi/', 'taxi', '#a855f7', 'Permits,QR Verify', 'live', 70),
('etel-laundry', 'Etel Laundry', 'Coming Soon', 'በቅርቡ', 'On-demand laundry and dry cleaning.', 'On-demand laundry and dry cleaning pickup, with per-item tracking.', '', 'laundry', '#06b6d4', 'Laundry', 'coming_soon', 80),
('etel-media', 'Etel Media', 'Coming Soon', 'በቅርቡ', 'Media and content production.', 'Media and content production across the Etel Group brand family.', '', 'media', '#ec4899', 'Media,Content', 'coming_soon', 90),
('etel-luxline', 'Etel LuxLine', 'Coming Soon', 'በቅርቡ', 'Premium transport and hospitality.', 'Premium transport and hospitality services under the Etel Group name.', '', 'luxline', '#ef4444', 'Premium,Hospitality', 'coming_soon', 100)
ON DUPLICATE KEY UPDATE slug = slug;
