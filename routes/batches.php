<?php
declare(strict_types=1);

const CHURN_BOWL_TARE_G = 540.0; // used to compute leftover on edit (stage 2)

$router->get('/batches', function () {
  require_login();
  global $pdo;

  $perPage = 50;
  $page = max(1, (int)($_GET['page'] ?? 1));

  $total = (int)$pdo->query("
    SELECT COUNT(*)
    FROM batches b
    JOIN recipe_versions rv ON rv.id = b.recipe_version_id
    JOIN recipes r          ON r.id = rv.recipe_id
  ")->fetchColumn();

  if ($total === 0) {
    $totalPages = 1;
    $page = 1;
  } else {
    $totalPages = (int)ceil($total / $perPage);
    if ($page > $totalPages) { $page = $totalPages; }
  }

  $offset = ($page - 1) * $perPage;

  $rows = $pdo->query("
    SELECT b.id, b.batch_date, b.churn_start_dt, b.target_mix_g, b.actual_mix_g, b.cogs_bwp,
           r.name AS recipe_name, rv.version_no
    FROM batches b
    JOIN recipe_versions rv ON rv.id = b.recipe_version_id
    JOIN recipes r          ON r.id = rv.recipe_id
    ORDER BY b.batch_date DESC, b.id DESC
    LIMIT {$perPage} OFFSET {$offset}
  ")->fetchAll();

  $navLinks = [];
  if ($page > 1) {
    $navLinks[] = "<a href='".url_for("/batches?page=".($page - 1))."'>Prev</a>";
  }
  if ($page < $totalPages) {
    $navLinks[] = "<a href='".url_for("/batches?page=".($page + 1))."'>Next</a>";
  }
  $nav = $navLinks ? "<p class='pager'>".implode(' | ', $navLinks)."</p>" : '';

  $tbl = "<table>
    <tr><th>Date</th><th>Recipe</th><th>v</th><th class='right'>Target (g)</th><th class='right'>Actual (g)</th><th class='right'>COGS (BWP)</th><th>Actions</th></tr>";
  foreach ($rows as $r) {
    $tbl .= "<tr>
      <td>".h(date('Y-m-d H:i', strtotime($r['batch_date'])))."</td>
      <td>".h($r['recipe_name'])."</td>
      <td class='center'>".(int)$r['version_no']."</td>
      <td class='right'>".number_format((float)$r['target_mix_g'],3)."</td>
      <td class='right'>".number_format((float)$r['actual_mix_g'],3)."</td>
      <td class='right'>".number_format((float)$r['cogs_bwp'] ?? 0,2)."</td>
      <td><a href='".url_for("/batches/view?id=".(int)$r['id'])."'>View</a> &nbsp;|&nbsp;
          <a href='".url_for("/batches/edit?id=".(int)$r['id'])."'>Edit</a></td>
    </tr>";
  }
  $tbl .= "</table>";

  render('Batches', "<h1>Batches</h1><p><a class='btn' href='".url_for("/recipes")."'>Start a new batch from a recipe</a></p>".$nav.$tbl.$nav);
});

$router->get('/batches/view', function () {
  require_login();
  global $pdo;
  $id = (int)($_GET['id'] ?? 0);
  if ($id <= 0) { http_response_code(400); exit('Bad id'); }

  $h = $pdo->prepare("
    SELECT b.*, r.name AS recipe_name, rv.version_no
    FROM batches b
    JOIN recipe_versions rv ON rv.id = b.recipe_version_id
    JOIN recipes r          ON r.id = rv.recipe_id
    WHERE b.id = ?
  ");
  $h->execute([$id]); $B = $h->fetch();
  if (!$B) { http_response_code(404); render('Batch', '<p class="err">Not found</p>'); return; }

  // Resolutions + costs
  $res = $pdo->prepare("
    SELECT bir.*, i.name AS ingredient_name, COALESCE(w.wac_bwp,0) AS wac
    FROM batch_ingredient_resolutions bir
    JOIN recipe_items ri ON ri.id = bir.recipe_item_id
    JOIN ingredients i   ON i.id = bir.resolved_ingredient_id
    LEFT JOIN v_current_wac w ON w.ingredient_id = bir.resolved_ingredient_id
    WHERE bir.batch_id = ?
    ORDER BY ri.choice_group, ri.sort_order, i.name
  ");
  $res->execute([$id]); $rows = $res->fetchAll();

  $tbl = "<table><tr><th>Ingredient</th><th class='right'>Measured</th><th>Unit</th><th class='right'>WAC</th><th class='right'>Ext (BWP)</th></tr>";
  $total = 0.0;
  foreach ($rows as $r) {
    $m = (float)($r['measured_qty'] ?? 0);
    $w = (float)$r['wac'];
    $ext = $m * $w; $total += $ext;
    $tbl .= "<tr>
      <td>".h($r['ingredient_name'])."</td>
      <td class='right'>".number_format($m,3)."</td>
      <td>".h($r['unit_kind'])."</td>
      <td class='right'>".number_format($w,4)."</td>
      <td class='right'>".number_format($ext,2)."</td>
    </tr>";
  }
  $tbl .= "<tr><td colspan='4' class='right'><strong>Total COGS snapshot</strong></td>
             <td class='right'><strong>".number_format($total,2)."</strong></td></tr></table>";

  // Packouts
  $po = $pdo->prepare("
    SELECT p.name, p.nominal_g_per_unit, bp.unit_count
    FROM batch_packouts bp
    JOIN package_types p ON p.id = bp.package_type_id
    WHERE bp.batch_id = ?
    ORDER BY p.name
  ");
  $po->execute([$id]); $packs = $po->fetchAll();

  $packTable = "<table><tr><th>Package</th><th class='right'>Units</th><th class='right'>Nominal g</th><th class='right'>Total g</th></tr>";
  $packTotal = 0.0;
  foreach ($packs as $p) {
    $u = (int)$p['unit_count'];
    $g = (float)$p['nominal_g_per_unit'];
    $tg = $u * $g; $packTotal += $tg;
    $packTable .= "<tr>
      <td>".h($p['name'])."</td>
      <td class='right'>".$u."</td>
      <td class='right'>".number_format($g,2)."</td>
      <td class='right'>".number_format($tg,2)."</td>
    </tr>";
  }
  $packTable .= "<tr><td colspan='3' class='right'><strong>Packed total (nominal)</strong></td>
                   <td class='right'><strong>".number_format($packTotal,2)."</strong></td></tr></table>";

  $hdr = "<h1>Batch #".(int)$B['id']." — ".h($B['recipe_name'])." v".(int)$B['version_no']."</h1>
    <p>Date: ".h(date('Y-m-d H:i', strtotime($B['batch_date'])))."</p>
    ".($B['churn_start_dt'] ? "<p>Churn start: ".h(date('Y-m-d H:i', strtotime($B['churn_start_dt'])))."</p>" : "")."
    <p>Target mix: ".number_format((float)$B['target_mix_g'],3)." g • Actual mix: ".number_format((float)$B['actual_mix_g'],3)." g</p>
    ".($B['residue_g']!==null ? "<p>Leftover/residue: ".number_format((float)$B['residue_g'],3)." g</p>" : "")."
    <p>COGS (stored): ".number_format((float)($B['cogs_bwp'] ?? 0),2)." BWP</p>
    <p><a href='".url_for("/batches/edit?id=".(int)$B['id'])."'>Edit batch</a></p>";

  render('View Batch', $hdr."<h2>Ingredients</h2>".$tbl."<h2>Packouts</h2>".$packTable);
});

$router->get('/batches/new', function() {
  require_login();
  global $pdo;

  $rv_id = (int)($_GET['rv_id'] ?? 0);
  if ($rv_id<=0) { http_response_code(400); exit('Bad recipe version id'); }

  $st = $pdo->prepare("SELECT rv.*, r.name AS recipe_name
                       FROM recipe_versions rv
                       JOIN recipes r ON r.id = rv.recipe_id
                       WHERE rv.id=?");
  $st->execute([$rv_id]); $v = $st->fetch();
  if (!$v) { http_response_code(404); exit('No such version'); }

  $target = isset($_GET['target']) ? (float)$_GET['target'] : (float)$v['default_yield_g'];
  $tok = csrf_token();

  $it = $pdo->prepare("
    SELECT
      ri.id AS ri_id, ri.choice_group AS cg, ri.qty AS recipe_qty, ri.unit_kind, ri.is_primary,
      i.id AS ingredient_id, i.name AS ingredient_name,
      COALESCE(w.wac_bwp,0) AS wac
    FROM recipe_items ri
    JOIN ingredients i     ON i.id = ri.ingredient_id
    LEFT JOIN v_current_wac w ON w.ingredient_id = i.id
    WHERE ri.recipe_version_id = ?
    ORDER BY (ri.choice_group=0) DESC, ri.choice_group, ri.sort_order, i.name
  ");
  $it->execute([$rv_id]);
  $rows = $it->fetchAll();

  $groups = [];
  foreach ($rows as $r) $groups[(int)$r['cg']][] = $r;

  $selectedByGroup = [];
  foreach ($groups as $cg => $items) {
    if ($cg === 0) continue;
    $sel = null;
    foreach ($items as $r) if ($r['is_primary']) { $sel = (int)$r['ri_id']; break; }
    if ($sel === null) { $sel = (int)$items[0]['ri_id']; }
    $selectedByGroup[$cg] = $sel;
  }

  $hdr = "<h1>New Batch — ".h($v['recipe_name'])." v".(int)$v['version_no']."</h1>
    <form method='post' action='".url_for("/batches/save")."' id='batchForm'>
      ".csrf_field($tok)."
      <input type='hidden' name='rv_id' value='".(int)$rv_id."'>
      <input type='hidden' name='default_yield' value='".number_format((float)$v['default_yield_g'],3,'.','')."'>

      <div class='row'>
        <div class='col'><label>Batch date/time
          <input type='datetime-local' name='batch_dt' value='".h(date('Y-m-d\TH:i'))."'>
        </label></div>
        <div class='col'><label>Target mix (g)
          <input type='number' step='0.001' name='target' value='".number_format($target,3,'.','')."'>
        </label></div>
        <div class='col'><label>Deduct inventory?
          <select name='deduct'><option value='1'>Yes</option><option value='0'>No</option></select>
        </label></div>
      </div>

      <div class='row'>
        <div class='col'><label>Bowl weight (g) <input name='bowl' type='number' step='0.001'></label></div>
        <div class='col'><label>Bowl+mix (g) <input name='bowlmix' type='number' step='0.001'></label></div>
        <div class='col small muted' id='netWeightHint'></div>
      </div>

      <h2>Ingredients checklist</h2>";

  $tbl = "<table id='chk'><tr>
            <th class='left'>Group</th>
            <th class='left'>Ingredient</th>
            <th>Recipe qty</th>
            <th>Measured</th>
            <th>Unit</th>
            <th>WAC</th>
            <th>Ext. cost</th>
            <th>Use</th>
          </tr>";

  foreach ($groups as $cg => $items) {
    $isAlt = ($cg !== 0);
    $rowspan = max(1, count($items));
    foreach ($items as $idx => $r) {
      $ri   = (int)$r['ri_id'];
      $ing  = (int)$r['ingredient_id'];
      $unit = h($r['unit_kind']);
      $wac  = (float)$r['wac'];
      // scaled recipe qty based on target/default_yield
      $rq   = ((float)$r['recipe_qty']) * ($target / max(1e-9, (float)$v['default_yield_g']));

      $measuredName = "measured[$ri]";
      $checkedName  = "used[$ri]";
      $ingHidden    = "<input type='hidden' name='ingredient_id[$ri]' value='$ing'>";

      $useCell = "";
      $groupCell = "";
      $disabled = "";

      if ($isAlt) {
        $sel = ($selectedByGroup[$cg] ?? $ri) === $ri;
        $disabled = $sel ? "" : "disabled";
        $useCell = "<label><input type='radio' name='alt[$cg]' value='$ri' ".($sel?'checked':'')." class='altpick' data-group='$cg' data-ri='$ri'> choose</label>";
        if ($idx === 0) $groupCell = "<td rowspan='$rowspan' class='center'><strong>".(int)$cg."</strong></td>";
      } else {
        $useCell = "<input type='checkbox' name='{$checkedName}' value='1' checked class='usechk' data-ri='$ri'>";
      }

      $tbl .= "<tr>"
           . ($groupCell ?: "<td class='center'>".($isAlt?'':'—')."</td>")
           . "<td class='left'>".h($r['ingredient_name'])."</td>"
           . "<td class='right'><span class='rq' data-ri='$ri'>".number_format($rq,3)."</span></td>"
           . "<td class='right'><input type='number' step='0.001' name='{$measuredName}' value='".number_format($rq,3,'.','')."' class='meas' data-ri='$ri' $disabled></td>"
           . "<td class='center'>$unit</td>"
           . "<td class='right'><span class='wac' data-ri='$ri'>".number_format($wac,4)."</span></td>"
           . "<td class='right'><span class='ext' data-ri='$ri'>".number_format($rq*$wac,4)."</span></td>"
           . "<td class='center'>$useCell $ingHidden</td>"
           . "</tr>";
    }
  }
  $tbl .= "<tr><td colspan='6' class='right'><strong>Total cost (BWP)</strong></td>
             <td class='right'><strong id='totalCost'>0.0000</strong></td>
             <td></td></tr>
           </table>

           <p class='small muted'>Ext. cost updates with measured qty × WAC. Alternates: choose exactly one per group. Uncheck a non-alternate to skip it.</p>

           <button>Save batch</button>
    </form>

    <script>
    (function(){
      function recalcOne(ri){
        var m = parseFloat((document.querySelector(\"input[name='measured[\"+ri+\"]']\")||{}).value||'0');
        var w = parseFloat((document.querySelector(\".wac[data-ri='\"+ri+\"']\")||{}).textContent||'0');
        var ext = m*w;
        var el = document.querySelector(\".ext[data-ri='\"+ri+\"']\");
        if (el) el.textContent = (isFinite(ext)?ext:0).toFixed(4);
      }
      function recalcTotal(){
        var total = 0;
        document.querySelectorAll('#chk .ext').forEach(function(e){
          var tr = e.closest('tr');
          var radio = tr.querySelector('input.altpick');
          if (radio){
            if (!radio.checked) return;
          } else {
            var chk = tr.querySelector('input.usechk');
            if (chk && !chk.checked) return;
          }
          var v = parseFloat(e.textContent||'0');
          if (!isNaN(v)) total += v;
        });
        var t = document.getElementById('totalCost');
        if (t) t.textContent = total.toFixed(4);
      }
      function refreshNet(){
        var bowl = parseFloat(document.querySelector(\"input[name='bowl']\").value||'0');
        var mix  = parseFloat(document.querySelector(\"input[name='bowlmix']\").value||'0');
        var net  = mix - bowl;
        var h = document.getElementById('netWeightHint');
        if (h) h.textContent = isFinite(net) ? ('Net mix ≈ '+net.toFixed(3)+' g') : '';
      }
      function applyAltState(group){
        document.querySelectorAll(\"input.altpick[data-group='\"+group+\"']\").forEach(function(r){
          var ri = r.getAttribute('data-ri');
          var inp = document.querySelector(\"input[name='measured[\"+ri+\"]']\");
          if (inp) inp.disabled = !r.checked;
        });
      }
      document.querySelectorAll('input.meas').forEach(function(inp){
        inp.addEventListener('input', function(){
          var ri = this.getAttribute('data-ri'); recalcOne(ri); recalcTotal();
        });
      });
      document.querySelectorAll('input.usechk').forEach(function(chk){
        chk.addEventListener('change', function(){
          var ri = this.getAttribute('data-ri');
          var inp = document.querySelector(\"input[name='measured[\"+ri+\"]']\");
          if (inp) inp.disabled = !this.checked;
          recalcTotal();
        });
      });
      document.querySelectorAll('input.altpick').forEach(function(r){
        applyAltState(r.getAttribute('data-group'));
        r.addEventListener('change', function(){
          applyAltState(this.getAttribute('data-group'));
          recalcTotal();
        });
      });
      ['bowl','bowlmix'].forEach(function(n){
        var el = document.querySelector(\"input[name='\"+n+\"']\");
        if (el) el.addEventListener('input', refreshNet);
      });
      document.querySelectorAll('span.ext').forEach(function(e){
        var ri = e.getAttribute('data-ri'); recalcOne(ri);
      });
      recalcTotal(); refreshNet();
    })();
    </script>";

  render('New Batch', $hdr.$tbl);
});

$router->post('/batches/save', function() {
  require_login(); post_only(); csrf_verify(); global $pdo;

  $rv_id = (int)($_POST['rv_id'] ?? 0);
  if ($rv_id <= 0) { http_response_code(400); render('Batch error','<p class="err">Bad recipe version id.</p>'); return; }

  $default_yield = (float)($_POST['default_yield'] ?? 0);
  $batch_date = $_POST['batch_dt'] ?? date('Y-m-d H:i:s');
  $txn_date   = date('Y-m-d', strtotime($batch_date));
  $target     = (float)($_POST['target'] ?? 0);
  $bowl       = (float)($_POST['bowl'] ?? 0);
  $bowlmix    = (float)($_POST['bowlmix'] ?? 0);
  $actual     = $bowlmix - $bowl;
  $deduct     = (int)($_POST['deduct'] ?? 1) ? 1 : 0;

  $measured = array_map('floatval', $_POST['measured'] ?? []);
  $used     = $_POST['used'] ?? [];
  $altPick  = $_POST['alt']  ?? [];

  try {
    $pdo->beginTransaction();

    $insB = $pdo->prepare("
      INSERT INTO batches
        (recipe_version_id, batch_date, target_mix_g, bowl_weight_g, bowl_plus_mix_g, actual_mix_g, deduct_inventory, created_by)
      VALUES (?,?,?,?,?,?,?,?)
    ");
    $insB->execute([
      $rv_id, $batch_date, $target, $bowl, $bowlmix, $actual, $deduct, (int)($_SESSION['user']['id'] ?? 0)
    ]);
    $batch_id = (int)$pdo->lastInsertId();

    $ritems = $pdo->prepare("
      SELECT id, ingredient_id, choice_group, unit_kind, qty AS recipe_qty
      FROM recipe_items
      WHERE recipe_version_id=?
    ");
    $ritems->execute([$rv_id]);
    $riRows = $ritems->fetchAll();

    $byRi  = []; $groups = []; $ingIds = [];
    foreach ($riRows as $r) {
      $ri = (int)$r['id'];
      $byRi[$ri] = $r;
      $groups[(int)$r['choice_group']][] = $ri;
      $ingIds[(int)$r['ingredient_id']] = true;
    }

    $wac = [];
    if (!empty($ingIds)) {
      $place = implode(',', array_fill(0, count($ingIds), '?'));
      $stW = $pdo->prepare("SELECT ingredient_id, wac_bwp FROM v_current_wac WHERE ingredient_id IN ($place)");
      $stW->execute(array_keys($ingIds));
      foreach ($stW as $row) $wac[(int)$row['ingredient_id']] = (float)$row['wac_bwp'];
    }

    $usedRi = [];
    $totalsByIng = [];
    $rowsToInsert = [];

    foreach ($groups as $cg => $riList) {
      if ($cg === 0) {
        foreach ($riList as $ri) {
          $qty = isset($measured[$ri]) ? (float)$measured[$ri] : 0.0;
          if (!isset($used[$ri]) || $qty <= 0) continue;
          $usedRi[$ri] = true;
        }
      } else {
        $selRi = isset($altPick[$cg]) ? (int)$altPick[$cg] : 0;
        if ($selRi && in_array($selRi, $riList, true)) {
          $qty = isset($measured[$selRi]) ? (float)$measured[$selRi] : 0.0;
          if ($qty > 0) $usedRi[$selRi] = true;
        }
      }
    }

    foreach ($usedRi as $ri => $_) {
      $row = $byRi[$ri];
      $iid = (int)$row['ingredient_id'];
      $u   = $row['unit_kind'];
      $qty = (float)$measured[$ri];

      $scaled = (float)$row['recipe_qty'] * ($target / max(1e-9, $default_yield));

      $rowsToInsert[] = [
        'ri'      => $ri,
        'iid'     => $iid,
        'scaled'  => $scaled,
        'unit'    => $u,
        'meas'    => $qty,
      ];
      $totalsByIng[$iid] = ($totalsByIng[$iid] ?? 0) + $qty;
    }

    // Save resolutions (replace set)
    $pdo->prepare("DELETE FROM batch_ingredient_resolutions WHERE batch_id=?")->execute([$batch_id]);
    if (!empty($rowsToInsert)) {
      $insR = $pdo->prepare("
        INSERT INTO batch_ingredient_resolutions
          (batch_id, recipe_item_id, resolved_ingredient_id, scaled_qty, unit_kind,
           checked_flag, measured_qty, checked_at, checked_by)
        VALUES (?,?,?,?,?, 1, ?, NOW(), ?)
      ");
      $uid = (int)($_SESSION['user']['id'] ?? 0);
      foreach ($rowsToInsert as $r) {
        $insR->execute([
          $batch_id,
          $r['ri'],
          $r['iid'],
          $r['scaled'],
          $r['unit'],
          $r['meas'],
          $uid,
        ]);
      }
    }

    // Idempotent inventory deductions (txn_type = 'consumption')
    if ($deduct && !empty($totalsByIng)) {
      $uMap = [];
      $stI = $pdo->prepare("SELECT id, unit_kind FROM ingredients WHERE id IN (".implode(',', array_fill(0,count($totalsByIng),'?')).")");
      $stI->execute(array_keys($totalsByIng));
      foreach ($stI as $r) $uMap[(int)$r['id']] = $r['unit_kind'];

      foreach ($totalsByIng as $iid => $wantQty) {
        $iid = (int)$iid;
        $wantNeg = -1.0 * (float)$wantQty;

        $stEx = $pdo->prepare("
          SELECT COALESCE(SUM(qty),0) AS q
          FROM inventory_txns
          WHERE ingredient_id=? AND source_table='batches' AND source_id=?");
        $stEx->execute([$iid, $batch_id]);
        $have = (float)$stEx->fetchColumn();

        $delta = $wantNeg - $have;
        if (abs($delta) < 1e-9) continue;

        $insTxn = $pdo->prepare("
          INSERT INTO inventory_txns
            (ingredient_id, txn_ts, txn_date, txn_type, qty, unit_kind, unit_cost_bwp,
             source_table, source_id, note, created_by)
          VALUES (?, NOW(), ?, 'usage', ?, ?, 0, 'batches', ?, ?, ?)
        ");
        $note = "Batch #$batch_id usage";
        $insTxn->execute([$iid, $txn_date, $delta, $uMap[$iid] ?? 'g', $batch_id, $note, (int)($_SESSION['user']['id'] ?? 0)]);
      }
    }

    // Store COGS = SUM(measured_qty * current WAC)
    $c = $pdo->prepare("
      SELECT SUM(COALESCE(bir.measured_qty,0) * COALESCE(w.wac_bwp,0)) AS cogs
      FROM batch_ingredient_resolutions bir
      JOIN v_current_wac w ON w.ingredient_id = bir.resolved_ingredient_id
      WHERE bir.batch_id = ?
    ");
    $c->execute([$batch_id]); $cogs = (float)$c->fetchColumn();
    $pdo->prepare("UPDATE batches SET cogs_bwp=? WHERE id=?")->execute([$cogs, $batch_id]);

    $pdo->commit();
    render('Batch saved', "<p class='ok'>Batch #".(int)$batch_id." saved.</p><p><a href='".url_for("/batches/view?id=".$batch_id)."'>Open it</a></p>");

  } catch (Throwable $e) {
    $pdo->rollBack();
    render('Batch error', "<p class='err'>".h($e->getMessage())."</p><p><a href='".url_for("/batches/new?rv_id=".$rv_id)."'>Back</a></p>");
  }
});

$router->get('/batches/edit', function () {
  require_login();
  global $pdo;

  $id = (int)($_GET['id'] ?? 0);
  if ($id <= 0) { http_response_code(400); exit('Bad batch id'); }

  $h = $pdo->prepare("
    SELECT b.*, rv.default_yield_g, r.name AS recipe_name, rv.version_no
    FROM batches b
    JOIN recipe_versions rv ON rv.id = b.recipe_version_id
    JOIN recipes r          ON r.id = rv.recipe_id
    WHERE b.id = ?
  ");
  $h->execute([$id]); $B = $h->fetch();
  if (!$B) { http_response_code(404); render('Edit batch','<p class="err">Not found</p>'); return; }

  // Build the ingredient grid using recipe_items, joined with existing resolutions for values
  $rit = $pdo->prepare("
    SELECT
      ri.id AS ri_id, ri.choice_group AS cg, ri.qty AS recipe_qty, ri.unit_kind, ri.is_primary,
      i.id AS ingredient_id, i.name AS ingredient_name,
      COALESCE(w.wac_bwp,0) AS wac,
      bir.measured_qty, bir.checked_flag,
      bir.resolved_ingredient_id
    FROM recipe_items ri
    JOIN ingredients i ON i.id = ri.ingredient_id
    LEFT JOIN v_current_wac w ON w.ingredient_id = i.id
    LEFT JOIN batch_ingredient_resolutions bir ON bir.recipe_item_id = ri.id AND bir.batch_id = ?
    WHERE ri.recipe_version_id = (
      SELECT recipe_version_id FROM batches WHERE id = ?
    )
    ORDER BY (ri.choice_group=0) DESC, ri.choice_group, ri.sort_order, i.name
  ");
  $rit->execute([$id, $id]); $rows = $rit->fetchAll();

  $groups = [];
  foreach ($rows as $r) $groups[(int)$r['cg']][] = $r;

  // For alternate groups, pick the resolved RI if present; else primary; else first
  $selectedByGroup = [];
  foreach ($groups as $cg => $items) {
    if ($cg === 0) continue;
    $sel = null;
    foreach ($items as $r) {
      if ((int)($r['resolved_ingredient_id'] ?? 0) === (int)$r['ingredient_id'] && $r['measured_qty'] !== null) {
        $sel = (int)$r['ri_id']; break;
      }
    }
    if ($sel === null) {
      foreach ($items as $r) if ($r['is_primary']) { $sel = (int)$r['ri_id']; break; }
    }
    if ($sel === null) { $sel = (int)$items[0]['ri_id']; }
    $selectedByGroup[$cg] = $sel;
  }

  // Package types for packouts
  $pt = $pdo->query("SELECT id, name, nominal_g_per_unit FROM package_types ORDER BY id")->fetchAll();
  $pack = $pdo->prepare("SELECT package_type_id, unit_count FROM batch_packouts WHERE batch_id=?");
  $pack->execute([$id]); $packMap = [];
  foreach ($pack as $p) $packMap[(int)$p['package_type_id']] = (int)$p['unit_count'];

  $tok = csrf_token();
  
  $hdr = "<h1>Edit Batch #".(int)$B['id']." — ".h($B['recipe_name'])." v".(int)$B['version_no']."</h1>
  <form method='post' action='".url_for("/batches/update")."' id='batchEdit'>
    ".csrf_field($tok)."
    <input type='hidden' name='id' value='".(int)$B['id']."'>
    <input type='hidden' name='default_yield' value='".number_format((float)$B['default_yield_g'],3,'.','')."'>

    <div class='row'>
      <div class='col'><label>Batch date/time
        <input type='datetime-local' name='batch_dt' value='".h(date('Y-m-d\TH:i', strtotime($B['batch_date'])))."'>
      </label></div>
      <div class='col'><label>Target mix (g)
        <input type='number' step='0.001' name='target' value='".number_format((float)$B['target_mix_g'],3,'.','')."'>
      </label></div>
      <div class='col'><label>Deduct inventory?
        <select name='deduct'><option value='1' ".((int)$B['deduct_inventory']?'selected':'').">Yes</option><option value='0' ".(!(int)$B['deduct_inventory']?'selected':'').">No</option></select>
      </label></div>
    </div>

    <div class='row'>
      <div class='col'><label>Empty-bowl weight (g) <input name='bowl' type='number' step='0.001' value='".number_format((float)$B['bowl_weight_g'],3,'.','')."'></label></div>
      <div class='col'><label>Bowl+mix (g) <input name='bowlmix' type='number' step='0.001' value='".number_format((float)$B['bowl_plus_mix_g'],3,'.','')."'></label></div>
      <div class='col small muted'>Net mix ≈ ".number_format((float)$B['actual_mix_g'],3)." g</div>
    </div>

    <h2>Stage 2: Churn + Pack</h2>
    <div class='row'>
      <div class='col'><label>Churn start (local)
        <input type='datetime-local' name='churn_start' value='".($B['churn_start_dt']?h(date('Y-m-d\TH:i', strtotime($B['churn_start_dt']))):"")."'>
      </label></div>
      <div class='col'><label>Bowl w/ residue (g)
        <input type='number' step='0.001' name='bowl_after' value='".($B['bowl_with_residue_g']!==null?number_format((float)$B['bowl_with_residue_g'],3,'.',''):"")."'>
      </label>
      <div class='small muted'>Leftover ≈ (this) − ".number_format(CHURN_BOWL_TARE_G,1)." g</div></div>
    </div>

    <h3>Packouts</h3>
    <div class='row'>";
  foreach ($pt as $p) {
    $val = isset($packMap[(int)$p['id']]) ? (int)$packMap[(int)$p['id']] : 0;
    $hdr .= "<div class='col'><label>".h($p['name'])." units
               <input type='number' min='0' step='1' name='pack[".(int)$p['id']."]' value='{$val}'>
             </label><div class='small muted'>Nominal ".number_format((float)$p['nominal_g_per_unit'],2)." g</div></div>";
  }
  $hdr .= "</div>

    <h2>Ingredients checklist (adjust if needed)</h2>";

  // Build table (like /batches/new) but pre-filled from resolutions
  $tbl = "<table id='chk'><tr>
            <th class='left'>Group</th>
            <th class='left'>Ingredient</th>
            <th>Scaled qty</th>
            <th>Measured</th>
            <th>Unit</th>
            <th>WAC</th>
            <th>Ext. cost</th>
            <th>Use</th>
          </tr>";

  $target = (float)$B['target_mix_g'];
  foreach ($groups as $cg => $items) {
    $isAlt = ($cg !== 0);
    $rowspan = max(1, count($items));
    foreach ($items as $idx => $r) {
      $ri   = (int)$r['ri_id'];
      $ing  = (int)$r['ingredient_id'];
      $unit = h($r['unit_kind']);
      $wac  = (float)$r['wac'];
      $scaled = ((float)$r['recipe_qty']) * ($target / max(1e-9, (float)$B['default_yield_g']));

      $measuredName = "measured[$ri]";
      $checkedName  = "used[$ri]";
      $ingHidden    = "<input type='hidden' name='ingredient_id[$ri]' value='$ing'>";

      // prior values
      $measVal = $r['measured_qty'] !== null ? (float)$r['measured_qty'] : $scaled;

      $useCell = "";
      $groupCell = "";
      $disabled = "";

      if ($isAlt) {
        $sel = ($selectedByGroup[$cg] ?? $ri) === $ri;
        $disabled = $sel ? "" : "disabled";
        $useCell = "<label><input type='radio' name='alt[$cg]' value='$ri' ".($sel?'checked':'')." class='altpick' data-group='$cg' data-ri='$ri'> choose</label>";
        if ($idx === 0) $groupCell = "<td rowspan='$rowspan' class='center'><strong>".(int)$cg."</strong></td>";
      } else {
        $checked = ($r['measured_qty'] !== null); // present in resolutions => used
        $useCell = "<input type='checkbox' name='{$checkedName}' value='1' ".($checked?'checked':'')." class='usechk' data-ri='$ri'>";
      }

      $tbl .= "<tr>"
           . ($groupCell ?: "<td class='center'>".($isAlt?'':'—')."</td>")
           . "<td class='left'>".h($r['ingredient_name'])."</td>"
           . "<td class='right'><span class='rq' data-ri='$ri'>".number_format($scaled,3)."</span></td>"
           . "<td class='right'><input type='number' step='0.001' name='{$measuredName}' value='".number_format($measVal,3,'.','')."' class='meas' data-ri='$ri' $disabled></td>"
           . "<td class='center'>$unit</td>"
           . "<td class='right'><span class='wac' data-ri='$ri'>".number_format($wac,4)."</span></td>"
           . "<td class='right'><span class='ext' data-ri='$ri'>".number_format($measVal*$wac,4)."</span></td>"
           . "<td class='center'>$useCell $ingHidden</td>"
           . "</tr>";
    }
  }
  $tbl .= "<tr><td colspan='6' class='right'><strong>Total cost (BWP)</strong></td>
             <td class='right'><strong id='totalCost'>0.0000</strong></td>
             <td></td></tr>
           </table>

           <p class='small muted'>Adjust measured values and alternates as needed. Saving will reconcile inventory and COGS.</p>

           <button>Save changes</button>
  </form>

  <script>
  (function(){
    function recalcOne(ri){
      var m = parseFloat((document.querySelector(\"input[name='measured[\"+ri+\"]']\")||{}).value||'0');
      var w = parseFloat((document.querySelector(\".wac[data-ri='\"+ri+\"']\")||{}).textContent||'0');
      var el = document.querySelector(\".ext[data-ri='\"+ri+\"']\");
      var ext = m*w; if (el) el.textContent = (isFinite(ext)?ext:0).toFixed(4);
    }
    function recalcTotal(){
      var total = 0;
      document.querySelectorAll('#chk .ext').forEach(function(e){
        var tr = e.closest('tr');
        var radio = tr.querySelector('input.altpick');
        if (radio){
          if (!radio.checked) return;
        } else {
          var chk = tr.querySelector('input.usechk');
          if (chk && !chk.checked) return;
        }
        var v = parseFloat(e.textContent||'0');
        if (!isNaN(v)) total += v;
      });
      var t = document.getElementById('totalCost');
      if (t) t.textContent = total.toFixed(4);
    }
    function applyAltState(group){
      document.querySelectorAll(\"input.altpick[data-group='\"+group+\"']\").forEach(function(r){
        var ri = r.getAttribute('data-ri');
        var inp = document.querySelector(\"input[name='measured[\"+ri+\"]']\");
        if (inp) inp.disabled = !r.checked;
      });
    }
    document.querySelectorAll('input.meas').forEach(function(inp){
      inp.addEventListener('input', function(){
        var ri = this.getAttribute('data-ri'); recalcOne(ri); recalcTotal();
      });
    });
    document.querySelectorAll('input.usechk').forEach(function(chk){
      chk.addEventListener('change', function(){
        var ri = this.getAttribute('data-ri');
        var inp = document.querySelector(\"input[name='measured[\"+ri+\"]']\");
        if (inp) inp.disabled = !this.checked;
        recalcTotal();
      });
    });
    document.querySelectorAll('input.altpick').forEach(function(r){
      applyAltState(r.getAttribute('data-group'));
      r.addEventListener('change', function(){
        applyAltState(this.getAttribute('data-group'));
        recalcTotal();
      });
    });
    document.querySelectorAll('span.ext').forEach(function(e){
      var ri = e.getAttribute('data-ri'); recalcOne(ri);
    });
    recalcTotal();
  })();
  </script>";

  render('Edit Batch', $hdr.$tbl);
});

$router->post('/batches/update', function () {
  require_login(); post_only(); csrf_verify();
  global $pdo;

  $id = (int)($_POST['id'] ?? 0);
  if ($id <= 0) { http_response_code(400); render('Batch error','<p class="err">Bad id.</p>'); return; }

  $default_yield = (float)($_POST['default_yield'] ?? 0);
  $batch_date = $_POST['batch_dt'] ?? date('Y-m-d H:i:s');
  $txn_date   = date('Y-m-d', strtotime($batch_date));
  $target     = (float)($_POST['target'] ?? 0);
  $bowl       = (float)($_POST['bowl'] ?? 0);
  $bowlmix    = (float)($_POST['bowlmix'] ?? 0);
  $actual     = $bowlmix - $bowl;
  $deduct     = (int)($_POST['deduct'] ?? 1) ? 1 : 0;

  $churn_start = $_POST['churn_start'] ?? null;
  $bowl_after  = ($_POST['bowl_after'] ?? '') !== '' ? (float)$_POST['bowl_after'] : null;
  $residue     = $bowl_after !== null ? max($bowl_after - CHURN_BOWL_TARE_G, 0) : null;

  $measured = array_map('floatval', $_POST['measured'] ?? []);
  $used     = $_POST['used'] ?? [];
  $altPick  = $_POST['alt']  ?? [];
  $pack     = $_POST['pack'] ?? [];

  try {
    $pdo->beginTransaction();

    // Lock header, get rv_id
    $st = $pdo->prepare("SELECT recipe_version_id FROM batches WHERE id=? FOR UPDATE");
    $st->execute([$id]); $rv_id = (int)($st->fetchColumn() ?? 0);
    if ($rv_id <= 0) throw new RuntimeException('Batch not found');

    // Update header
    $pdo->prepare("
      UPDATE batches
      SET batch_date=?, target_mix_g=?, bowl_weight_g=?, bowl_plus_mix_g=?, actual_mix_g=?,
          deduct_inventory=?, churn_start_dt=?, bowl_with_residue_g=?, residue_g=?
      WHERE id=?
    ")->execute([
      $batch_date, $target, $bowl, $bowlmix, $actual,
      $deduct, ($churn_start ?: null), $bowl_after, $residue, $id
    ]);

    // Build recipe items
    $ritems = $pdo->prepare("
      SELECT id, ingredient_id, choice_group, unit_kind, qty AS recipe_qty
      FROM recipe_items
      WHERE recipe_version_id=?
    ");
    $ritems->execute([$rv_id]);
    $riRows = $ritems->fetchAll();

    $byRi  = []; $groups = []; $ingIds = [];
    foreach ($riRows as $r) {
      $ri = (int)$r['id'];
      $byRi[$ri] = $r;
      $groups[(int)$r['choice_group']][] = $ri;
      $ingIds[(int)$r['ingredient_id']] = true;
    }

    $wac = [];
    if (!empty($ingIds)) {
      $place = implode(',', array_fill(0, count($ingIds), '?'));
      $stW = $pdo->prepare("SELECT ingredient_id, wac_bwp FROM v_current_wac WHERE ingredient_id IN ($place)");
      $stW->execute(array_keys($ingIds));
      foreach ($stW as $row) $wac[(int)$row['ingredient_id']] = (float)$row['wac_bwp'];
    }

    // Compute which RIs are used
    $usedRi = [];
    $totalsByIng = [];
    $rowsToInsert = [];

    foreach ($groups as $cg => $riList) {
      if ($cg === 0) {
        foreach ($riList as $ri) {
          $qty = isset($measured[$ri]) ? (float)$measured[$ri] : 0.0;
          if (!isset($used[$ri]) || $qty <= 0) continue;
          $usedRi[$ri] = true;
        }
      } else {
        $selRi = isset($altPick[$cg]) ? (int)$altPick[$cg] : 0;
        if ($selRi && in_array($selRi, $riList, true)) {
          $qty = isset($measured[$selRi]) ? (float)$measured[$selRi] : 0.0;
          if ($qty > 0) $usedRi[$selRi] = true;
        }
      }
    }

    foreach ($usedRi as $ri => $_) {
      $row = $byRi[$ri];
      $iid = (int)$row['ingredient_id'];
      $u   = $row['unit_kind'];
      $qty = (float)$measured[$ri];
      $scaled = (float)$row['recipe_qty'] * ($target / max(1e-9, $default_yield));

      $rowsToInsert[] = [
        'ri'      => $ri,
        'iid'     => $iid,
        'scaled'  => $scaled,
        'unit'    => $u,
        'meas'    => $qty,
      ];
      $totalsByIng[$iid] = ($totalsByIng[$iid] ?? 0) + $qty;
    }

    // Replace resolutions
    $pdo->prepare("DELETE FROM batch_ingredient_resolutions WHERE batch_id=?")->execute([$id]);
    if (!empty($rowsToInsert)) {
      $insR = $pdo->prepare("
        INSERT INTO batch_ingredient_resolutions
          (batch_id, recipe_item_id, resolved_ingredient_id, scaled_qty, unit_kind,
           checked_flag, measured_qty, checked_at, checked_by)
        VALUES (?,?,?,?,?, 1, ?, NOW(), ?)
      ");
      $uid = (int)($_SESSION['user']['id'] ?? 0);
      foreach ($rowsToInsert as $r) {
        $insR->execute([$id, $r['ri'], $r['iid'], $r['scaled'], $r['unit'], $r['meas'], $uid]);
      }
    }

    // Upsert packouts
    if (!empty($pack)) {
      $insP = $pdo->prepare("
        INSERT INTO batch_packouts (batch_id, package_type_id, unit_count)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE unit_count=VALUES(unit_count)
      ");
      foreach ($pack as $pt_id => $cnt) {
        $cnt = (int)$cnt;
        if ($cnt < 0) $cnt = 0;
        $insP->execute([$id, (int)$pt_id, $cnt]);
      }
    }

    // Idempotent inventory reconciliation
    // First, zero out prior consumption rows for this batch (we’ll re-apply deltas below)
    // NOTE: Keeping Idempotent pattern — compare desired total with already-on-ledger and insert delta
    if (!empty($totalsByIng)) {
      $uMap = [];
      $stI = $pdo->prepare("SELECT id, unit_kind FROM ingredients WHERE id IN (".implode(',', array_fill(0,count($totalsByIng),'?')).")");
      $stI->execute(array_keys($totalsByIng));
      foreach ($stI as $r) $uMap[(int)$r['id']] = $r['unit_kind'];

      foreach ($totalsByIng as $iid => $wantQty) {
        $iid = (int)$iid;
        $wantNeg = -1.0 * (float)$wantQty;

        $stEx = $pdo->prepare("
          SELECT COALESCE(SUM(qty),0) AS q
          FROM inventory_txns
          WHERE ingredient_id=? AND source_table='batches' AND source_id=?");
        $stEx->execute([$iid, $id]);
        $have = (float)$stEx->fetchColumn();

        $delta = $wantNeg - $have;
        if (abs($delta) < 1e-9) continue;

        $insTxn = $pdo->prepare("
          INSERT INTO inventory_txns
            (ingredient_id, txn_ts, txn_date, txn_type, qty, unit_kind, unit_cost_bwp,
             source_table, source_id, note, created_by)
          VALUES (?, NOW(), ?, 'usage', ?, ?, 0, 'batches', ?, ?, ?)
        ");
        $note = "Batch #$id usage (edit)";
        $insTxn->execute([$iid, $txn_date, $delta, $uMap[$iid] ?? 'g', $id, $note, (int)($_SESSION['user']['id'] ?? 0)]);
      }
    } else {
      // If nothing used now, remove any prior consumption for this batch
      $pdo->prepare("DELETE FROM inventory_txns WHERE source_table='batches' AND source_id=?")->execute([$id]);
    }

    // Store COGS = SUM(measured_qty * current WAC)
    $c = $pdo->prepare("
      SELECT SUM(COALESCE(bir.measured_qty,0) * COALESCE(w.wac_bwp,0)) AS cogs
      FROM batch_ingredient_resolutions bir
      JOIN v_current_wac w ON w.ingredient_id = bir.resolved_ingredient_id
      WHERE bir.batch_id = ?
    ");
    $c->execute([$id]); $cogs = (float)$c->fetchColumn();
    $pdo->prepare("UPDATE batches SET cogs_bwp=? WHERE id=?")->execute([$cogs, $id]);

    $pdo->commit();
    header('Location: ' . url_for('/batches/view?id='.$id));

  } catch (Throwable $e) {
    $pdo->rollBack();
    render('Batch update error', "<p class='err'>".h($e->getMessage())."</p><p><a href='".url_for("/batches/edit?id=".$id)."'>Back</a></p>");
  }
});
