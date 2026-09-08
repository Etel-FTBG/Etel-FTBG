-- ═══════════════════════════════════════════════════════════════════
-- MIGRATION: Add EtelID SSO support to controllers and admin_users
-- Run this once on the existing database (e.g. via phpMyAdmin) before
-- deploying the "Sign in with EtelID" login option.
--
-- staff_role / is_etelgroup_managed mirror the same pattern already
-- used by Delivery & Ride's own EtelID bridge (see api/etelid_bridge.php
-- there): is_etelgroup_managed is set the moment an account is first
-- created or adopted via EtelID sign-in, so a later login can tell a
-- true legacy local account (never touched, stays untouched forever)
-- apart from one that genuinely lost its EtelGroup role assignment
-- (blocked, since it was already known to be centrally managed).
-- admin_users had no phone column at all - EtelID matching is by
-- phone number, so one is required to link an admin account to an
-- EtelID member. controllers already had phone but no index on it.
-- ═══════════════════════════════════════════════════════════════════

ALTER TABLE controllers ADD COLUMN staff_role VARCHAR(60) NULL AFTER phone;
ALTER TABLE controllers ADD COLUMN is_etelgroup_managed TINYINT(1) NOT NULL DEFAULT 0 AFTER staff_role;
ALTER TABLE controllers ADD INDEX idx_phone (phone);

ALTER TABLE admin_users ADD COLUMN phone VARCHAR(20) NULL AFTER email;
ALTER TABLE admin_users ADD COLUMN staff_role VARCHAR(60) NULL AFTER phone;
ALTER TABLE admin_users ADD COLUMN is_etelgroup_managed TINYINT(1) NOT NULL DEFAULT 0 AFTER staff_role;
ALTER TABLE admin_users ADD INDEX idx_phone (phone);
