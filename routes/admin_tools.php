<?php
declare(strict_types=1);

/*
 * Admin tools (authenticated)
 * - /admin/tools               : menu
 * - /admin/tools/wac-recalc    : POST recalc WAC for all ingredients
 * - /admin/tools/log           : tail the PHP error_log (read-only)
 * - /admin/tools/normalize-po  : detect PO lines needing normalization
 * - /admin/tools/fix-and-recalc: one-click normalize + recalc summary
 */

$router->get('/admin/tools', function () {
  require_admin();

  $tok = csrf_token();
  $body = "<h1>Admin tools</h1>
  <form method='post' action='".url_for("/admin/tools/wac-recalc")."' onsubmit='return confirm(\"Recalculate WAC for all ingredients?\")'>
    ".csrf_field($tok)."
    <button>Recalculate WAC (all)</button>
  </form>

  <h2>Logs</h2>
  <p><a class='btn' href='".url_for("/admin/tools/log")."'>View PHP error_log (tail)</a></p>

  <h2>PO normalization</h2>
  <p><a class='btn' href='".url_for("/admin/tools/normalize-po")."'>Scan for lines needing normalization</a></p>
  <p><a class='btn' href='".url_for("/admin/tools/fix-and-recalc")."'>Fix &amp; Recalc (all)</a></p>";

  render('Admin Tools', $body);
});

$router->post('/admin/tools/wac-recalc', function () {
  require_admin();
  post_only(); // post_only() already calls csrf_verify()
  global $pdo;

  if (!function_exists('recalc_wac')) { require_once __DIR__ . '/../app/wac_tools.php'; }

  $ids = $pdo->query("SELECT id FROM ingredients")->fetchAll(PDO::FETCH_COLUMN);
  $n = 0;
  foreach ($ids as $iid) { recalc_wac($pdo, (int)$iid); $n++; }
  render('WAC Recalc', "<p class='ok'>Recalculated WAC for ".(int)$n." ingredients.</p><p><a href='".url_for("/admin/tools")."'>Back</a></p>");
});

$router->get('/admin/tools/log', function () {
  require_admin();

  // Try to read error_log (path from PHP config). If not readable, say so.
  $path = ini_get('error_log') ?: __DIR__ . '/../error_log';
  $out = '';
  if ($path && @is_readable($path)) {
    $lines = @file($path);
    if ($lines !== false) {
      $last = array_slice($lines, -200);
      $out = "<pre style='white-space:pre-wrap;max-height:60vh;overflow:auto'>".h(implode('', $last))."</pre>";
    } else {
      $out = "<p class='err'>Could not read file.</p>";
    }
  } else {
    $out = "<p class='muted'>No readable error_log at <code>".h((string)$path)."</code>.</p>";
  }

  render('Error log (tail)', "<h1>PHP error_log (tail)</h1>".$out."<p><a href='".url_for("/admin/tools")."'>Back</a></p>");
});

$router->get('/admin/tools/normalize-po', function() {
  require_admin();
  if (!function_exists('find_po_lines_needing_normalization')) { require_once __DIR__ . '/../app/po_normalize_tools.php'; }
  global $pdo;

  $iid  = isset($_GET['id']) ? (int)$_GET['id'] : null;
  $rows = find_po_lines_needing_normalization($pdo, $iid);

  if (empty($rows)) {
    render('Normalize PO', "<h1>Normalize PO Unit Costs</h1><p class='ok'>No candidates found.</p><p><a href='".url_for("/ingredients")."'>Back</a></p>");
    return;
  }

  $tok = csrf_token();
  $tbl = table_open() . "<tr><th>POL</th><th>Ingredient</th><th class='right'>Qty</th><th class='right'>FX</th><th class='right'>Current itxn unit BWP</th><th class='right'>Expected per-unit BWP</th></tr>";
  foreach ($rows as $r) {
    $tbl .= "<tr>"
          . "<td>".h((string)$r['pol_id'])."</td>"
          . "<td>".h((string)$r['ingredient_id'])."</td>"
          . "<td class='right'>".h((string)$r['qty'])."</td>"
          . "<td class='right'>".h((string)$r['fx'])."</td>"
          . "<td class='right'>".h((string)$r['it_unit_cost_bwp_current'])."</td>"
          . "<td class='right'>".h((string)$r['expected_per_unit_bwp'])."</td>"
          . "</tr>";
  }
  $tbl .= "</table>";

  $form = "<form method='post' action='".url_for("/admin/tools/normalize-po/apply")."'>"
        . csrf_field($tok)
        . "<button>Apply normalization to ".count($rows)." line(s)</button></form>";

  render('Normalize PO', "<h1>Normalize PO Unit Costs</h1><p>This will convert line totals into per-unit costs and fix WAC.</p>{$tbl}{$form}<p><a href='".url_for("/ingredients")."'>Back</a></p>");
});

$router->post('/admin/tools/normalize-po/apply', function() {
  require_admin();
  if (!function_exists('find_po_lines_needing_normalization')) { require_once __DIR__ . '/../app/po_normalize_tools.php'; }
  post_only(); global $pdo;

  $iid  = isset($_GET['id']) ? (int)$_GET['id'] : null;
  $rows = find_po_lines_needing_normalization($pdo, $iid);

  $n = 0;
  if (!empty($rows)) {
    $pdo->beginTransaction();
    try {
      $n = apply_normalization($pdo, $rows);
      $pdo->commit();
    } catch (Throwable $e) {
      $pdo->rollBack();
      render('Normalize PO', "<p class='err'>".h($e->getMessage())."</p>");
      return;
    }
  }

  render('Normalize PO', "<h1>Normalization applied</h1><p class='ok'>Updated ".(int)$n." line(s).</p><p><a href='".url_for("/ingredients")."'>Back</a></p>");
});

$router->get('/admin/tools/fix-and-recalc', function() {
  require_admin();
  if (!function_exists('find_po_lines_needing_normalization')) { require_once __DIR__ . '/../app/po_normalize_tools.php'; }
  global $pdo;

  $iid   = isset($_GET['id']) ? (int)$_GET['id'] : null;
  $rows  = find_po_lines_needing_normalization($pdo, $iid);
  $tok   = csrf_token();
  $count = count($rows);

  $btn = "<form method='post' action='".url_for("/admin/tools/normalize-po/apply".($iid ? ("?id=".$iid) : ""))."'>"
       . csrf_field($tok)
       . "<button>Fix ".($iid ? ("ingredient ".(int)$iid) : "all ingredients")." ({$count} line(s))</button></form>";

  render('Fix & Recalc', "<h1>Fix & Recalc</h1><p>Detected ".(int)$count." line(s) needing normalization.</p>{$btn}");
});

$router->get('/admin/timecheck', function () {
  require_admin();
  global $pdo;

  $phpNow = date('Y-m-d H:i:s T');
  $row = $pdo->query("SELECT NOW() AS now_ts, @@session.time_zone AS tz")->fetch();
  render('Time Check', "<p>PHP: ".h($phpNow)."</p><p>MySQL NOW(): ".h((string)$row['now_ts'])." (session tz: ".h((string)$row['tz']).")</p>");
});
