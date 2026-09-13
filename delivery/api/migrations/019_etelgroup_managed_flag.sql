-- ============================================================
-- Delivery - fix ambiguous "legacy vs EtelGroup-managed" detection
-- The previous approach (treating an empty staff_role as "this must
-- be an untouchable legacy admin") breaks for any account that was
-- auto-created by an EARLIER version of the EtelGroup bridge, before
-- staff_role tracking existed - that account is genuinely managed,
-- but looks identical to a true legacy account once staff_role is
-- empty. This column removes the ambiguity: it's set once, the
-- moment the bridge first creates or promotes an account, and never
-- means anything else.
-- ============================================================

ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `is_etelgroup_managed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `staff_role`;
