-- Community feedback: let any visitor mark each *submission* (one person's
-- full snapshot of datorii + asset-uri) as plausible or suspicious. Two
-- counters are denormalized onto `submissions` so the admin can cheaply
-- sort by "most flagged"; a per-voter row table backs the 24h cooldown on
-- vote changes and lets us recover the exact tally if a counter ever
-- drifts.
--
-- Existing submissions default to (0, 0) -- no community signal yet, so
-- this migration is safe to apply on prod without touching any aggregate.
-- The admin's manual `entries.status` flag remains the authoritative gate
-- for whether an entry counts in user statistics; community voting is
-- advisory only.

ALTER TABLE submissions
  ADD COLUMN community_true_count  INT NOT NULL DEFAULT 0,
  ADD COLUMN community_false_count INT NOT NULL DEFAULT 0;

CREATE INDEX idx_submissions_community_false ON submissions(community_false_count);

CREATE TABLE IF NOT EXISTS community_votes (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    submission_id INT NOT NULL,
    voter_uuid    VARCHAR(64) NOT NULL,
    vote          TINYINT(1) NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_submission_voter (submission_id, voter_uuid),
    KEY idx_community_votes_submission (submission_id),
    KEY idx_community_votes_voter (voter_uuid),
    CONSTRAINT fk_community_votes_submission
        FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
