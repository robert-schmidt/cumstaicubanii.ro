<?php
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="ro"><head><meta charset="utf-8"><title>Datorii API</title>
<style>
  body{font-family:ui-sans-serif,system-ui,sans-serif;background:#0f172a;color:#e2e8f0;max-width:42rem;margin:4rem auto;padding:0 1.5rem;line-height:1.55}
  code{background:#1e293b;padding:.15rem .4rem;border-radius:.25rem;color:#a7f3d0}
  a{color:#7dd3fc}
  h1{margin-bottom:.25rem}
  .muted{color:#94a3b8}
  ul{padding-left:1.25rem}
</style></head>
<body>
  <h1>Datorii — backend</h1>
  <p class="muted">Acesta e doar serverul PHP (API). Aplicația rulează pe frontend:</p>
  <p>👉 <a href="http://localhost:5173">http://localhost:5173</a></p>
  <p>Endpoint-uri disponibile:</p>
  <ul>
    <li><code>GET /api/meta.php</code></li>
    <li><code>GET /api/stats.php</code></li>
    <li><code>POST /api/submit.php</code></li>
  </ul>
  <p class="muted">Pornește frontend-ul cu <code>cd frontend &amp;&amp; npm run dev</code>.</p>
</body></html>
