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
    // Population = latest submission per UUID (dedupe re-submits), and only
    // those with at least one approved entry. Each row's totals sum only
    // status=1 entries.
    $sql = "SELECT s.id, s.uuid, s.optimist, s.judet, s.varsta, s.sex, s.created_at,
                   SUM(CASE WHEN e.kind = 'datorie' THEN e.amount ELSE 0 END) AS total_datorii,
                   SUM(CASE WHEN e.kind = 'asset' THEN e.amount ELSE 0 END) AS total_asset
            FROM submissions s
            INNER JOIN (
                SELECT MAX(id) AS latest_id FROM submissions GROUP BY uuid
            ) latest ON latest.latest_id = s.id
            INNER JOIN entries e ON e.submission_id = s.id AND e.status = 1
            WHERE 1=1";
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
// We DO NOT filter on status here — the user sees what they entered. Aggregations
// elsewhere filter on status=1.
function fetch_entries_for(PDO $pdo, int $sid): array {
    $stmt = $pdo->prepare('SELECT kind, type, amount, status FROM entries WHERE submission_id = ?');
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
 * Compute the percentile rank of $value within $nums, optionally removing the
 * user's own data point first.
 *
 * Definition: percentage of OTHER population members that have a value lower
 * than the user. Ties contribute 0.5 (mid-rank method, the most accurate way
 * to handle equality and yield a continuous distribution).
 *
 * Integer comparison: amounts are stored as BIGINT and never float, so we
 * cast both sides to int to avoid floating-point equality bugs.
 *
 * Self-exclusion: $excludeId, if supplied, removes one occurrence of $value
 * from the population so a user isn't compared against themselves.
 */
function percentile_of(int $value, array $nums, bool $excludeSelfOnce = false): float {
    if (count($nums) === 0) return 0.0;
    $nums = array_map('intval', $nums);
    if ($excludeSelfOnce) {
        $idx = array_search($value, $nums, true);
        if ($idx !== false) array_splice($nums, $idx, 1);
    }
    $n = count($nums);
    if ($n === 0) return 0.0;
    $below = 0;
    foreach ($nums as $x) {
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

$userLatest = null;
$userEntries = [];
$u = null;
if ($sid !== '') {
    $stmt = $pdo->prepare('SELECT id, optimist, session_id FROM submissions WHERE session_id = ? LIMIT 1');
    $stmt->execute([$sid]);
    $u = $stmt->fetch() ?: null;
} elseif ($uuid !== '') {
    $stmt = $pdo->prepare('SELECT id, optimist, session_id FROM submissions WHERE uuid = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$uuid]);
    $u = $stmt->fetch() ?: null;
}
if ($u) {
        $userEntries = fetch_entries_for($pdo, (int)$u['id']);
        $td = 0; $ta = 0;
        $tdApproved = 0; $taApproved = 0;
        $flaggedCount = 0;
        $byTypeDatorii = []; $byTypeAsset = [];
        foreach ($userEntries as $e) {
            $amt = (int)$e['amount'];
            $approved = (int)$e['status'] === 1;
            if (!$approved) $flaggedCount++;
            if ($e['kind'] === 'datorie') {
                $td += $amt;
                if ($approved) $tdApproved += $amt;
                $byTypeDatorii[$e['type']] = ($byTypeDatorii[$e['type']] ?? 0) + $amt;
            } else {
                $ta += $amt;
                if ($approved) $taApproved += $amt;
                $byTypeAsset[$e['type']] = ($byTypeAsset[$e['type']] ?? 0) + $amt;
            }
        }
        // Percentile uses APPROVED totals (matches the population definition,
        // which only sums status=1 entries per submission). Display uses raw
        // totals so the user sees what they entered.
        $userLatest = [
            'submission_id' => (int)$u['id'],
            'session_id' => $u['session_id'],
            'optimist' => (bool)$u['optimist'],
            'total_datorii' => $td,
            'total_asset' => $ta,
            'net_worth' => $ta - $td,
            'flagged_count' => $flaggedCount,
            'ratio_datorii_asset' => $ta > 0 ? round($td / $ta, 3) : null,
            'by_type_datorii' => $byTypeDatorii,
            'by_type_asset' => $byTypeAsset,
            'percentile_net_worth' => (int)round(percentile_of($taApproved - $tdApproved, $globalNet, true)),
            'percentile_datorii'   => (int)round(percentile_of($tdApproved, $globalDatorii, true)),
            'percentile_asset'     => (int)round(percentile_of($taApproved, $globalAsset, true)),
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

[$byJudetWhere, $byJudetParams] = $buildFilters(['judet']);
$judetStmt = $pdo->prepare(
    "SELECT s.judet,
            COUNT(DISTINCT s.id) AS n,
            AVG(sub.total_asset - sub.total_datorii) AS avg_net,
            AVG(sub.total_datorii) AS avg_datorii,
            AVG(sub.total_asset)   AS avg_asset
     FROM submissions s
     INNER JOIN (SELECT MAX(id) AS latest_id FROM submissions GROUP BY uuid) latest ON latest.latest_id = s.id
     INNER JOIN (
         SELECT submission_id,
                SUM(CASE WHEN kind = 'datorie' THEN amount ELSE 0 END) AS total_datorii,
                SUM(CASE WHEN kind = 'asset'   THEN amount ELSE 0 END) AS total_asset
         FROM entries WHERE status = 1 GROUP BY submission_id
     ) sub ON sub.submission_id = s.id
     WHERE s.judet IS NOT NULL $byJudetWhere
     GROUP BY s.judet
     ORDER BY avg_net DESC"
);
$judetStmt->execute($byJudetParams);
$judetRows = $judetStmt->fetchAll();

$byJudet = array_map(function ($r) {
    return [
        'judet' => $r['judet'],
        'count' => (int)$r['n'],
        'avg_net' => (int)round((float)$r['avg_net']),
        'avg_datorii' => (int)round((float)$r['avg_datorii']),
        'avg_asset' => (int)round((float)$r['avg_asset']),
    ];
}, $judetRows);

// Distribution by domeniu (global)
[$byDomWhere, $byDomParams] = $buildFilters();
$domStmt = $pdo->prepare(
    "SELECT s.domeniu,
            COUNT(DISTINCT s.id) AS n,
            AVG(sub.total_asset - sub.total_datorii) AS avg_net,
            AVG(sub.total_datorii) AS avg_datorii,
            AVG(sub.total_asset)   AS avg_asset
     FROM submissions s
     INNER JOIN (SELECT MAX(id) AS latest_id FROM submissions GROUP BY uuid) latest ON latest.latest_id = s.id
     INNER JOIN (
         SELECT submission_id,
                SUM(CASE WHEN kind = 'datorie' THEN amount ELSE 0 END) AS total_datorii,
                SUM(CASE WHEN kind = 'asset'   THEN amount ELSE 0 END) AS total_asset
         FROM entries WHERE status = 1 GROUP BY submission_id
     ) sub ON sub.submission_id = s.id
     WHERE s.domeniu IS NOT NULL AND s.domeniu != '' $byDomWhere
     GROUP BY s.domeniu
     HAVING n >= 1
     ORDER BY avg_net DESC"
);
$domStmt->execute($byDomParams);
$domeniuRows = $domStmt->fetchAll();

$byDomeniu = array_map(function ($r) {
    return [
        'domeniu' => $r['domeniu'],
        'count' => (int)$r['n'],
        'avg_net' => (int)round((float)$r['avg_net']),
        'avg_datorii' => (int)round((float)$r['avg_datorii']),
        'avg_asset' => (int)round((float)$r['avg_asset']),
    ];
}, $domeniuRows);

// Distribution by persoane_intretinere (bucketed 0,1,2,3,4+)
[$byPIWhere, $byPIParams] = $buildFilters();
$piStmt = $pdo->prepare(
    "SELECT
        CASE
            WHEN s.persoane_intretinere >= 4 THEN '4+'
            ELSE CAST(s.persoane_intretinere AS CHAR)
        END AS bucket,
        COUNT(DISTINCT s.id) AS n,
        AVG(sub.total_asset - sub.total_datorii) AS avg_net,
        AVG(sub.total_datorii) AS avg_datorii,
        AVG(sub.total_asset)   AS avg_asset
     FROM submissions s
     INNER JOIN (SELECT MAX(id) AS latest_id FROM submissions GROUP BY uuid) latest ON latest.latest_id = s.id
     INNER JOIN (
         SELECT submission_id,
                SUM(CASE WHEN kind = 'datorie' THEN amount ELSE 0 END) AS total_datorii,
                SUM(CASE WHEN kind = 'asset'   THEN amount ELSE 0 END) AS total_asset
         FROM entries WHERE status = 1 GROUP BY submission_id
     ) sub ON sub.submission_id = s.id
     WHERE s.persoane_intretinere IS NOT NULL $byPIWhere
     GROUP BY bucket
     ORDER BY CASE bucket WHEN '0' THEN 0 WHEN '1' THEN 1 WHEN '2' THEN 2 WHEN '3' THEN 3 WHEN '4+' THEN 4 END"
);
$piStmt->execute($byPIParams);
$piRows = $piStmt->fetchAll();

$byPI = array_map(function ($r) {
    return [
        'bucket' => $r['bucket'],
        'count' => (int)$r['n'],
        'avg_net' => (int)round((float)$r['avg_net']),
        'avg_datorii' => (int)round((float)$r['avg_datorii']),
        'avg_asset' => (int)round((float)$r['avg_asset']),
    ];
}, $piRows);

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
     WHERE 1=1 $optWhere"
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
     WHERE e.status = 1' .
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
     WHERE e.status = 1' .
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
    'avg_datorii' => (int)round(mean($globalDatorii)),
    'avg_asset' => (int)round(mean($globalAsset)),
    'avg_net_worth' => (int)round(mean($globalNet)),
    'median_datorii' => (int)round(median($globalDatorii)),
    'median_asset' => (int)round(median($globalAsset)),
    'median_net_worth' => (int)round(median($globalNet)),
];

$result = [
    'filters' => [
        'judet' => $judet,
        'sex' => $sex,
        'age_group' => $ageGroup,
    ],
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
    ],
];

json_response($result);
