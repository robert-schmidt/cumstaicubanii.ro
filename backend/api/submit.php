<?php
declare(strict_types=1);
require __DIR__ . '/../db.php';
check_request_origin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

// Per-IP rate limit: max 10 submits per hour (per source IP behind nginx X-Forwarded-For)
rate_limit('submit:' . client_ip(), 10, 3600);

// Cap raw body size before parsing (1 MB is plenty; legit submits are ~1 KB)
if (($_SERVER['CONTENT_LENGTH'] ?? 0) > 1_048_576) {
    json_response(['error' => 'Payload too large'], 413);
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    json_response(['error' => 'Invalid JSON'], 400);
}

$uuid = trim((string)($body['uuid'] ?? ''));
if (!preg_match('/^[0-9a-f-]{16,64}$/i', $uuid)) {
    json_response(['error' => 'Invalid uuid'], 400);
}

$optimist = !empty($body['optimist']) ? 1 : 0;

$judet = $body['judet'] ?? null;
if ($judet !== null) {
    $judet = trim((string)$judet);
    if ($judet === '' ) $judet = null;
    elseif (!in_array($judet, JUDETE, true)) json_response(['error' => 'Judet invalid'], 400);
}

$varsta = isset($body['varsta']) && $body['varsta'] !== '' ? (int)$body['varsta'] : null;
if ($varsta !== null && ($varsta < 14 || $varsta > 110)) json_response(['error' => 'Varsta invalida'], 400);

$sex = $body['sex'] ?? null;
if ($sex !== null) {
    $sex = (string)$sex;
    if ($sex === '') $sex = null;
    elseif (!in_array($sex, SEXE, true)) json_response(['error' => 'Sex invalid'], 400);
}

$pi = isset($body['persoane_intretinere']) && $body['persoane_intretinere'] !== '' ? (int)$body['persoane_intretinere'] : null;
if ($pi !== null && ($pi < 0 || $pi > 20)) json_response(['error' => 'Persoane intretinere invalid'], 400);

$domeniu = $body['domeniu'] ?? null;
if ($domeniu !== null) {
    $domeniu = trim((string)$domeniu);
    if ($domeniu === '') $domeniu = null;
    elseif (strlen($domeniu) > 80) $domeniu = substr($domeniu, 0, 80);
}

$entries = $body['entries'] ?? [];
if (!is_array($entries)) json_response(['error' => 'Entries invalid'], 400);
if (count($entries) > 50) json_response(['error' => 'Prea multe intrări'], 400);

const AMOUNT_MAX = 100_000_000_000; // 100 miliarde RON — safety cap, dincolo de orice realist

$cleaned = [];
foreach ($entries as $e) {
    if (!is_array($e)) continue;
    $kind = (string)($e['kind'] ?? '');
    $type = trim((string)($e['type'] ?? ''));
    $rawAmt = $e['amount'] ?? 0;
    if (!is_numeric($rawAmt)) continue;
    $amount = (int)round((float)$rawAmt);
    if ($amount <= 0) continue;
    if ($amount > AMOUNT_MAX) json_response(['error' => 'Sumă peste limita admisă'], 400);
    if ($kind === 'datorie') {
        if (!in_array($type, DATORII_TYPES, true)) json_response(['error' => "Tip datorie invalid: $type"], 400);
    } elseif ($kind === 'asset') {
        if (!in_array($type, ASSET_TYPES, true)) json_response(['error' => "Tip asset invalid: $type"], 400);
    } else {
        json_response(['error' => 'Kind invalid'], 400);
    }
    $cleaned[] = ['kind' => $kind, 'type' => $type, 'amount' => $amount];
}

if (count($cleaned) === 0) {
    json_response(['error' => 'Trebuie cel putin o intrare (datorie sau asset)'], 400);
}

$pdo = db();
$pdo->beginTransaction();
try {
    $sessionId = generate_sid($pdo);
    $stmt = $pdo->prepare('INSERT INTO submissions(uuid, session_id, optimist, judet, varsta, sex, persoane_intretinere, domeniu) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$uuid, $sessionId, $optimist, $judet, $varsta, $sex, $pi, $domeniu]);
    $rowId = (int)$pdo->lastInsertId();

    $ins = $pdo->prepare('INSERT INTO entries(submission_id, kind, type, amount) VALUES (?, ?, ?, ?)');
    foreach ($cleaned as $e) {
        $ins->execute([$rowId, $e['kind'], $e['type'], $e['amount']]);
    }
    $pdo->commit();
} catch (Throwable $t) {
    $pdo->rollBack();
    json_response(['error' => 'DB error: ' . $t->getMessage()], 500);
}

json_response(['ok' => true, 'submission_id' => $rowId, 'session_id' => $sessionId]);
