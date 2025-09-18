<?php
// app/wac_tools.php — recompute WAC as a single weighted-average row per ingredient.
function recalc_wac(PDO $pdo, ?int $ingredient_id=null): array {
  if ($ingredient_id !== null) {
    $ids = [ (int)$ingredient_id ];
  } else {
    $ids = array_map('intval', array_column($pdo->query("SELECT id FROM ingredients WHERE is_active=1")->fetchAll(), 'id'));
  }
  $out = [];
  foreach ($ids as $id) {
    $st = $pdo->prepare("SELECT SUM(qty * unit_cost_bwp) AS tot_cost, SUM(qty) AS tot_qty
                         FROM inventory_txns
                         WHERE ingredient_id=? AND txn_type='purchase' AND unit_cost_bwp IS NOT NULL");
    $st->execute([$id]);
    $row = $st->fetch();
    $tot_q = (float)($row['tot_qty'] ?? 0);
    $tot_c = (float)($row['tot_cost'] ?? 0);
    if ($tot_q <= 0 || $tot_c <= 0) {
      // No valid purchases: clear current flag so views show NULL (0.00 when COALESCE'd)
      $pdo->prepare("UPDATE ingredient_wac_history SET current_flag=0 WHERE ingredient_id=?")->execute([$id]);
      $out[$id] = null;
      continue;
    }
    $wac = $tot_c / $tot_q;
    $pdo->prepare("DELETE FROM ingredient_wac_history WHERE ingredient_id=?")->execute([$id]);
    $ins = $pdo->prepare("INSERT INTO ingredient_wac_history (ingredient_id, wac_bwp, current_flag) VALUES (?,?,1)");
    $ins->execute([$id, $wac]);
    $out[$id] = round($wac, 6);
  }
  return $out;
}
