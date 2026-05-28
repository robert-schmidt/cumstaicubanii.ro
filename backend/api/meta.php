<?php
declare(strict_types=1);
require __DIR__ . '/../db.php';
check_request_origin();

$pdo = db();
// Approved submissions = latest-per-UUID, not deleted, at least one status=1 entry.
// Shown on the form page as social proof ("X intrări aprobate").
$approvedSubmissions = (int)$pdo->query(
    "SELECT COUNT(*) FROM submissions s
     INNER JOIN (SELECT MAX(id) AS latest_id FROM submissions GROUP BY uuid) latest ON latest.latest_id = s.id
     WHERE s.deleted_at IS NULL
       AND EXISTS (SELECT 1 FROM entries e WHERE e.submission_id = s.id AND e.status = 1)"
)->fetchColumn();

$fx = fx_latest($pdo);

json_response([
    'datorii_types' => DATORII_TYPES,
    'asset_types' => ASSET_TYPES,
    'judete' => JUDETE,
    'domenii' => DOMENII,
    'sexe' => SEXE,
    'approved_submissions_count' => $approvedSubmissions,
    'fx' => $fx ? ['eur_ron' => $fx['eur_ron'], 'date' => $fx['rate_date']] : null,
]);
