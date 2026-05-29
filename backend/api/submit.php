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

if (!array_key_exists('optimist', $body) || !is_bool($body['optimist'])) {
    json_response(['error' => 'Optimist invalid'], 400);
}
$optimist = $body['optimist'] ? 1 : 0;
$isSample = !empty($body['is_sample']);

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
    elseif (!in_array($domeniu, DOMENII, true)) json_response(['error' => 'Domeniu invalid'], 400);
}
// When user picks 'Altele', a custom free-text label travels in domeniu_other
// and gets stored in place of 'Altele'. We can't whitelist arbitrary input, so
// scrub it hard: keep only sensible label characters, collapse whitespace, strip
// stray punctuation, require something meaningful, and cap to the VARCHAR(80)
// column width. If nothing usable survives, fall back to the literal 'Altele'
// bucket. A value that matches a known category is folded back to its canonical form.
if ($domeniu === 'Altele') {
    $other = (string)($body['domeniu_other'] ?? '');
    // Drop control chars, then anything outside letters/digits/space + a small
    // set of label punctuation (replaced with a space so words don't fuse).
    $other = preg_replace('/[\x00-\x1F\x7F]/u', '', $other);
    $other = preg_replace('/[^\p{L}\p{N} &\/.,()\-]/u', ' ', $other);
    // Collapse runs of whitespace, then trim surrounding space and punctuation.
    $other = trim(preg_replace('/\s+/u', ' ', $other));
    $other = trim($other, " &/.,()-");
    if (mb_strlen($other) >= 2) {
        if (mb_strlen($other) > 80) $other = mb_substr($other, 0, 80);
        foreach (DOMENII as $d) {
            if (mb_strtolower($d) === mb_strtolower($other)) { $other = $d; break; }
        }
        $domeniu = $other;
    }
    // else: too short / empty after scrubbing — keep $domeniu as 'Altele'.
}

$entries = $body['entries'] ?? [];
if (!is_array($entries)) json_response(['error' => 'Entries invalid'], 400);
if (count($entries) > 50) json_response(['error' => 'Prea multe intrări'], 400);

const AMOUNT_MAX = 100_000_000_000; // 100 miliarde RON — safety cap, dincolo de orice realist

// EUR entries are converted to RON at submit time using the latest stored BNR
// rate (refreshed daily by fx-fetch.php). Resolved lazily so RON-only submits
// never touch the fx table or the network. If the table is empty (cron hasn't
// run yet) we fetch once and persist.
$eurRate = null; $eurRateResolved = false;
$resolveEurRate = function () use (&$eurRate, &$eurRateResolved): ?float {
    if ($eurRateResolved) return $eurRate;
    $eurRateResolved = true;
    $p = db();
    $latest = fx_latest($p);
    if ($latest === null) {
        try { $r = fx_fetch_bnr(); fx_store_rate($p, $r['date'], $r['eur_ron']); $latest = $r; }
        catch (Throwable $t) { $latest = null; }
    }
    $eurRate = $latest['eur_ron'] ?? null;
    return $eurRate;
};

$cleaned = [];
foreach ($entries as $e) {
    if (!is_array($e)) continue;
    $kind = (string)($e['kind'] ?? '');
    $type = trim((string)($e['type'] ?? ''));
    $currency = strtoupper((string)($e['currency'] ?? 'RON'));
    if (!in_array($currency, ['RON', 'EUR'], true)) $currency = 'RON';
    $rawAmt = $e['amount'] ?? null;
    // Integer-only: reject NaN, non-numeric, fractional. Frontend strips
    // separators already; this is the strict backend gate. The integer is in
    // the input currency; EUR is converted to RON right after.
    if (!is_numeric($rawAmt)) continue;
    $asFloat = (float)$rawAmt;
    if ($asFloat <= 0) continue;
    if ($asFloat !== (float)(int)$asFloat) continue; // reject 1234.56
    $amount = (int)$asFloat;
    if ($currency === 'EUR') {
        $rate = $resolveEurRate();
        if ($rate === null || $rate <= 0) {
            json_response(['error' => 'Cursul EUR indisponibil momentan. Reîncearcă în câteva minute sau introdu sumele în RON.'], 503);
        }
        $amount = fx_eur_to_ron($amount, $rate);
    }
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

    $ins = $pdo->prepare('INSERT INTO entries(submission_id, kind, type, amount, status) VALUES (?, ?, ?, ?, ?)');
    foreach ($cleaned as $e) {
        // Untouched "Date exemplu" submission → all entries forced to status=0.
        // Otherwise the normal outlier-band rule decides.
        $status = $isSample ? 0 : compute_entry_status($pdo, $e['kind'], $e['type'], (int)$e['amount']);
        $ins->execute([$rowId, $e['kind'], $e['type'], $e['amount'], $status]);
    }
    $pdo->commit();
} catch (Throwable $t) {
    $pdo->rollBack();
    json_response(['error' => 'DB error: ' . $t->getMessage()], 500);
}

json_response(['ok' => true, 'submission_id' => $rowId, 'session_id' => $sessionId]);
