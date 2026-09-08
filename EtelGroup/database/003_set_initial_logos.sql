-- ============================================================
-- Etel Group super-page — set initial company logos
-- Run once against the database named in .env (DB_NAME), same way
-- as 002_companies.sql (manual run via phpMyAdmin - EtelGroup has
-- no automated migration runner like Delivery's api/migrate.php).
--
-- companies.logo_path already existed in the schema and the admin
-- panel's Companies form already had a working "Logo path" field
-- (js/admin.js + AdminController.php both already read/write it) -
-- the actual gap was that js/companies.js, which builds the public
-- card grid, never rendered it. That's fixed separately in
-- js/companies.js; this file just sets the three logos already on
-- hand as a starting point. Any company's logo can be changed at any
-- time from Admin -> Companies -> Logo path, no code change needed.
-- ============================================================

UPDATE companies SET logo_path = 'https://ethelmarket.com/delivery/icons/icon-192.png' WHERE slug = 'etel-delivery';

UPDATE companies SET logo_path = 'https://ethelmarket.com/EtelAi/icons/icon-192.png' WHERE slug = 'etel-ai';

-- Etel ID doesn't have its own distinct logo yet, so it uses the Etel
-- Group crest itself (per instruction) - upload
-- etelgroup-logo.png (included alongside this file) to
-- EtelGroup/icons/etelgroup-logo.png first, then run this.
UPDATE companies SET logo_path = 'https://ethelmarket.com/EtelGroup/icons/etelgroup-logo.png' WHERE slug = 'etel-id';
