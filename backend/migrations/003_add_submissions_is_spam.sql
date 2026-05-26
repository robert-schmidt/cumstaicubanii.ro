-- Manual spam flag set from the admin panel. When 1, the submission is
-- excluded from every aggregate query and the user can no longer see their
-- own dashboard, but the row REMAINS visible in admin (unlike deleted_at)
-- so the team can track spam patterns.
--
-- Existing rows default to 0 (not spam) — admin opt-in only.

ALTER TABLE submissions
  ADD COLUMN is_spam TINYINT(1) NOT NULL DEFAULT 0;

CREATE INDEX idx_submissions_is_spam ON submissions(is_spam);
