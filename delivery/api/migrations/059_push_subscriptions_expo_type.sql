-- Migration 059: allow Expo push tokens alongside existing Web Push
-- subscriptions in push_subscriptions. The native ETEL Drive app (React
-- Native/Expo) has no Web Push API - it registers an Expo push token
-- instead, which Expo's own service forwards to FCM. Reuses the existing
-- row shape rather than a second table: `endpoint` stores the Expo push
-- token itself (endpoint_hash = hash of it, same one-row-per-install
-- semantics as Web Push), and p256dh/auth are left NULL for these rows
-- since Expo push isn't end-to-end encrypted the way Web Push is.
--
-- NOTE ON FORMATTING: same convention as prior migrations - every
-- comment on its own line, every statement-terminating `;` last on its
-- line, so api/migrate.php's naive line-based statement splitter parses
-- this correctly.

ALTER TABLE `push_subscriptions`
  ADD COLUMN `type` VARCHAR(20) NOT NULL DEFAULT 'webpush' AFTER `user_id`;

ALTER TABLE `push_subscriptions`
  MODIFY COLUMN `p256dh` VARCHAR(255) NULL DEFAULT NULL;

ALTER TABLE `push_subscriptions`
  MODIFY COLUMN `auth` VARCHAR(255) NULL DEFAULT NULL;
