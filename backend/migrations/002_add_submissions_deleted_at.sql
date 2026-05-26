-- Soft-delete for submissions. When non-NULL, the submission is hidden
-- everywhere (admin list, aggregations, user lookup). Entries stay intact
-- so the row can be restored later by clearing this column.

ALTER TABLE submissions
  ADD COLUMN deleted_at DATETIME DEFAULT NULL;

CREATE INDEX idx_submissions_deleted_at ON submissions(deleted_at);
