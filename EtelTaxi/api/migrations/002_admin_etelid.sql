-- ============================================================
-- ETEL TAXI - Add etel_id to taxi_admins  v1.0
-- Lets an admin sign in with EtelID, linked by matching phone number
-- to an existing admin account on first use (see admin_etelid_login) -
-- does NOT let a new EtelID holder become an admin on their own; an
-- admin account must already exist, created deliberately by a super
-- admin (admin_register/seed-admin.php), same security boundary as
-- before this migration.
-- ============================================================

ALTER TABLE taxi_admins ADD COLUMN etel_id VARCHAR(50) NULL AFTER phone;
