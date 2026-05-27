<?php
// Admin board for moderating entries (approve / disable, grouped by submission).
//
// Auth: bcrypt hash stored in config.local.php under `admin_password_hash`
//       (config.local.php is gitignored — secrets never reach git).

declare(strict_types=1);
require __DIR__ . '/../db.php';

session_name('datorii_admin');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

$adminHash = null;
if (file_exists(__DIR__ . '/../config.local.php')) {
    $local = require __DIR__ . '/../config.local.php';
    if (is_array($local)) $adminHash = $local['admin_password_hash'] ?? null;
}

if (empty($adminHash)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Admin board not configured.\n";
    echo "Set 'admin_password_hash' in backend/config.local.php.\n";
    echo "Generate with: php -r 'echo password_hash(\"YOUR_PASSWORD\", PASSWORD_BCRYPT) . PHP_EOL;'\n";
    exit;
}

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf   = $_SESSION['csrf'];
$isAuth = !empty($_SESSION['admin']);

$action = $_GET['action'] ?? $_POST['action'] ?? null;
$method = $_SERVER['REQUEST_METHOD'];

// -------- POST actions --------
if ($method === 'POST' && $action === 'login') {
    rate_limit('admin-login:' . client_ip(), 5, 600);
    $pw = (string)($_POST['password'] ?? '');
    if (password_verify($pw, $adminHash)) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['csrf']  = bin2hex(random_bytes(16));
    } else {
        $_SESSION['login_error'] = 'Parolă incorectă.';
    }
    header('Location: /admin');
    exit;
}

if ($action === 'logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: /admin');
    exit;
}

if ($method === 'POST' && in_array($action, ['toggle', 'bulk_approve', 'bulk_disable', 'delete_submission', 'toggle_spam'], true)) {
    if (!$isAuth) { http_response_code(403); exit; }
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403); exit('Bad CSRF token');
    }
    $pdo = db();
    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('UPDATE entries SET status = 1 - status WHERE id = ?')->execute([$id]);
        }
    } elseif ($action === 'delete_submission') {
        $sid = (int)($_POST['submission_id'] ?? 0);
        if ($sid > 0) {
            $pdo->prepare('UPDATE submissions SET deleted_at = NOW() WHERE id = ?')->execute([$sid]);
        }
    } elseif ($action === 'toggle_spam') {
        $sid = (int)($_POST['submission_id'] ?? 0);
        if ($sid > 0) {
            // Read current state, then flip. If we're marking as spam, also
            // disable every entry on the submission (same effect as clicking
            // "Disable all") so the rose flag-state is consistent with the
            // spam decision. Unmarking spam does NOT auto-approve entries —
            // prior moderation decisions (manual approves/disables) must
            // stand, otherwise we'd silently undo admin work.
            $stmt = $pdo->prepare('SELECT is_spam FROM submissions WHERE id = ?');
            $stmt->execute([$sid]);
            $newSpam = 1 - (int)$stmt->fetchColumn();
            $pdo->prepare('UPDATE submissions SET is_spam = ? WHERE id = ?')->execute([$newSpam, $sid]);
            if ($newSpam === 1) {
                $pdo->prepare('UPDATE entries SET status = 0 WHERE submission_id = ?')->execute([$sid]);
            }
        }
    } else {
        $sid = (int)($_POST['submission_id'] ?? 0);
        $newStatus = $action === 'bulk_approve' ? 1 : 0;
        if ($sid > 0) {
            $pdo->prepare('UPDATE entries SET status = ? WHERE submission_id = ?')->execute([$newStatus, $sid]);
        }
    }
    $back = (string)($_POST['back'] ?? '/admin');
    if (!str_starts_with($back, '/')) $back = '/admin';
    header('Location: ' . $back);
    exit;
}

// -------- GET render --------
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

if (!$isAuth) {
    render_login();
    exit;
}

// Lazy-load fragment endpoint: returns ONLY the next batch of submission cards
// + an optional new sentinel. No shell, no JS, no header.
if ($action === 'fragment') {
    header('Content-Type: text/html; charset=utf-8');
    render_admin_cards((int)($_GET['offset'] ?? 0));
    exit;
}

render_admin();

// =============================================================================
// Render helpers
// =============================================================================

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function fmt_amount(int $n): string {
    return number_format($n, 0, ',', '.') . ' RON';
}

