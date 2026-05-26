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

function cors_headers(): void {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
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
