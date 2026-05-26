<?php
// Apply any pending SQL migrations from backend/migrations/*.sql.
// Tracks applied migrations in a `schema_migrations` table.
// Idempotent: re-running this is safe.
//
// Usage: `php backend/migrate.php`
// Called automatically from /usr/local/bin/deploy-cscb.sh after `git pull`.

declare(strict_types=1);
require __DIR__ . '/db.php';

$pdo = db();

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS schema_migrations (
        name VARCHAR(255) PRIMARY KEY,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$dir = __DIR__ . '/migrations';
if (!is_dir($dir)) {
    fwrite(STDERR, "No migrations directory at $dir — nothing to do.\n");
    exit(0);
}

$files = glob($dir . '/*.sql') ?: [];
sort($files);

$check  = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE name = ?');
$record = $pdo->prepare('INSERT INTO schema_migrations(name) VALUES (?)');

$applied = 0;
foreach ($files as $file) {
    $name = basename($file);
    $check->execute([$name]);
    if ($check->fetchColumn()) continue;

    echo "  → applying $name\n";
    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, "    ✗ could not read $file\n");
        exit(1);
    }

    try {
        $pdo->exec($sql);
        $record->execute([$name]);
        $applied++;
        echo "    ✓ applied\n";
    } catch (Throwable $t) {
        fwrite(STDERR, "    ✗ FAILED: " . $t->getMessage() . "\n");
        exit(1);
    }
}

$total = count($files);
echo "Migrations: $applied applied this run, $total total in repo.\n";
