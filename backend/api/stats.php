<?php
declare(strict_types=1);
require __DIR__ . '/../db.php';
cors_headers();

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
    $sql = "SELECT s.id, s.uuid, s.optimist, s.judet, s.varsta, s.sex, s.created_at,
                   COALESCE(SUM(CASE WHEN e.kind = 'datorie' THEN e.amount ELSE 0 END), 0) AS total_datorii,
                   COALESCE(SUM(CASE WHEN e.kind = 'asset' THEN e.amount ELSE 0 END), 0) AS total_asset
            FROM submissions s
            LEFT JOIN entries e ON e.submission_id = s.id
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

function percentile_of(float $value, array $nums): float {
    if (count($nums) === 0) return 0.0;
    sort($nums);
    $below = 0;
    foreach ($nums as $n) {
        if ($n < $value) $below++;
        elseif ($n === $value) $below += 0.5;
    }
    return round(($below / count($nums)) * 100, 1);
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
        $td = 0.0; $ta = 0.0;
        $byTypeDatorii = []; $byTypeAsset = [];
        foreach ($userEntries as $e) {
            if ($e['kind'] === 'datorie') {
                $td += (float)$e['amount'];
                $byTypeDatorii[$e['type']] = ($byTypeDatorii[$e['type']] ?? 0) + (float)$e['amount'];
            } else {
                $ta += (float)$e['amount'];
                $byTypeAsset[$e['type']] = ($byTypeAsset[$e['type']] ?? 0) + (float)$e['amount'];
            }
        }
        $userLatest = [
            'submission_id' => (int)$u['id'],
            'session_id' => $u['session_id'],
            'optimist' => (bool)$u['optimist'],
            'total_datorii' => (int)round($td),
            'total_asset' => (int)round($ta),
            'net_worth' => (int)round($ta - $td),
            'ratio_datorii_asset' => $ta > 0 ? round($td / $ta, 3) : null,
            'by_type_datorii' => array_map(fn($v) => (int)round($v), $byTypeDatorii),
            'by_type_asset' => array_map(fn($v) => (int)round($v), $byTypeAsset),
            'percentile_net_worth' => round(percentile_of($ta - $td, $globalNet)),
            'percentile_datorii' => round(percentile_of($td, $globalDatorii)),
            'percentile_asset' => round(percentile_of($ta, $globalAsset)),
        ];
}

// Distribution by judet (always global, ignores filters)
$judetRows = $pdo->query(
    "SELECT s.judet,
            COUNT(DISTINCT s.id) AS n,
            AVG(COALESCE(sub.total_asset,0) - COALESCE(sub.total_datorii,0)) AS avg_net,
            AVG(COALESCE(sub.total_datorii,0)) AS avg_datorii,
            AVG(COALESCE(sub.total_asset,0)) AS avg_asset
     FROM submissions s
     LEFT JOIN (
         SELECT submission_id,
                SUM(CASE WHEN kind = 'datorie' THEN amount ELSE 0 END) AS total_datorii,
                SUM(CASE WHEN kind = 'asset' THEN amount ELSE 0 END) AS total_asset
         FROM entries GROUP BY submission_id
     ) sub ON sub.submission_id = s.id
     WHERE s.judet IS NOT NULL
     GROUP BY s.judet
     ORDER BY avg_net DESC"
)->fetchAll();

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
$domeniuRows = $pdo->query(
    "SELECT s.domeniu,
            COUNT(DISTINCT s.id) AS n,
            AVG(COALESCE(sub.total_asset,0) - COALESCE(sub.total_datorii,0)) AS avg_net,
            AVG(COALESCE(sub.total_datorii,0)) AS avg_datorii,
            AVG(COALESCE(sub.total_asset,0)) AS avg_asset
     FROM submissions s
     LEFT JOIN (
         SELECT submission_id,
                SUM(CASE WHEN kind = 'datorie' THEN amount ELSE 0 END) AS total_datorii,
                SUM(CASE WHEN kind = 'asset' THEN amount ELSE 0 END) AS total_asset
         FROM entries GROUP BY submission_id
     ) sub ON sub.submission_id = s.id
     WHERE s.domeniu IS NOT NULL AND s.domeniu != ''
     GROUP BY s.domeniu
     HAVING n >= 1
     ORDER BY avg_net DESC"
)->fetchAll();

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
$piRows = $pdo->query(
    "SELECT
        CASE
            WHEN s.persoane_intretinere IS NULL THEN NULL
            WHEN s.persoane_intretinere >= 4 THEN '4+'
            ELSE CAST(s.persoane_intretinere AS CHAR)
        END AS bucket,
        COUNT(DISTINCT s.id) AS n,
        AVG(COALESCE(sub.total_asset,0) - COALESCE(sub.total_datorii,0)) AS avg_net,
        AVG(COALESCE(sub.total_datorii,0)) AS avg_datorii,
        AVG(COALESCE(sub.total_asset,0)) AS avg_asset
     FROM submissions s
     LEFT JOIN (
         SELECT submission_id,
                SUM(CASE WHEN kind = 'datorie' THEN amount ELSE 0 END) AS total_datorii,
                SUM(CASE WHEN kind = 'asset' THEN amount ELSE 0 END) AS total_asset
         FROM entries GROUP BY submission_id
     ) sub ON sub.submission_id = s.id
     WHERE s.persoane_intretinere IS NOT NULL
     GROUP BY bucket
     ORDER BY CASE bucket WHEN '0' THEN 0 WHEN '1' THEN 1 WHEN '2' THEN 2 WHEN '3' THEN 3 WHEN '4+' THEN 4 END"
)->fetchAll();

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
$optStmt = $pdo->query(
    "SELECT s.optimist,
            COALESCE(sub.total_asset,0) - COALESCE(sub.total_datorii,0) AS net
     FROM submissions s
     LEFT JOIN (
         SELECT submission_id,
                SUM(CASE WHEN kind = 'datorie' THEN amount ELSE 0 END) AS total_datorii,
                SUM(CASE WHEN kind = 'asset' THEN amount ELSE 0 END) AS total_asset
         FROM entries GROUP BY submission_id
     ) sub ON sub.submission_id = s.id"
);
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
     WHERE 1=1' .
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

$breakdown = ['datorii' => [], 'asset' => []];
foreach ($breakdownRows as $r) {
    $bucket = $r['kind'] === 'datorie' ? 'datorii' : 'asset';
    $breakdown[$bucket][] = [
        'type' => $r['type'],
        'avg' => (int)round((float)$r['avg_amount']),
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
