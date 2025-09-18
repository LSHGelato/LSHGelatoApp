<?php
declare(strict_types=1);

$router->get('/admin/enrich', function() {
  require_admin();
  if (!function_exists('enrich_one')) { http_response_code(404); render('Enrich', "<p class='err'>Enrichment module not installed.</p>"); return; }
  global $pdo;
  $rows = $pdo->query("SELECT id,name,solids_pct,fat_pct,sugar_pct,nutrition_source,nutrition_confidence FROM ingredients WHERE is_active=1 ORDER BY name")->fetchAll();
  $list = table_open() . "<tr><th>Name</th><th>Solids%</th><th>Fat%</th><th>Sugar%</th><th>Source</th><th>Conf</th><th>Actions</th></tr>";
  foreach ($rows as $r) {
    $missing = [];
    foreach (['solids_pct','fat_pct','sugar_pct'] as $k) if ($r[$k] === null) $missing[] = $k;
    $act = empty($missing) ? "<span class='ok'>Complete</span>" :
      "<a href='".url_for("/admin/enrich/run?id=".(int)$r['id'])."'>Run now</a>";
    $list .= "<tr><td>".h($r['name'])."</td><td>".h((string)$r['solids_pct'])."</td><td>".h((string)$r['fat_pct'])."</td><td>".h((string)$r['sugar_pct'])."</td><td>".h((string)$r['nutrition_source'])."</td><td>".h((string)$r['nutrition_confidence'])."</td><td>{$act}</td></tr>";
  }
  $list .= "</table><p class='small'>Auto-applies when confidence ≥ 80. Provider order: Open Food Facts → USDA FDC.</p>";
  render('Enrich', "<h1>AI Enrichment</h1>".$list);
});

$router->get('/admin/enrich/run', function() {
  require_admin();
  if (!function_exists('enrich_one')) { http_response_code(404); render('Enrich', "<p class='err'>Enrichment module not installed.</p>"); return; }
  global $pdo;
  $id = (int)($_GET['id'] ?? 0); if ($id<=0) { http_response_code(400); exit('Bad id'); }
  try {
    $res = enrich_one($pdo, $id, (int)$_SESSION['user']['id'], true, 80);
    $msg = !empty($res['_auto_applied']) ? "<p class='ok'>Auto-applied (confidence ".$res['confidence'].").</p>" : "<p class='warn'>Review needed (confidence ".$res['confidence'].").</p>";
    render('Enriched', $msg."<pre>".h(json_encode($res, JSON_PRETTY_PRINT))."</pre><p><a href='".url_for("/admin/enrich")."'>Back</a></p>");
  } catch (Throwable $e) {
    render('Enrich error', "<p class='err'>".h($e->getMessage())."</p><p><a href='".url_for("/admin/enrich")."'>Back</a></p>");
  }
});
