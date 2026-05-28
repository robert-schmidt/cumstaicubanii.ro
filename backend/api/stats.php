<?php
declare(strict_types=1);
require __DIR__ . '/../db.php';
check_request_origin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

$pdo = db();

$uuid = isset($_GET['uuid']) ? trim((string)$_GET['uuid']) : '';
$sid  = isset($_GET['sid'])  ? strtolower(trim((string)$_GET['sid'])) : '';
if ($sid !== '' && !preg_match('/^[a-z2-9]{8}$/', $sid)) {
    json_response(['error' => 'Session id invalid'], 400);
}
$judet = isset($_GET['judet']) && $_GET['judet'] !== '' ? (string)$_GET['judet'] : null;
$sex = isset($_GET['sex']) && $_GET['sex'] !== '' ? (string)$_GET['sex'] : null;
$ageGroup = isset($_GET['age_group']) && $_GET['age_group'] !== '' ? (string)$_GET['age_group'] : null;

if ($judet !== null && !in_array($judet, JUDETE, true)) json_response(['error' => 'Judet invalid'], 400);
if ($sex !== null && !in_array($sex, SEXE, true)) json_response(['error' => 'Sex invalid'], 400);

$ageRange = null;
if ($ageGroup !== null) {
    $map = [
        '14-24' => [14, 24],
        '25-34' => [25, 34],
        '35-44' => [35, 44],
        '45-54' => [45, 54],
        '55-64' => [55, 64],
        '65+'   => [65, 110],
    ];
    if (!isset($map[$ageGroup])) json_response(['error' => 'Age group invalid'], 400);
    $ageRange = $map[$ageGroup];
}

