<?php
declare(strict_types=1);
require __DIR__ . '/../db.php';
cors_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
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

$cleaned = [];
foreach ($entries as $e) {
    if (!is_array($e)) continue;
    $kind = (string)($e['kind'] ?? '');
    $type = trim((string)($e['type'] ?? ''));
    $amount = (float)($e['amount'] ?? 0);
    if ($amount <= 0) continue;
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
