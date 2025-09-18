<?php
declare(strict_types=1);

$router->get('/admin/tools/recalc-wac', function() {
  require_login(); if (!role_is('admin')) { http_response_code(403); exit('Forbidden'); }
  if (!function_exists('recalc_wac')) { require_once __DIR__ . '/../app/wac_tools.php'; }
  global $pdo;
  $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
  $res = recalc_wac($pdo, $id);
  $rows = "<table><tr><th>Ingredient ID</th><th>New WAC (BWP per g/ml)</th></tr>";
  foreach ($res as $iid => $w) {
    $rows .= "<tr><td>".(int)$iid."</td><td>".($w===null ? "<em>(no purchases)</em>" : number_format((float)$w,6))."</td></tr>";
  }
  $rows .= "</table>";
  render('Recalc WAC', "<h1>Recalculated WAC</h1><p>Values are price per canonical unit (g or ml).</p>{$rows}<p><a href='".url_for("/ingredients")."'>Back to ingredients</a></p>");
});

$router->get('/admin/tools/normalize-po', function() {
  require_login(); if (!role_is('admin')) { http_response_code(403); exit('Forbidden'); }
  if (!function_exists('find_po_lines_needing_normalization')) { require_once __DIR__ . '/../app/po_normalize_tools.php'; }
  global $pdo;
  $iid = isset($_GET['id']) ? (int)$_GET['id'] : null;
  $rows = find_po_lines_needing_normalization($pdo, $iid);
  if (empty($rows)) {
    render('Normalize PO', "<h1>Normalize PO Unit Costs</h1><p class='ok'>No candidates found.</p><p><a href='".url_for("/ingredients")."'>Back</a></p>");
    return;
  }
  $tok = csrf_token();
  $tbl = "<table><tr><th>POL</th><th>Ingredient</th><th>Qty</th><th>FX</th><th>Current itxn unit BWP</th><th>Expected per-unit BWP</th></tr>";
  foreach ($rows as $r) {
    $tbl .= "<tr><td>".$r['pol_id']."</td><td>".$r['ingredient_id']."</td><td>".$r['qty']."</td><td>".$r['fx']."</td><td>".$r['it_unit_cost_bwp_current']."</td><td>".$r['expected_per_unit_bwp']."</td></tr>";
  }
  $tbl .= "</table>";
  $form = "<form method='post' action='".url_for("/admin/tools/normalize-po/apply")."'><input type='hidden' name='_csrf' value='".h($tok)."'><button>Apply normalization to ".count($rows)." line(s)</button></form>";
  render('Normalize PO', "<h1>Normalize PO Unit Costs</h1><p>This will convert line totals into per-unit costs and fix WAC.</p>{$tbl}{$form}<p><a href='".url_for("/ingredients")."'>Back</a></p>");
});

$router->post('/admin/tools/normalize-po/apply', function() {
  require_login(); if (!role_is('admin')) { http_response_code(403); exit('Forbidden'); }
  if (!function_exists('find_po_lines_needing_normalization')) { require_once __DIR__ . '/../app/po_normalize_tools.php'; }
  post_only(); global $pdo;
  $iid = isset($_GET['id']) ? (int)$_GET['id'] : null;
  $rows = find_po_lines_needing_normalization($pdo, $iid);
  $n = 0;
  if (!empty($rows)) {
    $pdo->beginTransaction();
    try { $n = apply_normalization($pdo, $rows); $pdo->commit(); }
    catch (Throwable $e) { $pdo->rollBack(); render('Normalize PO', "<p class='err'>".$e->getMessage()."</p>"); return; }
  }
  render('Normalize PO', "<h1>Normalization applied</h1><p class='ok'>Updated {$n} line(s).</p><p><a href='".url_for("/ingredients")."'>Back</a></p>");
});

$router->get('/admin/tools/fix-and-recalc', function() {
  require_login(); if (!role_is('admin')) { http_response_code(403); exit('Forbidden'); }
  if (!function_exists('find_po_lines_needing_normalization')) { require_once __DIR__ . '/../app/po_normalize_tools.php'; }
  global $pdo;
  $iid = isset($_GET['id']) ? (int)$_GET['id'] : null;
  $rows = find_po_lines_needing_normalization($pdo, $iid);
  $tok = csrf_token();
  $count = count($rows);
  $btn = "<form method='post' action='".url_for("/admin/tools/normalize-po/apply".($iid?("?id=".$iid):""))."'><input type='hidden' name='_csrf' value='".h($tok)."'><button>Fix ".($iid?"ingredient ".$iid:"all ingredients")." ({$count} line(s))</button></form>";
  render('Fix & Recalc', "<h1>Fix & Recalc</h1><p>Detected {$count} line(s) needing normalization.</p>{$btn}");
});

$router->get('/admin/timecheck', function() use ($pdo) {
  $phpNow = date('Y-m-d H:i:s T');
  $row = $pdo->query("SELECT NOW() AS now_ts, @@session.time_zone AS tz")->fetch();
  render('Time Check', "<p>PHP: {$phpNow}</p><p>MySQL NOW(): {$row['now_ts']} (session tz: {$row['tz']})</p>");
});