function fetch_submissions(PDO $pdo, ?string $judet, ?string $sex, ?array $ageRange): array {
    // Population = latest submission per UUID (dedupe re-submits), not
    // soft-deleted, with at least one approved entry. Each row's totals sum
    // only status=1 entries.
    $sql = "SELECT s.id, s.uuid, s.optimist, s.judet, s.varsta, s.sex, s.created_at,
                   SUM(CASE WHEN e.kind = 'datorie' THEN e.amount ELSE 0 END) AS total_datorii,
                   SUM(CASE WHEN e.kind = 'asset' THEN e.amount ELSE 0 END) AS total_asset
            FROM submissions s
            INNER JOIN (
                SELECT MAX(id) AS latest_id FROM submissions GROUP BY uuid
            ) latest ON latest.latest_id = s.id
            INNER JOIN entries e ON e.submission_id = s.id AND e.status = 1
            WHERE s.deleted_at IS NULL AND s.is_spam = 0";
    $params = [];
    if ($judet !== null) { $sql .= ' AND s.judet = ?'; $params[] = $judet; }
    if ($sex !== null)   { $sql .= ' AND s.sex = ?';   $params[] = $sex; }
    if ($ageRange !== null) {
        $sql .= ' AND s.varsta BETWEEN ? AND ?';
        $params[] = $ageRange[0]; $params[] = $ageRange[1];
    }
    $sql .= ' GROUP BY s.id ORDER BY s.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Fetch all entries for a specific submission (used for the personal "Tu" card).
// We DO NOT filter on status here — the user sees exactly what they entered.
// Whether each entry is approved or flagged is intentionally NOT exposed to the
// user-facing response; only aggregations (population/breakdown/by_*) silently
// exclude flagged entries.
function fetch_entries_for(PDO $pdo, int $sid): array {
    $stmt = $pdo->prepare('SELECT kind, type, amount FROM entries WHERE submission_id = ?');
    $stmt->execute([$sid]);
    return $stmt->fetchAll();
}

function mean(array $nums): float {
    if (count($nums) === 0) return 0.0;
    return array_sum($nums) / count($nums);
}

function median(array $nums): float {
    if (count($nums) === 0) return 0.0;
    sort($nums);
    $n = count($nums);
    $m = (int)floor($n / 2);
    return $n % 2 ? (float)$nums[$m] : ($nums[$m - 1] + $nums[$m]) / 2.0;
}

/**
 * Mid-rank percentile of $value within $nums (a pre-filtered population that
 * does NOT include the user themselves — caller is responsible for that).
 *
 * Ties contribute 0.5 each, the standard accurate handling that yields a
 * continuous distribution.
 *
 * Integer comparison: amounts are stored as BIGINT, so we cast both sides
 * to int to avoid floating-point equality glitches.
 */
function percentile_of(int $value, array $nums): float {
    $n = count($nums);
    if ($n === 0) return 0.0;
    $below = 0.0;
    foreach ($nums as $x) {
        $x = (int)$x;
        if ($x < $value) $below++;
        elseif ($x === $value) $below += 0.5;
    }
    return round(($below / $n) * 100, 1);
}

$rows = fetch_submissions($pdo, $judet, $sex, $ageRange);

$globalNet = []; $globalDatorii = []; $globalAsset = [];
foreach ($rows as $r) {
    $globalDatorii[] = (float)$r['total_datorii'];
    $globalAsset[]   = (float)$r['total_asset'];
    $globalNet[]     = (float)$r['total_asset'] - (float)$r['total_datorii'];
}
// For "typical user" datorii / asset stats, exclude rows where that metric is 0
// (i.e. users who didn't report ANY entry of that kind). Including them
// would dominate the median when, say, half the population has no debt at all.
// Net worth is computed over the full population — a user with 0 datorii and
// 100k asset has a genuinely valid +100k net worth.
$datoriiOfDebtors  = array_values(array_filter($globalDatorii, fn($x) => $x > 0));
$assetOfHolders    = array_values(array_filter($globalAsset,   fn($x) => $x > 0));

$userLatest = null;
$userEntries = [];
$u = null;
if ($sid !== '') {
    $stmt = $pdo->prepare('SELECT id, optimist, session_id FROM submissions WHERE session_id = ? AND deleted_at IS NULL AND is_spam = 0 LIMIT 1');
    $stmt->execute([$sid]);
    $u = $stmt->fetch() ?: null;
} elseif ($uuid !== '') {
    $stmt = $pdo->prepare('SELECT id, optimist, session_id FROM submissions WHERE uuid = ? AND deleted_at IS NULL AND is_spam = 0 ORDER BY id DESC LIMIT 1');
    $stmt->execute([$uuid]);
    $u = $stmt->fetch() ?: null;
}
if ($u) {
        $userEntries = fetch_entries_for($pdo, (int)$u['id']);
        $td = 0; $ta = 0;
        $byTypeDatorii = []; $byTypeAsset = [];
        foreach ($userEntries as $e) {
            $amt = (int)$e['amount'];
            if ($e['kind'] === 'datorie') {
                $td += $amt;
                $byTypeDatorii[$e['type']] = ($byTypeDatorii[$e['type']] ?? 0) + $amt;
            } else {
                $ta += $amt;
                $byTypeAsset[$e['type']] = ($byTypeAsset[$e['type']] ?? 0) + $amt;
            }
        }

        // Build percentile populations: exclude self, and for datorii/asset use
        // only the corresponding sub-population (debtors / asset-holders) so
        // the ranking matches the median's "typical user of this metric"
        // semantics. Net worth uses the full population.
        $userSubId = (int)$u['id'];
        $pctDatoriiPop = [];
        $pctAssetPop   = [];
        $pctNetPop     = [];
        foreach ($rows as $r) {
            if ((int)$r['id'] === $userSubId) continue;
            $td_r = (float)$r['total_datorii'];
            $ta_r = (float)$r['total_asset'];
            if ($td_r > 0) $pctDatoriiPop[] = $td_r;
            if ($ta_r > 0) $pctAssetPop[]   = $ta_r;
            $pctNetPop[] = $ta_r - $td_r;
        }

        // Percentile = 0 when the user doesn't report that metric at all
        // (no debt → at the bottom of the debtor distribution = "Sub medie 🎉").
        $pctD = $td > 0 ? (int)round(percentile_of($td, $pctDatoriiPop)) : 0;
        $pctA = $ta > 0 ? (int)round(percentile_of($ta, $pctAssetPop))   : 0;
        $pctN = (int)round(percentile_of($ta - $td, $pctNetPop));

        // GLOBAL percentiles — same math but always against the full,
        // unfiltered population. Used for the share message so the percentage
        // a user posts on social media reflects their ABSOLUTE position, not
        // their rank within an arbitrary filter slice they may have toggled.
        if ($judet !== null || $sex !== null || $ageRange !== null) {
            $globalRows = fetch_submissions($pdo, null, null, null);
            $gDatorii = []; $gAsset = []; $gNet = [];
            foreach ($globalRows as $gr) {
                if ((int)$gr['id'] === $userSubId) continue;
                $tdg = (float)$gr['total_datorii'];
                $tag = (float)$gr['total_asset'];
                if ($tdg > 0) $gDatorii[] = $tdg;
                if ($tag > 0) $gAsset[]   = $tag;
                $gNet[] = $tag - $tdg;
            }
            $pctDg = $td > 0 ? (int)round(percentile_of($td, $gDatorii)) : 0;
            $pctAg = $ta > 0 ? (int)round(percentile_of($ta, $gAsset))   : 0;
            $pctNg = (int)round(percentile_of($ta - $td, $gNet));
        } else {
            // No filter applied — global = filtered, save the work.
            $pctDg = $pctD; $pctAg = $pctA; $pctNg = $pctN;
        }

        $userLatest = [
            'submission_id' => $userSubId,
            'session_id' => $u['session_id'],
            'optimist' => (bool)$u['optimist'],
            'total_datorii' => $td,
            'total_asset' => $ta,
            'net_worth' => $ta - $td,
            'ratio_datorii_asset' => $ta > 0 ? round($td / $ta, 3) : null,
            'by_type_datorii' => $byTypeDatorii,
            'by_type_asset' => $byTypeAsset,
            'percentile_datorii'          => $pctD,
            'percentile_asset'            => $pctA,
            'percentile_net_worth'        => $pctN,
            'percentile_datorii_global'   => $pctDg,
            'percentile_asset_global'     => $pctAg,
            'percentile_net_worth_global' => $pctNg,
        ];
}

// Distribution by judet (always global, ignores filters)
// Helpers: append the active filters to an aggregation query. `skip` lists
// dimensions we shouldn't filter on (e.g. when grouping BY that dimension).
$buildFilters = function(array $skip = []) use ($judet, $sex, $ageRange): array {
    $sql = ''; $params = [];
    if ($judet !== null && !in_array('judet', $skip, true))     { $sql .= ' AND s.judet = :judet';       $params[':judet'] = $judet; }
    if ($sex !== null && !in_array('sex', $skip, true))          { $sql .= ' AND s.sex = :sex';           $params[':sex']   = $sex; }
    if ($ageRange !== null && !in_array('age', $skip, true))     { $sql .= ' AND s.varsta BETWEEN :amin AND :amax'; $params[':amin'] = $ageRange[0]; $params[':amax'] = $ageRange[1]; }
    return [$sql, $params];
};

// By-group net worth uses MEDIAN per bucket — averages get skewed by a handful
// of high-net-worth outliers and stop reflecting "the typical user in this
// group". We fetch per-submission net values and median in PHP (same pattern
// as the per-type breakdown).
[$byJudetWhere, $byJudetParams] = $buildFilters(['judet']);
$judetStmt = $pdo->prepare(
    "SELECT s.judet, (sub.total_asset - sub.total_datorii) AS net
     FROM submissions s
     INNER JOIN (SELECT MAX(id) AS latest_id FROM submissions GROUP BY uuid) latest ON latest.latest_id = s.id
     INNER JOIN (
         SELECT submission_id,
                SUM(CASE WHEN kind = 'datorie' THEN amount ELSE 0 END) AS total_datorii,
                SUM(CASE WHEN kind = 'asset'   THEN amount ELSE 0 END) AS total_asset
         FROM entries WHERE status = 1 GROUP BY submission_id
     ) sub ON sub.submission_id = s.id
     WHERE s.judet IS NOT NULL AND s.deleted_at IS NULL AND s.is_spam = 0 $byJudetWhere"
);
$judetStmt->execute($byJudetParams);
$judetNets = [];
foreach ($judetStmt as $r) {
    $judetNets[$r['judet']][] = (int)$r['net'];
}
$byJudet = [];
foreach ($judetNets as $j => $nets) {
    $byJudet[] = [
        'judet' => $j,
        'count' => count($nets),
        'median_net' => (int)round(median($nets)),
    ];
}
usort($byJudet, fn($a, $b) => $b['median_net'] <=> $a['median_net']);

// Distribution by domeniu — same median-per-bucket approach as by_judet.
[$byDomWhere, $byDomParams] = $buildFilters();
$domStmt = $pdo->prepare(
    "SELECT s.domeniu, (sub.total_asset - sub.total_datorii) AS net
     FROM submissions s
     INNER JOIN (SELECT MAX(id) AS latest_id FROM submissions GROUP BY uuid) latest ON latest.latest_id = s.id
     INNER JOIN (
         SELECT submission_id,
                SUM(CASE WHEN kind = 'datorie' THEN amount ELSE 0 END) AS total_datorii,
                SUM(CASE WHEN kind = 'asset'   THEN amount ELSE 0 END) AS total_asset
         FROM entries WHERE status = 1 GROUP BY submission_id
     ) sub ON sub.submission_id = s.id
     WHERE s.domeniu IS NOT NULL AND s.domeniu != '' AND s.deleted_at IS NULL AND s.is_spam = 0 $byDomWhere"
);
$domStmt->execute($byDomParams);
$domNets = [];
foreach ($domStmt as $r) {
    $domNets[$r['domeniu']][] = (int)$r['net'];
}
$byDomeniu = [];
foreach ($domNets as $d => $nets) {
    $byDomeniu[] = [
        'domeniu' => $d,
        'count' => count($nets),
        'median_net' => (int)round(median($nets)),
    ];
}
usort($byDomeniu, fn($a, $b) => $b['median_net'] <=> $a['median_net']);

// Distribution by persoane_intretinere — bucketed 0,1,2,3,4+ with median net.
[$byPIWhere, $byPIParams] = $buildFilters();
$piStmt = $pdo->prepare(
    "SELECT s.persoane_intretinere AS pi, (sub.total_asset - sub.total_datorii) AS net
     FROM submissions s
     INNER JOIN (SELECT MAX(id) AS latest_id FROM submissions GROUP BY uuid) latest ON latest.latest_id = s.id
     INNER JOIN (
         SELECT submission_id,
                SUM(CASE WHEN kind = 'datorie' THEN amount ELSE 0 END) AS total_datorii,
                SUM(CASE WHEN kind = 'asset'   THEN amount ELSE 0 END) AS total_asset
         FROM entries WHERE status = 1 GROUP BY submission_id
     ) sub ON sub.submission_id = s.id
     WHERE s.persoane_intretinere IS NOT NULL AND s.deleted_at IS NULL AND s.is_spam = 0 $byPIWhere"
);
$piStmt->execute($byPIParams);
$piNets = [];
foreach ($piStmt as $r) {
    $pi = (int)$r['pi'];
    $bucket = $pi >= 4 ? '4+' : (string)$pi;
    $piNets[$bucket][] = (int)$r['net'];
}
$bucketOrder = ['0' => 0, '1' => 1, '2' => 2, '3' => 3, '4+' => 4];
$byPI = [];
foreach ($piNets as $bucket => $nets) {
    $byPI[] = [
        'bucket' => $bucket,
        'count' => count($nets),
        'median_net' => (int)round(median($nets)),
    ];
}
usort($byPI, fn($a, $b) => ($bucketOrder[$a['bucket']] ?? 99) <=> ($bucketOrder[$b['bucket']] ?? 99));

// Optimism correlation
[$optWhere, $optParams] = $buildFilters();
$optStmt = $pdo->prepare(
    "SELECT s.optimist,
            sub.total_asset - sub.total_datorii AS net
     FROM submissions s
     INNER JOIN (SELECT MAX(id) AS latest_id FROM submissions GROUP BY uuid) latest ON latest.latest_id = s.id
     INNER JOIN (
         SELECT submission_id,
                SUM(CASE WHEN kind = 'datorie' THEN amount ELSE 0 END) AS total_datorii,
                SUM(CASE WHEN kind = 'asset'   THEN amount ELSE 0 END) AS total_asset
         FROM entries WHERE status = 1 GROUP BY submission_id
     ) sub ON sub.submission_id = s.id
     WHERE s.deleted_at IS NULL AND s.is_spam = 0 $optWhere"
);
$optStmt->execute($optParams);
$optimistNet = []; $pesimistNet = [];
foreach ($optStmt as $r) {
    if ((int)$r['optimist'] === 1) $optimistNet[] = (float)$r['net'];
    else $pesimistNet[] = (float)$r['net'];
}

// Aggregate breakdown by type across the filtered population
$breakdownStmt = $pdo->prepare(
    'SELECT e.kind, e.type, AVG(e.amount) AS avg_amount, SUM(e.amount) AS sum_amount, COUNT(*) AS n
     FROM entries e
     JOIN submissions s ON s.id = e.submission_id
     INNER JOIN (SELECT MAX(id) AS latest_id FROM submissions GROUP BY uuid) latest ON latest.latest_id = s.id
     WHERE e.status = 1 AND s.deleted_at IS NULL AND s.is_spam = 0' .
     ($judet !== null ? ' AND s.judet = :judet' : '') .
     ($sex !== null   ? ' AND s.sex = :sex'     : '') .
     ($ageRange !== null ? ' AND s.varsta BETWEEN :amin AND :amax' : '') .
     ' GROUP BY e.kind, e.type'
);
$bindParams = [];
if ($judet !== null) $bindParams[':judet'] = $judet;
if ($sex !== null) $bindParams[':sex'] = $sex;
if ($ageRange !== null) { $bindParams[':amin'] = $ageRange[0]; $bindParams[':amax'] = $ageRange[1]; }
$breakdownStmt->execute($bindParams);
$breakdownRows = $breakdownStmt->fetchAll();

// Compute median per (kind, type) so breakdown shows the TYPICAL value, not
// the mean (skewed by a few high-net-worth users). PHP-side since MariaDB
// PERCENTILE_CONT requires window-only syntax that complicates the join.
$medianStmt = $pdo->prepare(
    'SELECT e.kind, e.type, e.amount
     FROM entries e
     JOIN submissions s ON s.id = e.submission_id
     INNER JOIN (SELECT MAX(id) AS latest_id FROM submissions GROUP BY uuid) latest ON latest.latest_id = s.id
     WHERE e.status = 1 AND s.deleted_at IS NULL AND s.is_spam = 0' .
     ($judet !== null ? ' AND s.judet = :judet' : '') .
     ($sex !== null   ? ' AND s.sex = :sex'     : '') .
     ($ageRange !== null ? ' AND s.varsta BETWEEN :amin AND :amax' : '') .
     ' ORDER BY e.kind, e.type, e.amount ASC'
);
$medianStmt->execute($bindParams);
$amountsByType = [];
foreach ($medianStmt as $r) {
    $amountsByType[$r['kind'] . '|' . $r['type']][] = (int)$r['amount'];
}
function median_of(array $sorted): int {
    $n = count($sorted);
    if ($n === 0) return 0;
    $mid = intdiv($n, 2);
    return $n % 2 === 1 ? $sorted[$mid] : (int)round(($sorted[$mid - 1] + $sorted[$mid]) / 2);
}

$breakdown = ['datorii' => [], 'asset' => []];
foreach ($breakdownRows as $r) {
    $bucket = $r['kind'] === 'datorie' ? 'datorii' : 'asset';
    $median = median_of($amountsByType[$r['kind'] . '|' . $r['type']] ?? []);
    $breakdown[$bucket][] = [
        'type' => $r['type'],
        'avg' => (int)round((float)$r['avg_amount']),
        'median' => $median,
        'sum' => (int)round((float)$r['sum_amount']),
        'count' => (int)$r['n'],
    ];
}

$population = [
    'count' => count($rows),
    'avg_datorii'      => (int)round(mean($datoriiOfDebtors)),
    'avg_asset'        => (int)round(mean($assetOfHolders)),
    'avg_net_worth'    => (int)round(mean($globalNet)),
    'median_datorii'   => (int)round(median($datoriiOfDebtors)),
    'median_asset'     => (int)round(median($assetOfHolders)),
    'median_net_worth' => (int)round(median($globalNet)),
    // Coverage signals — frontend can show "X% au datorii" if it wants.
    'with_datorii_count' => count($datoriiOfDebtors),
    'with_asset_count'   => count($assetOfHolders),
];

// Submission-level moderation counts (1 user = 1 unit, latest per UUID, global).
// "approved" = submission has at least one status=1 entry (counted in stats).
// "troli"    = submission has zero status=1 entries (filtered out everywhere).
$submissionCounts = $pdo->query(
    "SELECT
        SUM(CASE WHEN has_approved = 1 THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN has_approved = 0 THEN 1 ELSE 0 END) AS flagged
     FROM (
        SELECT s.id,
               (SELECT 1 FROM entries e WHERE e.submission_id = s.id AND e.status = 1 LIMIT 1) IS NOT NULL AS has_approved
        FROM submissions s
        INNER JOIN (SELECT MAX(id) AS latest_id FROM submissions GROUP BY uuid) latest ON latest.latest_id = s.id
        WHERE s.deleted_at IS NULL AND s.is_spam = 0
     ) sub"
)->fetch();
$submissionsApproved = (int)($submissionCounts['approved'] ?? 0);
$submissionsFlagged  = (int)($submissionCounts['flagged']  ?? 0);

$result = [
    'filters' => [
        'judet' => $judet,
        'sex' => $sex,
        'age_group' => $ageGroup,
    ],
    'submissions_approved' => $submissionsApproved,
    'submissions_flagged'  => $submissionsFlagged,
    'population' => $population,
    'breakdown' => $breakdown,
    'by_judet' => $byJudet,
    'by_domeniu' => $byDomeniu,
    'by_persoane_intretinere' => $byPI,
    'optimism' => [
        'optimist' => [
            'count' => count($optimistNet),
            'avg_net' => (int)round(mean($optimistNet)),
            'median_net' => (int)round(median($optimistNet)),
        ],
        'pesimist' => [
            'count' => count($pesimistNet),
            'avg_net' => (int)round(mean($pesimistNet)),
            'median_net' => (int)round(median($pesimistNet)),
        ],
    ],
    'user' => $userLatest,
    'meta' => [
        'datorii_types' => DATORII_TYPES,
        'asset_types' => ASSET_TYPES,
        'judete' => JUDETE,
        'domenii' => DOMENII,
        'age_groups' => ['14-24','25-34','35-44','45-54','55-64','65+'],
        'fx' => ($fx = fx_latest($pdo)) ? ['eur_ron' => $fx['eur_ron'], 'date' => $fx['rate_date']] : null,
    ],
];

json_response($result);
