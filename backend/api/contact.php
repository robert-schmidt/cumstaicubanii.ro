<?php
// Public contact form endpoint.
//
// POST /api/contact.php → validate the message and relay it by email through
//                         Postmark to the site owner.
//
// Auth: none (public form). Anti-abuse leans on (a) a per-IP rate limit,
// (b) a hidden honeypot field that real users never fill, and (c) basic
// length/format validation. The visitor's address is placed in Reply-To so a
// reply from the inbox goes straight back to them, while the actual sender is
// our verified Postmark signature.
//
// Secrets (Postmark token, from/to addresses) live in config.local.php, which
// is gitignored — they never reach git. If the token is missing the endpoint
// responds 503 so the frontend can show a graceful error.

declare(strict_types=1);
require __DIR__ . '/../db.php';
check_request_origin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

// 5 messages/hour per IP — generous for a human, noisy for a script.
rate_limit('contact:' . client_ip(), 5, 3600);

if (((int)($_SERVER['CONTENT_LENGTH'] ?? 0)) > 16384) {
    json_response(['error' => 'Mesaj prea lung.'], 413);
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    json_response(['error' => 'Cerere invalidă.'], 400);
}

// Honeypot: a field hidden from real users. If it's filled, silently accept
// (pretend success) so bots don't learn they were caught.
if (trim((string)($body['website'] ?? '')) !== '') {
    json_response(['ok' => true]);
}

$name    = trim((string)($body['name'] ?? ''));
$email   = trim((string)($body['email'] ?? ''));
$message = trim((string)($body['message'] ?? ''));

$errors = [];
if ($name === '' || mb_strlen($name) > 120) {
    $errors['name'] = 'Spune-ne cum te cheamă (max. 120 caractere).';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
    $errors['email'] = 'Introdu o adresă de email validă.';
}
if (mb_strlen($message) < 10 || mb_strlen($message) > 5000) {
    $errors['message'] = 'Mesajul trebuie să aibă între 10 și 5000 de caractere.';
}
if ($errors) {
    json_response(['error' => 'Te rugăm verifică datele.', 'fields' => $errors], 422);
}

// --- Config (Postmark) ------------------------------------------------------
$conf = [];
if (file_exists(__DIR__ . '/../config.local.php')) {
    $loaded = require __DIR__ . '/../config.local.php';
    if (is_array($loaded)) $conf = $loaded;
}
$token = (string)($conf['postmark_token'] ?? (getenv('POSTMARK_TOKEN') ?: ''));
$from  = (string)($conf['contact_from']  ?? 'no-reply@cumstaicubanii.ro');
$to    = (string)($conf['contact_to']    ?? 'contact@cumstaicubanii.ro');

if ($token === '') {
    json_response(['error' => 'Trimiterea emailurilor nu este configurată momentan.'], 503);
}

// --- Compose ----------------------------------------------------------------
$subject  = 'Contact cumstaicubanii.ro — ' . mb_substr($name, 0, 60);
$textBody = "Mesaj nou din formularul de contact:\n\n"
          . "Nume:  {$name}\n"
          . "Email: {$email}\n"
          . "IP:    " . client_ip() . "\n"
          . "Dată:  " . date('Y-m-d H:i:s') . "\n\n"
          . "--------------------------------------------------\n"
          . $message . "\n";

$payload = [
    'From'          => $from,
    'To'            => $to,
    'ReplyTo'       => $email,
    'Subject'       => $subject,
    'TextBody'      => $textBody,
    'MessageStream' => 'outbound',
];

// --- Send via Postmark ------------------------------------------------------
$ch = curl_init('https://api.postmarkapp.com/email');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_HTTPHEADER     => [
        'Accept: application/json',
        'Content-Type: application/json',
        'X-Postmark-Server-Token: ' . $token,
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
]);
$resp   = curl_exec($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$cerr   = curl_error($ch);
curl_close($ch);

if ($resp === false || $status === 0) {
    error_log('[contact] Postmark transport error: ' . $cerr);
    json_response(['error' => 'Nu am putut trimite mesajul. Reîncearcă mai târziu.'], 502);
}
if ($status < 200 || $status >= 300) {
    error_log('[contact] Postmark API error ' . $status . ': ' . $resp);
    json_response(['error' => 'Nu am putut trimite mesajul. Reîncearcă mai târziu.'], 502);
}

json_response(['ok' => true]);
