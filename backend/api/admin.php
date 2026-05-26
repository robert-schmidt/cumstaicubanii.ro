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

if ($method === 'POST' && in_array($action, ['toggle', 'bulk_approve', 'bulk_disable'], true)) {
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

function render_admin(): void {
    global $csrf;
    $pdo = db();

    // -------- Filters & sort --------
    $kind   = (string)($_GET['kind']   ?? '');
    $type   = (string)($_GET['type']   ?? '');
    $status = $_GET['status'] ?? '';
    $sort   = (string)($_GET['sort']   ?? 'recent');
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 30;

    $allowedKinds  = ['datorie', 'asset'];
    $allowedStatus = ['0', '1'];
    $allowedSorts  = ['recent', 'amount_desc', 'amount_asc'];
    if ($kind   !== '' && !in_array($kind, $allowedKinds, true))           $kind = '';
    if ($status !== '' && !in_array((string)$status, $allowedStatus, true)) $status = '';
    if (!in_array($sort, $allowedSorts, true))                              $sort = 'recent';

    // Filter clauses live in EXISTS subqueries so a submission shows if it has
    // at least one matching entry. (All its entries are then rendered, with
    // matching/flagged ones highlighted in context.)
    $where = [];
    $params = [];
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
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // Order clause varies by sort mode.
    if ($sort === 'amount_desc') {
        $orderSql = 'ORDER BY (SELECT MAX(amount) FROM entries e WHERE e.submission_id = s.id) DESC, s.id DESC';
    } elseif ($sort === 'amount_asc') {
        $orderSql = 'ORDER BY (SELECT MAX(amount) FROM entries e WHERE e.submission_id = s.id) ASC, s.id DESC';
    } else {
        $orderSql = 'ORDER BY s.id DESC';
    }

    // Total submissions matching filters
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM submissions s $whereSql");
    $cnt->execute($params);
    $totalSubs = (int)$cnt->fetchColumn();
    $pages = max(1, (int)ceil($totalSubs / $perPage));
    $page  = min($page, $pages);
    $offset = ($page - 1) * $perPage;

    // Fetch the page of submissions.
    $subSql = "SELECT s.id, s.uuid, s.session_id, s.created_at, s.judet, s.varsta,
                      s.sex, s.persoane_intretinere, s.domeniu, s.optimist
               FROM submissions s
               $whereSql
               $orderSql
               LIMIT $perPage OFFSET $offset";
    $sStmt = $pdo->prepare($subSql);
    $sStmt->execute($params);
    $submissions = $sStmt->fetchAll();

    // Fetch all entries for the visible submissions in one query.
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
            $entriesBySubmission[$row['submission_id']][] = $row;
        }
    }

    // -------- Aggregate stats for header --------
    $summary = $pdo->query("SELECT status, COUNT(*) c FROM entries GROUP BY status")->fetchAll();
    $approved = 0; $flagged = 0;
    foreach ($summary as $r) {
        if ((int)$r['status'] === 1) $approved = (int)$r['c'];
        else $flagged = (int)$r['c'];
    }

    $typesAll = array_merge(DATORII_TYPES, ASSET_TYPES);
    $backUrl = '/admin?' . http_build_query(array_filter([
        'kind' => $kind, 'type' => $type, 'status' => $status, 'sort' => $sort, 'page' => $page,
    ], fn($v) => $v !== '' && $v !== null));

    // -------- Render --------
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

    // Filter dropdown options
    $kindOpts   = render_options(['' => 'Toate'] + ['datorie' => 'datorie', 'asset' => 'asset'], $kind);
    $typeOpts   = render_options(['' => 'Toate tipurile'] + array_combine($typesAll, $typesAll), $type);
    $statusOpts = render_options(['' => 'Toate', '1' => 'aprobate', '0' => 'flagged'], (string)$status);
    $sortOpts   = render_options([
        'recent'      => 'Cele mai recente',
        'amount_desc' => 'Suma (mare → mică)',
        'amount_asc'  => 'Suma (mică → mare)',
    ], $sort);

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
    <label class="text-xs text-slate-600">Sortare
      <select name="sort" class="block mt-1 rounded-lg border border-slate-300 px-2 py-1.5 text-sm">{$sortOpts}</select>
    </label>
    <button type="submit" class="px-3 py-1.5 rounded-lg bg-slate-900 text-white text-sm">Aplică</button>
    <a href="/admin" class="text-sm text-slate-500 hover:text-slate-900">Reset</a>
  </div>
</form>
HTML;

    // Submission cards
    $cards = '';
    foreach ($submissions as $s) {
        $sid = (int)$s['id'];
        $entries = $entriesBySubmission[$sid] ?? [];
        $cards .= render_submission_card($s, $entries, $csrf, $backUrl);
    }
    if (!$submissions) {
        $cards = '<div class="bg-white rounded-2xl border border-slate-200 p-8 text-center text-slate-400">Niciun submission pentru filtrele alese.</div>';
    }

    // Pagination
    $pagerHtml = '';
    if ($pages > 1) {
        $base = $_GET; unset($base['page']);
        $qs = http_build_query($base);
        $qs = $qs === '' ? '' : ($qs . '&');
        $prev = max(1, $page - 1);
        $next = min($pages, $page + 1);
        $pagerHtml = <<<HTML
<div class="flex items-center justify-between text-sm text-slate-600 mt-6">
  <span>Pagina {$page} din {$pages} ({$totalSubs} submissions)</span>
  <span class="flex gap-2">
    <a href="/admin?{$qs}page={$prev}" class="px-3 py-1 rounded-md border border-slate-300 hover:bg-slate-100">← Prev</a>
    <a href="/admin?{$qs}page={$next}" class="px-3 py-1 rounded-md border border-slate-300 hover:bg-slate-100">Next →</a>
  </span>
</div>
HTML;
    }

    $main = "<main class=\"max-w-7xl mx-auto px-4 py-6 space-y-4\">{$cards}{$pagerHtml}</main>";

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

    return <<<HTML
<section class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
  <header class="bg-slate-50 px-4 py-3 flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-slate-200">
    <div class="flex items-baseline gap-2 flex-1 min-w-0">
      <span class="text-xs text-slate-400">#{$sid}</span>
      <span class="font-mono text-sm text-slate-800">{$sessionId}</span>
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
