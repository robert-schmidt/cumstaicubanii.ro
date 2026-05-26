SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(64) NOT NULL,
    session_id VARCHAR(16) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    optimist TINYINT(1) NOT NULL,
    judet VARCHAR(64) DEFAULT NULL,
    varsta INT DEFAULT NULL,
    sex CHAR(1) DEFAULT NULL,
    persoane_intretinere INT DEFAULT NULL,
    domeniu VARCHAR(80) DEFAULT NULL,
    UNIQUE KEY uq_submissions_sid (session_id),
    KEY idx_submissions_uuid (uuid),
    KEY idx_submissions_judet (judet)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    submission_id INT NOT NULL,
    kind ENUM('datorie','asset') NOT NULL,
    type VARCHAR(64) NOT NULL,
    amount BIGINT NOT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    KEY idx_entries_submission (submission_id),
    KEY idx_entries_kind (kind),
    KEY idx_entries_type (type),
    KEY idx_entries_status (status),
    CONSTRAINT fk_entries_submission
        FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
