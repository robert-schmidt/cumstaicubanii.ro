<?php
// Daily EUR→RON rate fetcher. Pulls the BNR reference rate and upserts one row
// per publishing date into `fx_rates`. Idempotent — safe to run repeatedly.
//
// Run manually (local):   php backend/fx-fetch.php
// Cron (prod, 14:00 RO):   0 14 * * *  php /path/to/backend/fx-fetch.php >> /var/log/fx-fetch.log 2>&1
//
// Exit codes: 0 = stored, 1 = failure (so cron / monitoring can alert).

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

require __DIR__ . '/db.php';

try {
    $r = fx_fetch_bnr();
    fx_store_rate(db(), $r['date'], $r['eur_ron']);
    fwrite(STDOUT, sprintf("[%s] BNR EUR→RON for %s = %.4f — stored.\n", date('c'), $r['date'], $r['eur_ron']));
    exit(0);
} catch (Throwable $t) {
    fwrite(STDERR, sprintf("[%s] FX fetch failed: %s\n", date('c'), $t->getMessage()));
    exit(1);
}