function page_shell(string $bodyHtml, string $title = 'Admin · cumstaicubanii.ro'): void {
    $t = h($title);
    echo <<<HTML
<!doctype html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>{$t}</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif; }</style>
</head>
<body class="bg-slate-50 text-slate-900 min-h-screen">
{$bodyHtml}
</body>
</html>
HTML;
}

function render_login(): void {
    $err = $_SESSION['login_error'] ?? null;
    unset($_SESSION['login_error']);
    $errHtml = $err ? '<p class="text-rose-600 text-sm mb-3">' . h($err) . '</p>' : '';
    $body = <<<HTML
<div class="min-h-screen flex items-center justify-center px-4">
  <form method="POST" action="/admin?action=login" class="w-full max-w-sm bg-white rounded-2xl border border-slate-200 p-6 shadow-sm">
    <h1 class="text-lg font-semibold mb-1">Admin · cumstaicubanii.ro</h1>
    <p class="text-sm text-slate-500 mb-5">Autentificare necesară.</p>
    {$errHtml}
    <label class="block text-xs font-medium text-slate-600 mb-1">Parolă</label>
    <input type="password" name="password" autocomplete="current-password" required
           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900/20 focus:border-slate-500">
    <button type="submit" class="mt-4 w-full px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-medium hover:bg-slate-800">Intră</button>
  </form>
</div>
HTML;
    page_shell($body, 'Login · admin');
}

// Parse + validate filter / sort params common to render_admin and render_admin_cards.
function admin_query_params(): array {
    $kind   = (string)($_GET['kind']   ?? '');
    $type   = (string)($_GET['type']   ?? '');
    $status = $_GET['status'] ?? '';
    $spam   = $_GET['spam'] ?? '';
    $sort   = (string)($_GET['sort']   ?? 'recent');

    if ($kind   !== '' && !in_array($kind, ['datorie', 'asset'], true)) $kind = '';
    if ($status !== '' && !in_array((string)$status, ['0', '1'], true)) $status = '';
    if ($spam   !== '' && !in_array((string)$spam,   ['0', '1'], true)) $spam = '';
    if (!in_array($sort, ['recent', 'amount_desc', 'amount_asc', 'community_flagged', 'community_validated'], true)) $sort = 'recent';

    // Admin sees non-deleted by default. Spam filter is opt-in (default shows
    // both spam and non-spam, so admin can spot patterns).
    $where = ['s.deleted_at IS NULL']; $params = [];
    if ($kind !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM entries e WHERE e.submission_id = s.id AND e.kind = ?)';
        $params[] = $kind;
    }
    if ($type !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM entries e WHERE e.submission_id = s.id AND e.type = ?)';
        $params[] = $type;
    }
    if ($status !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM entries e WHERE e.submission_id = s.id AND e.status = ?)';
        $params[] = (int)$status;
    }
    if ($spam !== '') {
        $where[] = 's.is_spam = ?';
        $params[] = (int)$spam;
    }

    if ($sort === 'amount_desc')              $order = 'ORDER BY (SELECT MAX(amount) FROM entries e WHERE e.submission_id = s.id) DESC, s.id DESC';
    elseif ($sort === 'amount_asc')           $order = 'ORDER BY (SELECT MAX(amount) FROM entries e WHERE e.submission_id = s.id) ASC, s.id DESC';
    elseif ($sort === 'community_flagged')    $order = 'ORDER BY s.community_false_count DESC, s.id DESC';
    elseif ($sort === 'community_validated')  $order = 'ORDER BY s.community_true_count  DESC, s.id DESC';
    else                                      $order = 'ORDER BY s.id DESC';

    return [
        'kind'      => $kind,
        'type'      => $type,
        'status'    => $status,
        'spam'      => $spam,
        'sort'      => $sort,
        'whereSql'  => 'WHERE ' . implode(' AND ', $where),
        'params'    => $params,
        'orderSql'  => $order,
    ];
}

