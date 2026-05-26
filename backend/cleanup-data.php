<?php
// One-off (rerunnable) cleanup:
//   1) Delete duplicate submissions per UUID (keeps the latest by id).
//      Entries cascade automatically via FK ON DELETE CASCADE.
//   2) Re-evaluate the outlier band per (kind, type) and flag amounts that
//      fall outside [median/2, median*2] (i.e., 100% above or below median).
//
// Usage:
//   php backend/cleanup-data.php            # DRY RUN — only prints what would change
//   php backend/cleanup-data.php --apply    # actually mutates the database
//
// The script is idempotent: running it twice with --apply yields the same DB state.

declare(strict_types=1);
require __DIR__ . '/db.php';

// Minimum approved entries needed before applying outlier check to a (kind,type).
// Mirrors the constant in db.php so this script also works against an older
// backend that doesn't expose it yet.
if (!defined('OUTLIER_MIN_SAMPLE')) define('OUTLIER_MIN_SAMPLE', 5);

$dryRun = !in_array('--apply', $argv ?? [], true);

// Outlier band multiplier. Default 2 matches the auto-flag rule at insert.
// For a one-off cleanup pass over noisy real-world data, pass --band=5 to be
// far more permissive (only flags genuinely extreme values).
$band = 2.0;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--band=([\d.]+)$/', $arg, $m) && (float)$m[1] > 0) {
        $band = (float)$m[1];
    }
}

$pdo = db();

echo "===== Cleanup data (" . ($dryRun ? 'DRY RUN' : 'APPLY') . ") =====\n\n";

// -------------------------------------------------------------------------- 1
// Duplicate submissions (same UUID, multiple rows). Keep latest, delete older.
$dupes = $pdo->query(
    "SELECT uuid, GROUP_CONCAT(id ORDER BY id DESC) AS ids, COUNT(*) AS c
     FROM submissions
     GROUP BY uuid
     HAVING c > 1"
)->fetchAll();

$toDelete = [];
echo "STEP 1 — Duplicate submissions per UUID:\n";
if (!$dupes) {
    echo "  ✓ none\n";
}
foreach ($dupes as $d) {
    $ids  = explode(',', $d['ids']);
    $keep = array_shift($ids);
    foreach ($ids as $oldId) $toDelete[] = (int)$oldId;
    printf("  uuid %s — keep #%s, delete [%s]\n", $d['uuid'], $keep, implode(', ', $ids));
}
echo "  → " . count($toDelete) . " submissions to delete\n";

if (!$dryRun && $toDelete) {
    $in   = str_repeat('?,', count($toDelete) - 1) . '?';
    $stmt = $pdo->prepare("DELETE FROM submissions WHERE id IN ($in)");
    $stmt->execute($toDelete);
    echo "  ✓ deleted " . $stmt->rowCount() . " (entries cascaded).\n";
}
echo "\n";

// -------------------------------------------------------------------------- 2
// Outlier flag: for each (kind, type), recompute median over status=1 entries
// (post-dedup if applied) and flag values outside [median/2, median*2].

$lowMul  = 1.0 / $band;
$highMul = $band;
printf("STEP 2 — Outlier flagging (band = [median × %g, median × %g]):\n", $lowMul, $highMul);

$pairs = $pdo->query('SELECT DISTINCT kind, type FROM entries')->fetchAll();
$totalFlagged = 0;

foreach ($pairs as $p) {
    $kind = $p['kind'];
    $type = $p['type'];

    $vals = $pdo->prepare(
        'SELECT amount FROM entries
         WHERE kind = ? AND type = ? AND status = 1
         ORDER BY amount ASC'
    );
    $vals->execute([$kind, $type]);
    $values = array_map('intval', $vals->fetchAll(PDO::FETCH_COLUMN));
    $n = count($values);

    if ($n < OUTLIER_MIN_SAMPLE) {
        printf("  %-8s %-30s n=%-4d  (too few; min=%d, skipped)\n", $kind, $type, $n, OUTLIER_MIN_SAMPLE);
        continue;
    }
    $mIdx   = intdiv($n, 2);
    $median = $n % 2 === 1 ? (float)$values[$mIdx] : ($values[$mIdx - 1] + $values[$mIdx]) / 2.0;
    $low    = $median * $lowMul;
    $high   = $median * $highMul;

    $cand = $pdo->prepare(
        'SELECT id, amount FROM entries
         WHERE kind = ? AND type = ? AND status = 1 AND (amount < ? OR amount > ?)'
    );
    $cand->execute([$kind, $type, $low, $high]);
    $candidates = $cand->fetchAll();

    $fmt = fn($v) => number_format($v, 0, '.', ',');
    printf("  %-8s %-30s n=%-4d median=%-12s band=[%s, %s]  → flag %d\n",
        $kind, $type, $n, $fmt($median), $fmt($low), $fmt($high), count($candidates));

    if (!$dryRun && $candidates) {
        $ids = array_column($candidates, 'id');
        $in  = str_repeat('?,', count($ids) - 1) . '?';
        $upd = $pdo->prepare("UPDATE entries SET status = 0 WHERE id IN ($in)");
        $upd->execute($ids);
        $totalFlagged += $upd->rowCount();
    } else {
        $totalFlagged += count($candidates);
    }
}

echo "\nTotal outliers " . ($dryRun ? "WOULD be flagged" : "flagged") . ": $totalFlagged\n";

if ($dryRun) {
    echo "\n💡 DRY RUN — no changes made. Re-run with `--apply` to commit.\n";
} else {
    echo "\n✓ Done.\n";
}
