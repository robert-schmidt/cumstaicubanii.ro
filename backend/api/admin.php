<?php
// Admin board for moderating entries (approve / disable).
//
// Auth: bcrypt hash stored in config.local.php under `admin_password_hash`
//       (config.local.php is gitignored — secrets never reach git).
// Routes everything through one file, server-side rendered HTML. No JS needed.

declare(strict_types=1);
require __DIR__ . '/../db.php';

// Session config — name it specifically so it doesn't collide with anything else
session_name('datorii_admin');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

$cfg = db_config();
$adminHash = null;
// Reach the local config directly — db_config() doesn't expose unrelated keys
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

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];
$isAuth = !empty($_SESSION['admin']);

$action = $_GET['action'] ?? $_POST['action'] ?? null;
$method = $_SERVER['REQUEST_METHOD'];

// --- POST actions ---
if ($method === 'POST' && $action === 'login') {
    // Throttle login attempts per IP (5 / 10min)
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

if ($method === 'POST' && $action === 'toggle') {
    if (!$isAuth) { http_response_code(403); exit; }
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403); exit('Bad CSRF token');
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $pdo = db();
        $stmt = $pdo->prepare('UPDATE entries SET status = 1 - status WHERE id = ?');
        $stmt->execute([$id]);
    }
    $back = (string)($_POST['back'] ?? '/admin');
    if (!str_starts_with($back, '/')) $back = '/admin'; // open-redirect guard
    header('Location: ' . $back);
    exit;
}

// --- GET render ---
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

if (!$isAuth) {
    render_login();
    exit;
}

// Authenticated — render entries list
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

    $kind   = (string)($_GET['kind']   ?? '');
    $type   = (string)($_GET['type']   ?? '');
    $status = $_GET['status'] ?? '';
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 50;

    $allowedKinds  = ['datorie', 'asset'];
    $allowedStatus = ['0', '1'];
    if ($kind   !== '' && !in_array($kind, $allowedKinds, true))      $kind = '';
    if ($status !== '' && !in_array((string)$status, $allowedStatus, true)) $status = '';

    $where = []; $params = [];
    if ($kind   !== '') { $where[] = 'e.kind = ?';   $params[] = $kind; }
    if ($type   !== '') { $where[] = 'e.type = ?';   $params[] = $type; }
    if ($status !== '') { $where[] = 'e.status = ?'; $params[] = (int)$status; }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // Total count
    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM entries e JOIN submissions s ON s.id = e.submission_id $whereSql");
    $cntStmt->execute($params);
    $total = (int)$cntStmt->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));
    $page  = min($page, $pages);

    $offset = ($page - 1) * $perPage;
    $sql = "SELECT e.id, e.submission_id, e.kind, e.type, e.amount, e.status,
                   s.created_at, s.judet, s.varsta, s.domeniu, s.optimist
            FROM entries e
            JOIN submissions s ON s.id = e.submission_id
            $whereSql
            ORDER BY e.id DESC
            LIMIT $perPage OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Aggregate counts
    $summary = $pdo->query("SELECT status, COUNT(*) c FROM entries GROUP BY status")->fetchAll();
    $approved = 0; $flagged = 0;
    foreach ($summary as $r) {
        if ((int)$r['status'] === 1) $approved = (int)$r['c'];
        else $flagged = (int)$r['c'];
    }

    // Type dropdown options (union of datorie + asset types from db.php)
    $typesAll = array_merge(DATORII_TYPES, ASSET_TYPES);

    $backUrl = '/admin?' . http_build_query(array_filter(['kind' => $kind, 'type' => $type, 'status' => $status, 'page' => $page]));

    // Header
    $header = <<<HTML
<header class="bg-white border-b border-slate-200 sticky top-0 z-10">
  <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
    <div class="flex items-baseline gap-3">
      <h1 class="font-semibold">Admin · cumstaicubanii.ro</h1>
      <span class="text-xs text-slate-500">{$approved} aprobate · {$flagged} flagged · <strong>{$total}</strong> filtrate</span>
    </div>
    <a href="/admin?action=logout" class="text-sm text-slate-500 hover:text-rose-600">Ieși</a>
  </div>
</header>
HTML;

    // Filters
    $kindOpts = '<option value="">Toate</option>' .
        '<option value="datorie"' . ($kind === 'datorie' ? ' selected' : '') . '>datorie</option>' .
        '<option value="asset"'   . ($kind === 'asset'   ? ' selected' : '') . '>asset</option>';

    $typeOpts = '<option value="">Toate tipurile</option>';
    foreach ($typesAll as $t) {
        $sel = $type === $t ? ' selected' : '';
        $typeOpts .= '<option value="' . h($t) . '"' . $sel . '>' . h($t) . '</option>';
    }

    $statusOpts = '<option value="">Toate</option>' .
        '<option value="1"' . ($status === '1' ? ' selected' : '') . '>aprobate</option>' .
        '<option value="0"' . ($status === '0' ? ' selected' : '') . '>flagged</option>';

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
    <button type="submit" class="px-3 py-1.5 rounded-lg bg-slate-900 text-white text-sm">Filtrează</button>
    <a href="/admin" class="text-sm text-slate-500 hover:text-slate-900">Reset</a>
  </div>
