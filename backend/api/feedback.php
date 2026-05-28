<?php
// Public "community feedback" endpoint.
//
// GET  /api/feedback.php  → paginated list of submissions with their entries
//                           (read-only) plus the community vote counters and
//                           the caller's own vote per submission (if any).
// POST /api/feedback.php  → cast or flip a vote on a submission.
//
// Auth: none. Anti-abuse leans on (a) the localStorage-derived voter UUID
// stored in `community_votes` with a UNIQUE (submission_id, voter_uuid) key,
// and (b) a per-IP rate limit on POSTs. Easy to defeat by clearing
// localStorage, accepted as the cost of keeping the feature anonymous.
//
// Voting granularity is per *submission* (one person's full snapshot) — not
// per individual entry — because a snapshot is read holistically: if the
// totals look implausible the whole submission is suspect, and asking the
// voter to flag entries one-by-one is more friction than signal.
//
// A voter can flip their vote freely (up→down, down→up). The denormalized
// counters always move in lock-step (one column +1, the other -1) so a
// voter's net contribution stays exactly 1, no matter how many times they
// flip. Per-IP rate limiting (60/h) handles spam.

declare(strict_types=1);
require __DIR__ . '/../db.php';
check_request_origin();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    feedback_list();
} elseif ($method === 'POST') {
    feedback_vote();
} else {
    json_response(['error' => 'Method not allowed'], 405);
}

function valid_voter_uuid(string $u): bool {
    return $u !== '' && (bool)preg_match('/^[0-9a-f-]{16,64}$/i', $u);
}

function feedback_list(): void {
    $pdo = db();

    $voterUuid = trim((string)($_GET['uuid'] ?? ''));
    if (!valid_voter_uuid($voterUuid)) $voterUuid = '';

    $kind = (string)($_GET['kind'] ?? '');
    if ($kind !== '' && !in_array($kind, ['datorie', 'asset'], true)) {
        json_response(['error' => 'Kind invalid'], 400);
    }

    $type = (string)($_GET['type'] ?? '');
    if ($type !== '' && !in_array($type, array_merge(DATORII_TYPES, ASSET_TYPES), true)) {
        json_response(['error' => 'Tip invalid'], 400);
    }

    $judet = (string)($_GET['judet'] ?? '');
    if ($judet !== '' && !in_array($judet, JUDETE, true)) {
        json_response(['error' => 'Judet invalid'], 400);
    }

    $status = (string)($_GET['status'] ?? '');
    if (!in_array($status, ['', '0', '1'], true)) {
        json_response(['error' => 'Status invalid'], 400);
    }

    $sort = (string)($_GET['sort'] ?? 'recent');
    if (!in_array($sort, ['recent', 'most_flagged', 'most_validated'], true)) {
        $sort = 'recent';
    }

    $offset  = max(0, (int)($_GET['offset'] ?? 0));
    $perPage = 30;

    // Submissions visible to the public: not soft-deleted, not spam-marked.
    // kind/type/status filters apply to ANY entry of the submission so we
    // keep the "1 submission card per person" framing intact.
    $where  = ['s.deleted_at IS NULL', 's.is_spam = 0'];
    $params = [];
    if ($kind !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM entries e WHERE e.submission_id = s.id AND e.kind = ?)';
        $params[] = $kind;
    }
    if ($type !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM entries e WHERE e.submission_id = s.id AND e.type = ?)';
        $params[] = $type;
    }
    if ($judet !== '') {
        $where[] = 's.judet = ?';
        $params[] = $judet;
    }
    if ($status !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM entries e WHERE e.submission_id = s.id AND e.status = ?)';
        $params[] = (int)$status;
    }
    $whereSql = 'WHERE ' . implode(' AND ', $where);

    if ($sort === 'most_flagged') {
        $orderSql = 'ORDER BY s.community_false_count DESC, s.id DESC';
    } elseif ($sort === 'most_validated') {
        $orderSql = 'ORDER BY s.community_true_count DESC, s.id DESC';
    } else {
        $orderSql = 'ORDER BY s.id DESC';
    }

    // Deliberately NOT selecting session_id — it's the credential a user
    // would type into SidLogin to recover their dashboard, so exposing it on
    // a public list is equivalent to publishing 8-char passwords. Admin
    // panel needs it (it's authenticated); /feedback does not.
    $sStmt = $pdo->prepare(
        "SELECT s.id, s.created_at, s.judet, s.varsta, s.sex,
                s.persoane_intretinere, s.domeniu, s.optimist,
                s.community_true_count, s.community_false_count
         FROM submissions s
         $whereSql
         $orderSql
         LIMIT $perPage OFFSET $offset"
    );
    $sStmt->execute($params);
    $submissions = $sStmt->fetchAll();

    $entriesBySubmission = [];
    if ($submissions) {
        $subIds = array_column($submissions, 'id');
        $in = str_repeat('?,', count($subIds) - 1) . '?';
        $eStmt = $pdo->prepare(
            "SELECT id, submission_id, kind, type, amount, status
             FROM entries
             WHERE submission_id IN ($in)
             ORDER BY status ASC, amount DESC"
        );
        $eStmt->execute($subIds);
        foreach ($eStmt as $row) {
            $entriesBySubmission[(int)$row['submission_id']][] = $row;
        }
    }

    // Caller's own votes — only when we have a usable voter id.
    $myVotes = [];
    if ($voterUuid !== '' && $submissions) {
        $subIds = array_column($submissions, 'id');
        $in2    = str_repeat('?,', count($subIds) - 1) . '?';
        $vStmt  = $pdo->prepare(
            "SELECT submission_id, vote, updated_at FROM community_votes
             WHERE voter_uuid = ? AND submission_id IN ($in2)"
        );
        $vStmt->execute(array_merge([$voterUuid], $subIds));
        foreach ($vStmt as $r) {
            $myVotes[(int)$r['submission_id']] = [
                'vote'       => (int)$r['vote'],
                'updated_at' => $r['updated_at'],
            ];
        }
    }

    $out = [];
    foreach ($submissions as $s) {
        $sid = (int)$s['id'];
        $out[] = [
            'id'                   => $sid,
            'created_at'           => $s['created_at'],
            'judet'                => $s['judet'],
            'varsta'               => $s['varsta'] !== null ? (int)$s['varsta'] : null,
            'sex'                  => $s['sex'],
            'persoane_intretinere' => $s['persoane_intretinere'] !== null ? (int)$s['persoane_intretinere'] : null,
            'domeniu'              => $s['domeniu'],
            'optimist'             => (bool)$s['optimist'],
            'community_true'       => (int)$s['community_true_count'],
            'community_false'      => (int)$s['community_false_count'],
            'entries' => array_map(fn($e) => [
                'id'     => (int)$e['id'],
                'kind'   => $e['kind'],
                'type'   => $e['type'],
                'amount' => (int)$e['amount'],
                'status' => (int)$e['status'],
            ], $entriesBySubmission[$sid] ?? []),
        ];
    }

    json_response([
        'submissions' => $out,
        'my_votes'    => (object)$myVotes,
        'next_offset' => count($submissions) === $perPage ? $offset + $perPage : null,
    ]);
}

