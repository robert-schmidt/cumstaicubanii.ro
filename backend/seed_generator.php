<?php
// One-shot generator for seed.sql — run from CLI: php seed_generator.php > seed.sql
declare(strict_types=1);
require __DIR__ . '/db.php';

mt_srand(42);

$judete = JUDETE;
$datoriiT = DATORII_TYPES;
$assetT = ASSET_TYPES;
$domenii = DOMENII;
$sexe = SEXE;

function pick(array $a) { return $a[array_rand($a)]; }
function rnd(int $a, int $b): int { return mt_rand($a, $b); }
function uuid(): string {
    $d = random_bytes(16);
    $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
    $d[8] = chr(ord($d[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

function sid_seed(array &$seen): string {
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    $len = strlen($alphabet);
    while (true) {
        $s = '';
        for ($i = 0; $i < 8; $i++) $s .= $alphabet[mt_rand(0, $len - 1)];
        if (!isset($seen[$s])) { $seen[$s] = true; return $s; }
    }
}
$sidSeen = [];

echo "-- Seed data: ~50 demo submissions\n";

for ($i = 0; $i < 50; $i++) {
    $sid = $i + 1;
    $uuid = uuid();
    $optimist = (mt_rand(0, 100) < 55) ? 1 : 0;
    $judet = pick($judete);
    $varsta = rnd(22, 67);
    $sex = pick($sexe);
    $pi = rnd(0, 4);
    $domeniu = pick($domenii);

    $bias = ($optimist ? 1.0 : 0.65);
    $bias *= match(true) {
        in_array($judet, ['Bucuresti','Cluj','Timis','Ilfov','Brasov','Sibiu','Iasi','Constanta'], true) => 1.45,
        default => 1.0,
    };
    if ($varsta >= 35 && $varsta < 55) $bias *= 1.25;

    $createdDays = rnd(0, 120);

    $sessionId = sid_seed($sidSeen);
    echo sprintf(
        "INSERT INTO submissions(id, uuid, session_id, created_at, optimist, judet, varsta, sex, persoane_intretinere, domeniu) VALUES (%d, %s, %s, DATE_SUB(NOW(), INTERVAL %d DAY), %d, %s, %d, %s, %d, %s);\n",
        $sid,
        "'" . $uuid . "'",
        "'" . $sessionId . "'",
        $createdDays,
        $optimist,
        "'" . str_replace("'", "''", $judet) . "'",
        $varsta,
        "'" . $sex . "'",
        $pi,
        "'" . str_replace("'", "''", $domeniu) . "'"
    );

    $nd = rnd(1, 4);
    $na = rnd(1, 4);
    $datoriiPick = (array)array_rand(array_flip($datoriiT), min($nd, count($datoriiT)));
    if (!is_array($datoriiPick)) $datoriiPick = [$datoriiPick];
    $assetPick = (array)array_rand(array_flip($assetT), min($na, count($assetT)));
    if (!is_array($assetPick)) $assetPick = [$assetPick];

    foreach ($datoriiPick as $t) {
        $base = match($t) {
            'credit imobiliar' => rnd(120000, 480000),
            'credit personal'  => rnd(5000, 80000),
            'credit SRL'       => rnd(20000, 250000),
            'card credit'      => rnd(500, 12000),
            'datorii la stat'  => rnd(500, 25000),
            default            => rnd(1000, 30000),
        };
        $amt = (int)round($base * $bias);
        echo sprintf(
            "INSERT INTO entries(submission_id, kind, type, amount) VALUES (%d, 'datorie', %s, %d);\n",
            $sid,
            "'" . str_replace("'", "''", $t) . "'",
            $amt
        );
    }
    foreach ($assetPick as $t) {
        $base = match($t) {
            'imobile'           => rnd(150000, 850000),
            'terenuri'          => rnd(20000, 200000),
            'depozite bancare'  => rnd(3000, 150000),
            'titluri de stat'   => rnd(2000, 90000),
            'investitii bursa'  => rnd(1500, 120000),
            'cryptomonede'      => rnd(500, 60000),
            'bunuri de valoare' => rnd(2000, 50000),
            default             => rnd(1000, 40000),
        };
        $amt = (int)round($base * $bias);
        echo sprintf(
            "INSERT INTO entries(submission_id, kind, type, amount) VALUES (%d, 'asset', %s, %d);\n",
            $sid,
            "'" . str_replace("'", "''", $t) . "'",
            $amt
        );
    }
}