</form>
HTML;

    // Rows
    $rowsHtml = '';
    foreach ($rows as $r) {
        $id     = (int)$r['id'];
        $sid    = (int)$r['submission_id'];
        $kindR  = h($r['kind']);
        $typeR  = h($r['type']);
        $amtR   = h(fmt_amount((int)$r['amount']));
        $statR  = (int)$r['status'];
        $when   = h((string)$r['created_at']);
        $judet  = h((string)($r['judet'] ?? '—'));
        $varsta = $r['varsta'] !== null ? h((string)$r['varsta']) : '—';
        $domeniu = h((string)($r['domeniu'] ?? '—'));
        $optimist = (int)$r['optimist'] === 1 ? '😊' : '😟';

        $statBadge = $statR === 1
            ? '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 text-xs font-medium">aprobat</span>'
            : '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-rose-100 text-rose-700 text-xs font-medium">flagged</span>';

        $actionLabel = $statR === 1 ? 'Disable' : 'Approve';
        $actionCls   = $statR === 1 ? 'bg-rose-600 hover:bg-rose-700' : 'bg-emerald-600 hover:bg-emerald-700';

        $rowsHtml .= <<<HTML
<tr class="border-b border-slate-200 hover:bg-slate-50">
  <td class="px-3 py-2 text-xs text-slate-400">{$id}</td>
  <td class="px-3 py-2 text-xs text-slate-500">#{$sid}</td>
  <td class="px-3 py-2 text-xs">{$kindR}</td>
  <td class="px-3 py-2 text-sm">{$typeR}</td>
  <td class="px-3 py-2 text-sm font-mono text-right">{$amtR}</td>
  <td class="px-3 py-2">{$statBadge}</td>
  <td class="px-3 py-2 text-xs text-slate-500">{$judet} · {$varsta} ani · {$domeniu} · {$optimist}</td>
  <td class="px-3 py-2 text-xs text-slate-400 whitespace-nowrap">{$when}</td>
  <td class="px-3 py-2 text-right">
    <form method="POST" action="/admin?action=toggle" class="inline">
      <input type="hidden" name="csrf" value="{$csrf}">
      <input type="hidden" name="id" value="{$id}">
      <input type="hidden" name="back" value="{$backUrl}">
      <button type="submit" class="px-3 py-1 rounded-md text-white text-xs font-medium {$actionCls}">{$actionLabel}</button>
    </form>
  </td>
</tr>
HTML;
    }

    if ($rowsHtml === '') {
        $rowsHtml = '<tr><td colspan="9" class="px-3 py-8 text-center text-slate-400">Niciun rezultat pentru filtrele alese.</td></tr>';
    }

    // Pagination
    $pagerHtml = '';
    if ($pages > 1) {
        $prev = max(1, $page - 1);
        $next = min($pages, $page + 1);
        $base = $_GET; unset($base['page']);
        $qs = http_build_query($base);
        $qs = $qs === '' ? '' : ($qs . '&');
        $pagerHtml = <<<HTML
<div class="flex items-center justify-between text-sm text-slate-600 px-4 py-3">
  <span>Pagina {$page} din {$pages}</span>
  <span class="flex gap-2">
    <a href="/admin?{$qs}page={$prev}" class="px-3 py-1 rounded-md border border-slate-300 hover:bg-slate-100">← Prev</a>
    <a href="/admin?{$qs}page={$next}" class="px-3 py-1 rounded-md border border-slate-300 hover:bg-slate-100">Next →</a>
  </span>
</div>
HTML;
    }

    $main = <<<HTML
<main class="max-w-7xl mx-auto px-4 py-6">
  <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-xs uppercase text-slate-500">
          <tr>
            <th class="px-3 py-2 text-left">ID</th>
            <th class="px-3 py-2 text-left">Submit</th>
            <th class="px-3 py-2 text-left">Kind</th>
            <th class="px-3 py-2 text-left">Tip</th>
            <th class="px-3 py-2 text-right">Sumă</th>
            <th class="px-3 py-2 text-left">Status</th>
            <th class="px-3 py-2 text-left">Demograf.</th>
            <th class="px-3 py-2 text-left">Când</th>
            <th class="px-3 py-2 text-right">Acțiune</th>
          </tr>
        </thead>
        <tbody>{$rowsHtml}</tbody>
      </table>
    </div>
    {$pagerHtml}
  </div>
</main>
HTML;

    page_shell($header . $filters . $main, 'Admin · entries');
}
