-- ═══════════════════════════════════════════════════════════════════
-- MIGRATION: Add batch_id to queue table
-- Run this on existing databases to support batch-based pagination
-- ═══════════════════════════════════════════════════════════════════

ALTER TABLE queue ADD COLUMN batch_id INT UNSIGNED DEFAULT 0 AFTER priority_tier;
ALTER TABLE queue ADD INDEX idx_batch (batch_id);

-- Set existing records to batch 1 so they group together on page 1
UPDATE queue SET batch_id = 1 WHERE batch_id = 0 AND status = 'queued';
