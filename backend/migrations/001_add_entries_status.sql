-- Add a status column on entries for moderation/outlier flagging.
-- 1 = approved (counted in aggregates), 0 = flagged (NOT counted).
-- Existing rows default to 1 (approved) — they were accepted under the old rules.

ALTER TABLE entries
  ADD COLUMN status TINYINT(1) NOT NULL DEFAULT 1;

CREATE INDEX idx_entries_status ON entries(status);
