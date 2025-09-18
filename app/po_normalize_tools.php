<?php
// app/po_normalize_tools.php
// Detect and fix purchase_order_lines saved with line total instead of per-unit cost.
// Heuristic: if inventory_txns.unit_cost_bwp ≈ (pol.unit_cost_native * fx_rate) (i.e., equals line total in BWP),
// then it's wrong; correct per-unit is (pol.unit_cost_native * fx_rate) / qty.

require_once __DIR__ . '/wac_tools.php';

function find_po_lines_needing_normalization(PDO $pdo, ?int $ingredient_id=null): array {
  $sql = "SELECT pol.id AS pol_id, pol.purchase_order_id, pol.ingredient_id, pol.qty,
                 pol.unit_cost_native, pol.unit_cost_bwp,
                 po.fx_rate_used,
                 it.id AS itxn_id, it.unit_cost_bwp AS it_unit_cost_bwp
          FROM purchase_order_lines pol
          JOIN purchase_orders po ON po.id = pol.purchase_order_id
          LEFT JOIN inventory_txns it ON it.source_table='purchase_order_lines' AND it.source_id = pol.id
          " . ($ingredient_id ? "WHERE pol.ingredient_id = :iid" : "") . "
          ORDER BY pol.id";
  $st = $pdo->prepare($sql);
  if ($ingredient_id) $st->bindValue(':iid', $ingredient_id, PDO::PARAM_INT);
  $st->execute();
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  $eps = 0.0001;
  $out = [];
  foreach ($rows as $r) {
    $qty = (float)$r['qty'];
    $fx  = (float)$r['fx_rate_used'];
    if ($qty <= 0 || $fx <= 0) continue;
    $expected_line_total_bwp = (float)$r['unit_cost_native'] * $fx;
    $it_uc = (float)($r['it_unit_cost_bwp'] ?? 0.0);
    $looks_wrong = (abs($it_uc - $expected_line_total_bwp) < $eps); // stored line total as per-unit
    $looks_right = (abs(($it_uc * $qty) - $expected_line_total_bwp) < $eps);
    if ($looks_wrong && !$looks_right) {
      $out[] = [
        'pol_id' => (int)$r['pol_id'],
        'purchase_order_id' => (int)$r['purchase_order_id'],
        'ingredient_id' => (int)$r['ingredient_id'],
        'qty' => $qty,
        'fx'  => $fx,
        'unit_cost_native_current' => (float)$r['unit_cost_native'],
        'unit_cost_bwp_current'    => (float)$r['unit_cost_bwp'],
        'itxn_id'                  => $r['itxn_id'] ? (int)$r['itxn_id'] : null,
        'it_unit_cost_bwp_current' => $it_uc,
        'expected_per_unit_native' => $qty > 0 ? ($r['unit_cost_native'] / $qty) : null,
        'expected_per_unit_bwp'    => $qty > 0 ? ($expected_line_total_bwp / $qty) : null,
      ];
    }
  }
  return $out;
}

function apply_normalization(PDO $pdo, array $rows): int {
  $n = 0;
  foreach ($rows as $r) {
    $pol_id = (int)$r['pol_id'];
    $iid    = (int)$r['ingredient_id'];
    $new_unit_native = (float)$r['expected_per_unit_native'];
    $new_unit_bwp    = (float)$r['expected_per_unit_bwp'];
    $itxn_id = $r['itxn_id'];

    // Update POL per-unit costs
    $st = $pdo->prepare("UPDATE purchase_order_lines SET unit_cost_native=?, unit_cost_bwp=? WHERE id=?");
    $st->execute([$new_unit_native, $new_unit_bwp, $pol_id]);

    // Update inventory_txns unit cost
    if ($itxn_id) {
      $st = $pdo->prepare("UPDATE inventory_txns SET unit_cost_bwp=?, note = CONCAT(COALESCE(note,''),' [unitcost normalized]') WHERE id=?");
      $st->execute([$new_unit_bwp, $itxn_id]);
    }

    // Recalc WAC for this ingredient
    recalc_wac($pdo, $iid);
    $n++;
  }
  return $n;
}