// Fetch one batch of submissions + their entries, returning everything needed
// to render a slice. Reused by both initial render and lazy-load fragments.
function admin_fetch_batch(PDO $pdo, array $q, int $offset, int $limit): array {
    $sStmt = $pdo->prepare(
        "SELECT s.id, s.uuid, s.session_id, s.created_at, s.judet, s.varsta,
                s.sex, s.persoane_intretinere, s.domeniu, s.optimist, s.is_spam,
                s.community_true_count, s.community_false_count
         FROM submissions s
         {$q['whereSql']}
         {$q['orderSql']}
         LIMIT $limit OFFSET $offset"
    );
    $sStmt->execute($q['params']);
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
        foreach ($eStmt as $row) $entriesBySubmission[$row['submission_id']][] = $row;
    }

    return [$submissions, $entriesBySubmission];
}

function admin_back_url(array $q): string {
    return '/admin?' . http_build_query(array_filter([
        'kind' => $q['kind'], 'type' => $q['type'], 'status' => $q['status'],
        'spam' => $q['spam'], 'sort' => $q['sort'],
    ], fn($v) => $v !== '' && $v !== null));
}

function render_admin_cards(int $offset): void {
    global $csrf;
    $pdo = db();
    $perPage = 30;
    $q = admin_query_params();

    [$submissions, $entriesBySubmission] = admin_fetch_batch($pdo, $q, $offset, $perPage);
    $backUrl = admin_back_url($q);

    $html = '';
    foreach ($submissions as $s) {
        $sid = (int)$s['id'];
        $html .= render_submission_card($s, $entriesBySubmission[$sid] ?? [], $csrf, $backUrl);
    }

    // If this batch was full, append a new sentinel for the NEXT batch.
    // Frontend JS observes the sentinel and fetches more when scrolled into view.
    if (count($submissions) === $perPage) {
        $nextOffset = $offset + $perPage;
        $html .= "<div id=\"lazy-sentinel\" data-offset=\"{$nextOffset}\" class=\"h-1\"></div>";
    }
    echo $html;
}

