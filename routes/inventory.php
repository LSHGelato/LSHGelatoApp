<?php
declare(strict_types=1);

/**
 * Inventory adjustments:
 * - GET  /inventory/adjust?id=ING_ID     (show forms)
 * - POST /inventory/adjust/usage         (record a deduction)
 * - POST /inventory/adjust/set-on-hand   (set current on-hand via delta)
 */

$router->get('/inventory/adjust', function () {
  require_login(); if (!role_is('admin')) { http_response_code(403); exit('Forbidden'); }
  global $pdo;

  $id = (int)($_GET['id'] ?? 0);
  if ($id <= 0) { http_response_code(400); render('Adjust','<p class="err">Bad ingredient id.</p>'); return; }

  // Ingredient + current on-hand
  $st = $pdo->prepare("
    SELECT i.id, i.name, i.unit_kind,
           COALESCE((SELECT SUM(qty) FROM inventory_txns t WHERE t.ingredient_id=i.id), 0) AS on_hand
    FROM ingredients i
    WHERE i.id = ?
  ");
  $st->execute([$id]);
  $row = $st->fetch();
  if (!$row) { http_response_code(404); render('Adjust','<p class="err">Ingredient not found.</p>'); return; }

  $tok     = csrf_token();
  $today   = date('Y-m-d');
  $name    = h($row['name']);
  $unit    = h($row['unit_kind']);
  $on_hand = (float)$row['on_hand'];
 
  $body = "<h1>Adjust inventory — {$name}</h1>
  <p><a href='".url_for('/inventory/history?id='.(int)$row['id'])."'>View recent adjustments</a></p>
  <p>Current on hand: <strong>".number_format($on_hand, 3)."</strong> {$unit}</p>

  <h2>Record a deduction</h2>
  <form method='post' action='".url_for('/inventory/adjust/usage')."' class='row'>
    <input type='hidden' name='_csrf' value='".h($tok)."'>
    <input type='hidden' name='ingredient_id' value='".(int)$row['id']."'>
    <div class='col'><label>Date <input type='date' name='date' value='".h($today)."' required></label></div>
    <div class='col'><label>Qty to deduct ({$unit}) <input type='number' name='qty' step='0.001' min='0.001' required></label></div>
    <div class='col' style='flex:2'><label>Note <input name='note' placeholder='e.g., non-gelato use'></label></div>
    <button>Record deduction</button>
  </form>

  <h2>Set on-hand to an exact amount</h2>
  <form method='post' action='".url_for('/inventory/adjust/set-on-hand')."' class='row'>
    <input type='hidden' name='_csrf' value='".h($tok)."'>
    <input type='hidden' name='ingredient_id' value='".(int)$row['id']."'>
    <div class='col'><label>Date <input type='date' name='date' value='".h($today)."' required></label></div>
    <div class='col'><label>Target on hand ({$unit}) <input type='number' name='target_qty' step='0.001' min='0' required></label></div>
    <div class='col' style='flex:2'><label>Note <input name='note' placeholder='e.g., scale count / stocktake'></label></div>
    <button>Apply stocktake</button>
  </form>

  <p><a href='".url_for('/ingredients')."'>Back to ingredients</a></p>";

  render('Adjust Inventory', $body);
});

$router->post('/inventory/adjust/usage', function () {
  require_login(); if (!role_is('admin')) { http_response_code(403); exit('Forbidden'); }
  csrf_verify(); global $pdo;

  $iid  = (int)($_POST['ingredient_id'] ?? 0);
  $date = $_POST['date'] ?? date('Y-m-d');
  $qty  = (float)($_POST['qty'] ?? 0);
  $note = trim((string)($_POST['note'] ?? ''));

  if ($iid <= 0 || $qty <= 0) { render('Adjust','<p class="err">Bad input.</p>'); return; }

  // canonical unit for the ingredient
  $u = $pdo->prepare("SELECT unit_kind FROM ingredients WHERE id=?");
  $u->execute([$iid]);
  $unit = $u->fetchColumn();
  if ($unit === false) { render('Adjust','<p class="err">Ingredient not found.</p>'); return; }

  // usage is negative
  $delta = -abs($qty);

  $ins = $pdo->prepare("
    INSERT INTO inventory_txns
      (ingredient_id, txn_ts, txn_date, txn_type, qty, unit_kind, unit_cost_bwp, source_table, source_id, note, created_by)
    VALUES
      (?, NOW(), ?, 'usage', ?, ?, 0, 'manual_adjust', 0, ?, ?)
  ");
  $ins->execute([$iid, $date, $delta, $unit, $note !== '' ? $note : null, (int)($_SESSION['user']['id'] ?? 0)]);

  header('Location: ' . url_for('/ingredients'));
});

$router->post('/inventory/adjust/set-on-hand', function () {
  require_login(); if (!role_is('admin')) { http_response_code(403); exit('Forbidden'); }
  csrf_verify(); global $pdo;

  $iid   = (int)($_POST['ingredient_id'] ?? 0);
  $date  = $_POST['date'] ?? date('Y-m-d');
  $tgt   = $_POST['target_qty'] !== '' ? (float)$_POST['target_qty'] : null;
  $note  = trim((string)($_POST['note'] ?? ''));

  if ($iid <= 0 || $tgt === null || $tgt < 0) { render('Adjust','<p class="err">Bad input.</p>'); return; }

  // current on hand
  $cur = $pdo->prepare("SELECT COALESCE(SUM(qty),0) FROM inventory_txns WHERE ingredient_id=?");
  $cur->execute([$iid]);
  $on_hand = (float)$cur->fetchColumn();

  // delta to reach target
  $delta = $tgt - $on_hand;
  if (abs($delta) < 1e-9) { header('Location: ' . url_for('/ingredients')); return; }

  // canonical unit
  $u = $pdo->prepare("SELECT unit_kind FROM ingredients WHERE id=?");
  $u->execute([$iid]);
  $unit = $u->fetchColumn();
  if ($unit === false) { render('Adjust','<p class="err">Ingredient not found.</p>'); return; }

  // stocktake entry (positive or negative)
  $ins = $pdo->prepare("
    INSERT INTO inventory_txns
      (ingredient_id, txn_ts, txn_date, txn_type, qty, unit_kind, unit_cost_bwp, source_table, source_id, note, created_by)
    VALUES
      (?, NOW(), ?, 'stocktake', ?, ?, 0, 'manual_adjust', 0, ?, ?)
  ");
  $autoNote = "Stocktake set to {$tgt} {$unit} (Δ " . number_format($delta,3,'.','') . ")";
  $ins->execute([$iid, $date, $delta, $unit, ($note!==''?$note.' — ':'').$autoNote, (int)($_SESSION['user']['id'] ?? 0)]);

  header('Location: ' . url_for('/ingredients'));
});

$router->get('/inventory/history', function () {
  require_login(); if (!role_is('admin')) { http_response_code(403); exit('Forbidden'); }
  global $pdo;

  $id = (int)($_GET['id'] ?? 0);
  if ($id <= 0) { http_response_code(400); render('History','<p class="err">Bad ingredient id.</p>'); return; }

  $ing = $pdo->prepare("SELECT name, unit_kind FROM ingredients WHERE id=?");
  $ing->execute([$id]); $I = $ing->fetch();
  if (!$I) { http_response_code(404); render('History','<p class="err">Ingredient not found.</p>'); return; }

  $tx = $pdo->prepare("
    SELECT txn_date, txn_type, qty, unit_kind, note
    FROM inventory_txns
    WHERE ingredient_id=?
    ORDER BY txn_date DESC, id DESC
    LIMIT 50
  ");
  $tx->execute([$id]); $rows = $tx->fetchAll();

  $tbl = "<table><tr><th>Date</th><th>Type</th><th class='right'>Qty</th><th>Unit</th><th>Note</th></tr>";
  foreach ($rows as $r) {
    $tbl .= "<tr>
      <td>".h($r['txn_date'])."</td>
      <td>".h($r['txn_type'])."</td>
      <td class='right'>".number_format((float)$r['qty'],3)."</td>
      <td>".h($r['unit_kind'])."</td>
      <td>".h((string)$r['note'])."</td>
    </tr>";
  }
  $tbl .= "</table>";

  render('Inventory History', "<h1>History — ".h($I['name'])."</h1>{$tbl}<p><a href='".url_for('/ingredients')."'>Back</a></p>");
});
