<?php
// admin_wac_repair.php — drop in web root. Repairs per-unit costs for a single ingredient and rebuilds WAC.
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/csrf.php';
require_once __DIR__ . '/app/wac_tools.php';
start_secure_session();
if (empty($_SESSION['user']) || ($_SESSION['user']['role'] !== 'admin')) { http_response_code(403); exit('Forbidden'); }

header('Content-Type: text/plain; charset=utf-8');
$iid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($iid<=0) { echo "Usage: /admin_wac_repair.php?id=<ingredient_id>\n"; exit; }

// 1) Show POL + ITXN before
echo "== BEFORE ==\n";
$st = $pdo->prepare("SELECT pol.id AS pol_id, po.fx_rate_used, pol.qty, pol.unit_cost_native, pol.unit_cost_bwp,
                            it.id AS itxn_id, it.qty AS it_qty, it.unit_cost_bwp AS it_unit_cost_bwp
                     FROM purchase_order_lines pol
                     JOIN purchase_orders po ON po.id=pol.purchase_order_id
                     LEFT JOIN inventory_txns it ON it.source_table='purchase_order_lines' AND it.source_id=pol.id
                     WHERE pol.ingredient_id=?");
$st->execute([$iid]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);

// 2) Normalize cases that look like line totals
$fix = $pdo->prepare("UPDATE inventory_txns it
  JOIN purchase_order_lines pol ON it.source_table='purchase_order_lines' AND it.source_id=pol.id
  JOIN purchase_orders po ON po.id = pol.purchase_order_id
  SET it.unit_cost_bwp = CASE WHEN pol.qty>0 THEN (pol.unit_cost_native * po.fx_rate_used) / pol.qty ELSE it.unit_cost_bwp END
  WHERE it.txn_type='purchase' AND pol.ingredient_id=? AND
        (it.unit_cost_bwp = 0 OR ABS(it.unit_cost_bwp - (pol.unit_cost_native * po.fx_rate_used)) < 0.0001)");
$fix->execute([$iid]);

$fix2 = $pdo->prepare("UPDATE purchase_order_lines pol
  JOIN purchase_orders po ON po.id=pol.purchase_order_id
  LEFT JOIN inventory_txns it ON it.source_table='purchase_order_lines' AND it.source_id=pol.id
  SET pol.unit_cost_bwp = CASE WHEN pol.qty>0 THEN (pol.unit_cost_native * po.fx_rate_used) / pol.qty ELSE pol.unit_cost_bwp END,
      pol.unit_cost_native = CASE WHEN pol.qty>0 AND (pol.unit_cost_bwp = 0 OR ABS(it.unit_cost_bwp * pol.qty - (pol.unit_cost_native * po.fx_rate_used)) < 0.0001)
                                  THEN pol.unit_cost_native / pol.qty
                                  ELSE pol.unit_cost_native END
  WHERE pol.ingredient_id=?");
$fix2->execute([$iid]);

// 3) Rebuild WAC
recalc_wac($pdo, $iid);

// 4) Show AFTER
echo "\n== AFTER ==\n";
$st->execute([$iid]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);

$w = $pdo->prepare("SELECT * FROM ingredient_wac_history WHERE ingredient_id=? ORDER BY id DESC LIMIT 1");
$w->execute([$iid]);
echo "\nCurrent WAC row:\n";
print_r($w->fetch(PDO::FETCH_ASSOC));
