-- NFC card feature: courier/customer/service-giver identity resolution.
-- Persists the link between a local Delivery `users` row and the EtelID
-- member it mirrors, so a server-to-server call from EtelID (identifying
-- someone by etel_id, e.g. after an NFC card tap) can find the matching
-- local account directly instead of only being able to match by phone
-- number at login time.

ALTER TABLE users ADD COLUMN etel_id VARCHAR(24) NULL DEFAULT NULL AFTER phone;
ALTER TABLE users ADD INDEX idx_users_etel_id (etel_id);