function feedback_vote(): void {
    // Per-IP rate limit. 60 votes/hour is generous for honest users while
    // making scripted spam noticeable.
    rate_limit('feedback-vote:' . client_ip(), 60, 3600);

    if (($_SERVER['CONTENT_LENGTH'] ?? 0) > 4096) {
        json_response(['error' => 'Payload too large'], 413);
    }
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) json_response(['error' => 'Invalid JSON'], 400);

    $voterUuid = trim((string)($body['uuid'] ?? ''));
    if (!valid_voter_uuid($voterUuid)) {
        json_response(['error' => 'Invalid uuid'], 400);
    }
    $submissionId = (int)($body['submission_id'] ?? 0);
    if ($submissionId <= 0) {
        json_response(['error' => 'Submission ID invalid'], 400);
    }
    if (!array_key_exists('vote', $body) || !is_bool($body['vote'])) {
        json_response(['error' => 'Vote invalid (must be boolean)'], 400);
    }
    $vote = $body['vote'] ? 1 : 0;

    $pdo = db();

    // Refuse votes on submissions that aren't publicly visible.
    $check = $pdo->prepare(
        "SELECT id FROM submissions
         WHERE id = ? AND deleted_at IS NULL AND is_spam = 0"
    );
    $check->execute([$submissionId]);
    if (!$check->fetchColumn()) {
        json_response(['error' => 'Submission not found'], 404);
    }

    $action = null;

    $pdo->beginTransaction();
    try {
        $existing = $pdo->prepare(
            'SELECT vote FROM community_votes
             WHERE submission_id = ? AND voter_uuid = ?'
        );
        $existing->execute([$submissionId, $voterUuid]);
        $prev = $existing->fetch();

        if ($prev) {
            $prevVote = (int)$prev['vote'];
            if ($prevVote === $vote) {
                $action = 'noop';
            } else {
                // Flip: swap the voter's contribution between the two counters.
                $pdo->prepare(
                    'UPDATE community_votes SET vote = ?
                     WHERE submission_id = ? AND voter_uuid = ?'
                )->execute([$vote, $submissionId, $voterUuid]);
                if ($vote === 1) {
                    $pdo->prepare(
                        'UPDATE submissions
                         SET community_true_count  = community_true_count + 1,
                             community_false_count = GREATEST(community_false_count - 1, 0)
                         WHERE id = ?'
                    )->execute([$submissionId]);
                } else {
                    $pdo->prepare(
                        'UPDATE submissions
                         SET community_false_count = community_false_count + 1,
                             community_true_count  = GREATEST(community_true_count - 1, 0)
                         WHERE id = ?'
                    )->execute([$submissionId]);
                }
                $action = 'flipped';
            }
        } else {
            $pdo->prepare(
                'INSERT INTO community_votes (submission_id, voter_uuid, vote) VALUES (?, ?, ?)'
            )->execute([$submissionId, $voterUuid, $vote]);
            $col = $vote === 1 ? 'community_true_count' : 'community_false_count';
            $pdo->prepare("UPDATE submissions SET $col = $col + 1 WHERE id = ?")->execute([$submissionId]);
            $action = 'inserted';
        }
        $pdo->commit();
    } catch (Throwable $t) {
        $pdo->rollBack();
        json_response(['error' => 'DB error: ' . $t->getMessage()], 500);
    }

    $stmt = $pdo->prepare('SELECT community_true_count, community_false_count FROM submissions WHERE id = ?');
    $stmt->execute([$submissionId]);
    $r = $stmt->fetch();

    json_response([
        'ok'              => true,
        'changed'         => $action !== 'noop',
        'community_true'  => (int)$r['community_true_count'],
        'community_false' => (int)$r['community_false_count'],
        'my_vote'         => $vote,
    ]);
}