function render_admin(): void {
    global $csrf;
    $pdo = db();
    $perPage = 30;
    $q = admin_query_params();

    [$submissions, $entriesBySubmission] = admin_fetch_batch($pdo, $q, 0, $perPage);
    $backUrl = admin_back_url($q);

    // Header totals (over the full non-deleted set, ignoring filters — so the
    // admin sees the global moderation health).
    $totalsRow = $pdo->query("
        SELECT
          SUM(CASE WHEN e.status = 1 THEN 1 ELSE 0 END) AS approved,
          SUM(CASE WHEN e.status = 0 THEN 1 ELSE 0 END) AS flagged
        FROM entries e
        JOIN submissions s ON s.id = e.submission_id
        WHERE s.deleted_at IS NULL
    ")->fetch();
    $approved = (int)($totalsRow['approved'] ?? 0);
    $flagged  = (int)($totalsRow['flagged']  ?? 0);
    $totalSubs = (int)$pdo->prepare("SELECT COUNT(*) FROM submissions s {$q['whereSql']}")
        ->fetchColumn(); // placeholder, replaced below using prepared statement
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM submissions s {$q['whereSql']}");
    $cnt->execute($q['params']);
    $totalSubs = (int)$cnt->fetchColumn();

    $typesAll = array_merge(DATORII_TYPES, ASSET_TYPES);

    // ---- Header ----
    $header = <<<HTML
<header class="bg-white border-b border-slate-200 sticky top-0 z-10">
  <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
    <div class="flex items-baseline gap-3">
      <h1 class="font-semibold">Admin · cumstaicubanii.ro</h1>
      <span class="text-xs text-slate-500">
        {$approved} entries aprobate · {$flagged} flagged · <strong>{$totalSubs}</strong> submissions filtrate
      </span>
    </div>
    <a href="/admin?action=logout" class="text-sm text-slate-500 hover:text-rose-600">Ieși</a>
  </div>
</header>
HTML;

    // ---- Filters ----
    $kindOpts   = render_options(['' => 'Toate'] + ['datorie' => 'datorie', 'asset' => 'asset'], $q['kind']);
    $typeOpts   = render_options(['' => 'Toate tipurile'] + array_combine($typesAll, $typesAll), $q['type']);
    $statusOpts = render_options(['' => 'Toate', '1' => 'aprobate', '0' => 'flagged'], (string)$q['status']);
    $spamOpts   = render_options(['' => 'Toate', '0' => 'non-spam', '1' => '🚫 doar spam'], (string)$q['spam']);
    $sortOpts   = render_options([
        'recent'              => 'Cele mai recente',
        'amount_desc'         => 'Suma (mare → mică)',
        'amount_asc'          => 'Suma (mică → mare)',
        'community_flagged'   => '✗ Cele mai semnalate (comunitate)',
        'community_validated' => '✓ Cele mai validate (comunitate)',
    ], $q['sort']);

    $filters = <<<HTML
<form method="GET" action="/admin" class="bg-white border-b border-slate-200">
  <div class="max-w-7xl mx-auto px-4 py-3 flex flex-wrap items-end gap-3">
    <label class="text-xs text-slate-600">Kind
      <select name="kind" class="block mt-1 rounded-lg border border-slate-300 px-2 py-1.5 text-sm">{$kindOpts}</select>
    </label>
    <label class="text-xs text-slate-600">Tip
      <select name="type" class="block mt-1 rounded-lg border border-slate-300 px-2 py-1.5 text-sm min-w-[180px]">{$typeOpts}</select>
    </label>
    <label class="text-xs text-slate-600">Status
      <select name="status" class="block mt-1 rounded-lg border border-slate-300 px-2 py-1.5 text-sm">{$statusOpts}</select>
    </label>
    <label class="text-xs text-slate-600">Spam
      <select name="spam" class="block mt-1 rounded-lg border border-slate-300 px-2 py-1.5 text-sm">{$spamOpts}</select>
    </label>
    <label class="text-xs text-slate-600">Sortare
      <select name="sort" class="block mt-1 rounded-lg border border-slate-300 px-2 py-1.5 text-sm">{$sortOpts}</select>
    </label>
    <button type="submit" class="px-3 py-1.5 rounded-lg bg-slate-900 text-white text-sm">Aplică</button>
    <a href="/admin" class="text-sm text-slate-500 hover:text-slate-900">Reset</a>
  </div>
</form>
HTML;

    // ---- Initial cards + sentinel ----
    $cards = '';
    foreach ($submissions as $s) {
        $sid = (int)$s['id'];
        $cards .= render_submission_card($s, $entriesBySubmission[$sid] ?? [], $csrf, $backUrl);
    }
    if (!$submissions) {
        $cards = '<div class="bg-white rounded-2xl border border-slate-200 p-8 text-center text-slate-400">Niciun submission pentru filtrele alese.</div>';
    }
    $sentinel = '';
    if (count($submissions) === $perPage) {
        $nextOffset = $perPage;
        $sentinel = "<div id=\"lazy-sentinel\" data-offset=\"{$nextOffset}\" class=\"h-1\"></div>";
    }

    // ---- Lazy-load JS + scroll restoration after form actions ----
    $lazyJs = <<<'JS'
<script>
(function () {
  // 1) Preserve scroll position across approve/disable/delete form submits.
  //    Each form posts and the server 302s back to /admin; without this the
  //    new page would load scrolled to top.
  var SCROLL_KEY = 'admin_scroll_y';
  try {
    var saved = sessionStorage.getItem(SCROLL_KEY);
    if (saved !== null) {
      sessionStorage.removeItem(SCROLL_KEY);
      window.scrollTo(0, parseFloat(saved));
    }
  } catch (e) {}
  document.addEventListener('submit', function () {
    try { sessionStorage.setItem(SCROLL_KEY, String(window.scrollY)); } catch (e) {}
  }, true);

  // 2) Lazy load: when sentinel scrolls into view, fetch next batch.
  function observeSentinel() {
    var sentinel = document.getElementById('lazy-sentinel');
    if (!sentinel) return;
    var io = new IntersectionObserver(function (entries) {
      if (!entries[0].isIntersecting) return;
      io.disconnect();
      var offset = sentinel.dataset.offset;
      var params = new URLSearchParams(window.location.search);
      params.set('action', 'fragment');
      params.set('offset', offset);
      fetch('/admin?' + params.toString(), { credentials: 'same-origin' })
        .then(function (r) { return r.text(); })
        .then(function (html) {
          var tmp = document.createElement('div');
          tmp.innerHTML = html;
          var list = document.getElementById('lazy-list');
          sentinel.remove();
          while (tmp.firstChild) list.appendChild(tmp.firstChild);
          observeSentinel();
        })
        .catch(function (e) { console.error('lazy load failed', e); });
    }, { rootMargin: '400px' });
    io.observe(sentinel);
  }
  observeSentinel();
})();
</script>
JS;

    $main = "<main class=\"max-w-7xl mx-auto px-4 py-6\"><div id=\"lazy-list\" class=\"space-y-4\">{$cards}{$sentinel}</div></main>{$lazyJs}";

    page_shell($header . $filters . $main, 'Admin · cumstaicubanii.ro');
}

function render_options(array $opts, string $selected): string {
    $html = '';
    foreach ($opts as $val => $label) {
        $sel = (string)$val === $selected ? ' selected' : '';
        $html .= '<option value="' . h((string)$val) . '"' . $sel . '>' . h((string)$label) . '</option>';
    }
    return $html;
}

function render_submission_card(array $s, array $entries, string $csrf, string $backUrl): string {
    $sid       = (int)$s['id'];
    $sessionId = h((string)$s['session_id']);
    $created   = h((string)$s['created_at']);
    $judet     = h((string)($s['judet'] ?? '—'));
    $varsta    = $s['varsta'] !== null ? h((string)$s['varsta']) . ' ani' : '—';
    $sex       = h((string)($s['sex'] ?? '—'));
    $pi        = $s['persoane_intretinere'] !== null ? 'PI:' . h((string)$s['persoane_intretinere']) : '';
    $domeniu   = h((string)($s['domeniu'] ?? '—'));
    $optimist  = (int)$s['optimist'] === 1 ? '😊' : '😟';
    $isSpam    = (int)($s['is_spam'] ?? 0) === 1;

    $nApproved = 0; $nTotal = count($entries);
    foreach ($entries as $e) if ((int)$e['status'] === 1) $nApproved++;
    $allApproved = $nApproved === $nTotal;
    $allFlagged  = $nApproved === 0;

    $ratioColor = $allFlagged ? 'bg-rose-100 text-rose-700'
                : ($allApproved ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700');

    // Entry rows
    $entryRows = '';
    foreach ($entries as $e) {
        $eid    = (int)$e['id'];
        $eKind  = h($e['kind']);
        $eType  = h($e['type']);
        $eAmt   = h(fmt_amount((int)$e['amount']));
        $eStat  = (int)$e['status'];

        $badge = $eStat === 1
            ? '<span class="inline-block px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 text-xs font-medium">ok</span>'
            : '<span class="inline-block px-2 py-0.5 rounded-full bg-rose-100 text-rose-700 text-xs font-medium">flagged</span>';

        $toggleLabel = $eStat === 1 ? 'Disable' : 'Approve';
        $toggleCls   = $eStat === 1 ? 'bg-rose-600 hover:bg-rose-700' : 'bg-emerald-600 hover:bg-emerald-700';

        $entryRows .= <<<HTML
<tr class="border-t border-slate-100">
  <td class="px-2 py-1.5 text-xs text-slate-400 w-12">#{$eid}</td>
  <td class="px-2 py-1.5 text-xs w-20">{$eKind}</td>
  <td class="px-2 py-1.5 text-sm">{$eType}</td>
  <td class="px-2 py-1.5 text-sm font-mono text-right">{$eAmt}</td>
  <td class="px-2 py-1.5 w-20">{$badge}</td>
  <td class="px-2 py-1.5 text-right">
    <form method="POST" action="/admin?action=toggle" class="inline">
      <input type="hidden" name="csrf" value="{$csrf}">
      <input type="hidden" name="id" value="{$eid}">
      <input type="hidden" name="back" value="{$backUrl}">
      <button type="submit" class="px-2.5 py-0.5 rounded-md text-white text-xs font-medium {$toggleCls}">{$toggleLabel}</button>
    </form>
  </td>
</tr>
HTML;
    }

    // Bulk action buttons (disabled when not applicable)
    $approveDisabled = $allApproved ? 'opacity-40 cursor-not-allowed' : '';
    $disableDisabled = $allFlagged  ? 'opacity-40 cursor-not-allowed' : '';

    $spamBadge = $isSpam
        ? '<span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-rose-100 text-rose-700 font-semibold">🚫 SPAM</span>'
        : '';

    $cTrue  = (int)($s['community_true_count']  ?? 0);
    $cFalse = (int)($s['community_false_count'] ?? 0);
    $commCls = $cFalse > $cTrue
        ? 'bg-rose-50 text-rose-700 border-rose-200'
        : ($cTrue > 0 ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-slate-50 text-slate-500 border-slate-200');
    $commBadge = ($cTrue + $cFalse) > 0
        ? "<span class=\"inline-flex items-center gap-1 px-2 py-0.5 rounded-full border text-xs font-mono tabular-nums {$commCls}\" title=\"Voturi comunitate: ▲ plauzibil / ▼ suspect\">▲{$cTrue} · ▼{$cFalse}</span>"
        : '';
    $spamBtnLabel = $isSpam ? '✓ unmark spam' : '🚫 spam';
    $spamBtnCls   = $isSpam
        ? 'text-rose-700 border border-rose-300 bg-rose-50 hover:bg-rose-100'
        : 'text-slate-600 border border-slate-300 hover:bg-rose-50 hover:text-rose-700 hover:border-rose-300';
    $sectionCls = 'bg-white rounded-2xl border overflow-hidden ' . ($isSpam ? 'border-rose-200 ring-1 ring-rose-100' : 'border-slate-200');

    return <<<HTML
<section class="{$sectionCls}" data-submission="{$sid}">
  <header class="bg-slate-50 px-4 py-3 flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-slate-200">
    <div class="flex items-baseline gap-2 flex-1 min-w-0">
      <span class="text-xs text-slate-400">#{$sid}</span>
      <span class="font-mono text-sm text-slate-800">{$sessionId}</span>
      {$spamBadge}
      {$commBadge}
      <span class="text-xs text-slate-500 truncate">{$judet} · {$varsta} · {$sex} · {$pi} · {$domeniu} · {$optimist}</span>
      <span class="text-xs text-slate-400 ml-auto">{$created}</span>
    </div>
    <div class="flex items-center gap-2 shrink-0">
      <span class="text-xs px-2 py-0.5 rounded-full {$ratioColor}">{$nApproved}/{$nTotal} ok</span>
      <form method="POST" action="/admin?action=bulk_approve" class="inline">
        <input type="hidden" name="csrf" value="{$csrf}">
        <input type="hidden" name="submission_id" value="{$sid}">
        <input type="hidden" name="back" value="{$backUrl}">
        <button type="submit" class="px-3 py-1 rounded-md text-white text-xs font-medium bg-emerald-600 hover:bg-emerald-700 {$approveDisabled}">Approve all</button>
      </form>
      <form method="POST" action="/admin?action=bulk_disable" class="inline">
        <input type="hidden" name="csrf" value="{$csrf}">
        <input type="hidden" name="submission_id" value="{$sid}">
        <input type="hidden" name="back" value="{$backUrl}">
        <button type="submit" class="px-3 py-1 rounded-md text-white text-xs font-medium bg-rose-600 hover:bg-rose-700 {$disableDisabled}">Disable all</button>
      </form>
      <form method="POST" action="/admin?action=toggle_spam" class="inline">
        <input type="hidden" name="csrf" value="{$csrf}">
        <input type="hidden" name="submission_id" value="{$sid}">
        <input type="hidden" name="back" value="{$backUrl}">
        <button type="submit" class="px-3 py-1 rounded-md text-xs font-medium {$spamBtnCls}" title="Marchează ca spam — exclus din toate agregările dar rămâne vizibil în admin">{$spamBtnLabel}</button>
      </form>
      <form method="POST" action="/admin?action=delete_submission" class="inline"
            onsubmit="return confirm('Sigur dispari rândul #{$sid} complet? Datele rămân în DB (soft-delete) dar nu mai apar în admin sau în statistici.');">
        <input type="hidden" name="csrf" value="{$csrf}">
        <input type="hidden" name="submission_id" value="{$sid}">
        <input type="hidden" name="back" value="{$backUrl}">
        <button type="submit" class="px-3 py-1 rounded-md text-xs font-medium text-slate-600 border border-slate-300 hover:bg-slate-900 hover:text-white" title="Soft-delete: rândul dispare complet din admin și statistici">🗑️</button>
      </form>
    </div>
  </header>
  <table class="w-full">
    <tbody>
      {$entryRows}
    </tbody>
  </table>
</section>
HTML;
}
