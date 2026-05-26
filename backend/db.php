<?php
declare(strict_types=1);

function db_config(): array {
    static $config = null;
    if ($config !== null) return $config;

    // Local config file overrides env vars (used in production via config.local.php).
    $local = [];
    if (file_exists(__DIR__ . '/config.local.php')) {
        $local = require __DIR__ . '/config.local.php';
        if (!is_array($local)) $local = [];
    }

    $config = [
        'host' => $local['db_host'] ?? (getenv('DB_HOST') ?: '127.0.0.1'),
        'port' => $local['db_port'] ?? (getenv('DB_PORT') ?: '3306'),
        'name' => $local['db_name'] ?? (getenv('DB_NAME') ?: 'datorii'),
        'user' => $local['db_user'] ?? (getenv('DB_USER') ?: 'datorii'),
        'pass' => $local['db_pass'] ?? (getenv('DB_PASS') ?: ''),
    ];
    return $config;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $c = db_config();
    $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4";
    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

/**
 * Compute approval status for a new entry, based on whether its amount is an
 * outlier vs the existing approved entries of the same (kind, type).
 *
 * Rule: if there are <OUTLIER_MIN_SAMPLE> prior approved entries for that pair,
 * we don't have enough data to detect outliers — approve (return 1).
 * Otherwise compute the median of approved amounts and reject the new value if
 * it falls outside [median / 2, median * 2] (i.e. more than 100% below or above
 * the median).
 *
 * Returns 1 (approved) or 0 (flagged).
 */
const OUTLIER_MIN_SAMPLE = 5;

function compute_entry_status(PDO $pdo, string $kind, string $type, int $amount): int {
    if ($amount <= 0) return 0; // sanity — caller should already filter, but be safe

    static $medianCache = [];
    $cacheKey = $kind . '|' . $type;

    if (!array_key_exists($cacheKey, $medianCache)) {
        $stmt = $pdo->prepare(
            'SELECT amount FROM entries
             WHERE kind = ? AND type = ? AND status = 1
             ORDER BY amount ASC'
        );
        $stmt->execute([$kind, $type]);
        $values = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        if (count($values) < OUTLIER_MIN_SAMPLE) {
            $medianCache[$cacheKey] = null;
        } else {
            $n = count($values);
            $m = intdiv($n, 2);
            $median = $n % 2 === 1
                ? (float)$values[$m]
                : ($values[$m - 1] + $values[$m]) / 2.0;
            $medianCache[$cacheKey] = $median;
        }
    }

    $median = $medianCache[$cacheKey];
    if ($median === null) return 1;          // bootstrap — not enough baseline data
    if ($median <= 0) return 1;              // defensive (cannot happen with amount>0 invariant)

    $lower = $median / 2.0;
    $upper = $median * 2.0;
    if ($amount < $lower || $amount > $upper) return 0;
    return 1;
}

function generate_sid(PDO $pdo): string {
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    $len = strlen($alphabet);
    for ($attempt = 0; $attempt < 8; $attempt++) {
        $sid = '';
        for ($i = 0; $i < 8; $i++) $sid .= $alphabet[random_int(0, $len - 1)];
        $check = $pdo->prepare('SELECT 1 FROM submissions WHERE session_id = ? LIMIT 1');
        $check->execute([$sid]);
        if (!$check->fetchColumn()) return $sid;
    }
    throw new RuntimeException('Could not generate unique session id');
}

function json_response($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

const ALLOWED_HOSTS = ['cumstaicubanii.ro', 'www.cumstaicubanii.ro', 'localhost', '127.0.0.1'];

function host_is_allowed(string $url): bool {
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) return false;
    return in_array(strtolower($host), ALLOWED_HOSTS, true);
}

/**
 * Handles CORS preflight and rejects state-changing requests from untrusted origins.
 * GETs are public; POSTs (and other writes) must come from an allowed host (Origin or Referer).
 */
function check_request_origin(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    // OPTIONS preflight — be permissive only for known origins
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        if ($origin !== '' && host_is_allowed($origin)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Vary: Origin');
        }
        http_response_code(204);
        exit;
    }

    // Echo back allowed origin header so browser CORS checks pass for cross-origin GETs (rare).
    if ($origin !== '' && host_is_allowed($origin)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }

    // Read methods (GET, HEAD) — leave open. Stats are public by design.
    if (in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) return;

    // Write methods — must come from an allowed host
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if ($origin !== '' && host_is_allowed($origin)) return;
    if ($referer !== '' && host_is_allowed($referer)) return;

    json_response(['error' => 'Forbidden: untrusted origin'], 403);
}

function client_ip(): string {
    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($xff !== '') {
        foreach (explode(',', $xff) as $ip) {
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Simple file-based per-key rate limit. Sliding window of $windowSecs seconds.
 * Aborts with 429 if more than $max requests in window.
 */
function rate_limit(string $key, int $max, int $windowSecs): void {
    $bucket = sys_get_temp_dir() . '/datorii-rl';
    if (!is_dir($bucket)) @mkdir($bucket, 0700, true);
    $file = $bucket . '/' . hash('sha1', $key);
    $now = time();
    $threshold = $now - $windowSecs;

    $fp = @fopen($file, 'c+');
    if (!$fp) {
        // If we can't open the file (FS issue), fail-open — better than blocking everyone.
        return;
    }
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $data = $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($data)) $data = [];
    $data = array_values(array_filter($data, fn($t) => is_int($t) && $t > $threshold));

    if (count($data) >= $max) {
        flock($fp, LOCK_UN);
        fclose($fp);
        header('Retry-After: ' . $windowSecs);
        json_response(['error' => 'Prea multe cereri. Reîncearcă mai târziu.'], 429);
    }

    $data[] = $now;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

const DATORII_TYPES = [
    'credit personal',
    'credit imobiliar',
    'credit SRL',
    'card credit',
    'datorii la stat',
    'datorii alte persoane',
];

const ASSET_TYPES = [
    'depozite bancare',
    'titluri de stat',
    'investitii bursa',
    'cryptomonede',
    'imobile',
    'bunuri de valoare',
    'terenuri',
];

const JUDETE = [
    'Alba','Arad','Arges','Bacau','Bihor','Bistrita-Nasaud','Botosani','Brasov','Braila','Bucuresti',
    'Buzau','Caras-Severin','Calarasi','Cluj','Constanta','Covasna','Dambovita','Dolj','Galati','Giurgiu',
    'Gorj','Harghita','Hunedoara','Ialomita','Iasi','Ilfov','Maramures','Mehedinti','Mures','Neamt',
    'Olt','Prahova','Satu Mare','Salaj','Sibiu','Suceava','Teleorman','Timis','Tulcea','Vaslui','Valcea','Vrancea',
];

const DOMENII = [
    'IT & Software',
    'Sanatate',
    'Educatie',
    'Constructii',
    'Comert',
    'Industrie',
    'Transport',
    'HoReCa',
    'Finante & Banking',
    'Agricultura',
    'Administratie publica',
    'Freelance',
    'Antreprenor / SRL',
    'Student',
    'Pensionar',
    'Altele',
];

const SEXE = ['M', 'F', 'X'];
