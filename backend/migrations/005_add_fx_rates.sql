-- Daily EUR→RON reference rates from BNR. One row per publishing date.
-- Amounts are always stored in RON; this table backs both the submit-time
-- EUR→RON conversion and the dashboard's on-the-fly RON↔EUR display toggle.
-- Refreshed by a daily cron (backend/fx-fetch.php @ 14:00 RO time).

CREATE TABLE IF NOT EXISTS fx_rates (
    rate_date  DATE PRIMARY KEY,
    eur_ron    DECIMAL(12,4) NOT NULL,
    source     VARCHAR(32) NOT NULL DEFAULT 'BNR',
    fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
