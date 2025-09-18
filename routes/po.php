<?php
declare(strict_types=1);

/**
 * Self-dispatching PO routes.
 * Requires: $pdo, render(), h(), url_for(), require_login(), role_is(),
 * csrf_token(), csrf_verify(), db_tx(); fx_* helpers; recalc_wac().
 */
(function () use ($pdo) {
  $path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
  $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
  $is = function(string $m, string $p) use ($method, $path): bool {
    return strtoupper($m) === strtoupper($method) && rtrim($path, '/') === rtrim($p, '/');
  };

    // ---- LIST: GET /po ----
    if ($is('GET','/po')) {
      require_admin();
    
      $rows = $pdo->query("
        SELECT
          po.id,
          po.order_date,
          po.po_number,
          po.currency,
          po.fx_rate_used,
          COALESCE(s.name,'') AS supplier_name,
          COUNT(pol.id) AS line_count,
          COALESCE(SUM(pol.qty * pol.unit_cost_bwp), 0) AS total_bwp
        FROM purchase_orders po
        LEFT JOIN suppliers s ON s.id = po.supplier_id
        LEFT JOIN purchase_order_lines pol ON pol.purchase_order_id = po.id
        GROUP BY po.id, po.order_date, po.po_number, po.currency, po.fx_rate_used, supplier_name
        ORDER BY po.order_date DESC, po.id DESC
        LIMIT 200
      ")->fetchAll();
    
      $list = "<table>
        <tr><th>Date</th><th>PO #</th><th>Supplier</th>
            <th class='right'>Lines</th><th class='right'>Total (BWP)</th><th>Actions</th></tr>";
    
      foreach ($rows as $r) {
        $supp = (string)($r['supplier_name'] ?? '');
        $supplierHtml = ($supp !== '') ? h($supp) : "<span class='muted'>—</span>";
        $list .= "<tr>
          <td>".h($r['order_date'])."</td>
          <td>".h((string)$r['po_number'])."</td>
          <td>".$supplierHtml."</td>
          <td class='right'>".(int)$r['line_count']."</td>
          <td class='right'>".number_format((float)$r['total_bwp'], 2)."</td>
          <td><a href='".url_for("/po/view?id=".(int)$r['id'])."'>View</a> &nbsp;|&nbsp;
              <a href='".url_for("/po/edit?id=".(int)$r['id'])."'>Edit</a></td>
        </tr>";
      }
      if (!$rows) {
        $list .= "<tr><td colspan='6' class='center muted'>No purchase orders yet.</td></tr>";
      }
      $list .= "</table>";
    
      $btn = "<p><a class='btn' href='".url_for("/po/new")."'>New PO</a></p>";
      render('Purchase Orders', "<h1>Purchase Orders</h1>{$btn}{$list}{$btn}");
      exit;
    }

  // ---- VIEW: GET /po/view?id=... ----
  if ($is('GET','/po/view')) {
    require_admin();
    $id = (int)($_GET['id'] ?? 0); if ($id<=0) { http_response_code(400); exit('Bad id'); }

    $po = $pdo->prepare("
      SELECT po.*, COALESCE(s.name,'') AS supplier_name
      FROM purchase_orders po
      LEFT JOIN suppliers s ON s.id=po.supplier_id
      WHERE po.id=?
    ");
    $po->execute([$id]); $H = $po->fetch();
    if (!$H) { http_response_code(404); render('PO','<p class="err">Not found</p>'); exit; }

    $lines = $pdo->prepare("
      SELECT pol.*, i.name AS ingredient_name, i.unit_kind
      FROM purchase_order_lines pol
      JOIN ingredients i ON i.id=pol.ingredient_id
      WHERE pol.purchase_order_id=?
      ORDER BY pol.id
    ");
    $lines->execute([$id]); $L = $lines->fetchAll();

    $hdr = "<h1>PO ".h((string)$H['po_number'])."</h1>
            <p>Date: ".h($H['order_date'])." • Supplier: ".h((string)$H['supplier_name'])."
               • Currency: ".h($H['currency'])." @ ".number_format((float)$H['fx_rate_used'],6)."</p>
            <p><a href='".url_for("/po/edit?id=".$id)."'>Edit this PO</a></p>";

    $tbl = "<table><tr><th>Ingredient</th><th class='right'>Qty</th><th>Unit</th>
                   <th class='right'>Unit cost (native)</th><th class='right'>Unit cost (BWP)</th><th>Note</th></tr>";
    $total = 0.0;
    foreach ($L as $r) {
      $total += (float)$r['qty'] * (float)$r['unit_cost_bwp'];
      $tbl .= "<tr>
        <td>".h($r['ingredient_name'])."</td>
        <td class='right'>".number_format((float)$r['qty'],3)."</td>
        <td>".h($r['unit_kind'])."</td>
        <td class='right'>".number_format((float)$r['unit_cost_native'],4)."</td>
        <td class='right'>".number_format((float)$r['unit_cost_bwp'],4)."</td>
        <td>".h((string)$r['line_note'])."</td>
      </tr>";
    }
    $tbl .= "<tr><td colspan='4'></td><td class='right'><strong>Total</strong></td>
                <td class='right'><strong>".number_format($total,2)."</strong></td></tr></table>";

    render('View PO', $hdr.$tbl);
    exit;
  }

  // ---- NEW: GET /po/new ----
  if ($is('GET','/po/new')) {
    require_admin();
    $ing = $pdo->query("SELECT id,name,unit_kind FROM ingredients WHERE is_active=1 ORDER BY name")->fetchAll();
    $opt = ""; foreach ($ing as $i) { $opt .= "<option value='".(int)$i['id']."'>".h($i['name'])." (".h($i['unit_kind']).")</option>"; }

    $tok = csrf_token();
    $today = date('Y-m-d');
    $currencyDefault = 'BWP';
    $fxPrefill = 1.000000; $fxSource = 'par';
    if (!empty($_GET['currency'])) $currencyDefault = preg_replace('/[^A-Z]/','', strtoupper($_GET['currency']));
    if ($currencyDefault !== 'BWP') {
      $q = fx_get_cached($pdo, $today, $currencyDefault, 'BWP');
      if (!$q) $q = fx_get_manual_prev($pdo, $today, $currencyDefault, 'BWP');
      if ($q) { $fxPrefill = (float)$q['rate']; $fxSource = $q['source'].' (db)'; }
    }

    $body = "<h1>New Purchase Order</h1>
    <form method='post' action='".url_for("/po/save")."' id='poForm'>
      <input type='hidden' name='_csrf' value='".h($tok)."'>
      <div class='row'>
        <div class='col'><label>PO Number <input name='po_number'></label></div>
        <div class='col'><label>Supplier (free text) <input name='supplier'></label></div>
        <div class='col'><label>Order date <input type='date' name='order_date' value='".h($today)."'></label></div>
        <div class='col'><label>Currency
          <select name='currency' id='curSel'>
            <option ".($currencyDefault==='USD'?'selected':'').">USD</option>
            <option ".($currencyDefault==='BWP'?'selected':'').">BWP</option>
            <option ".($currencyDefault==='ZAR'?'selected':'').">ZAR</option>
          </select></label></div>
        <div class='col'><label>FX rate to BWP
          <input type='number' name='fx' id='fx' step='0.000001' value='".number_format($fxPrefill,6,'.','')."'>
        </label>
        <div class='small muted' id='fxsrc'>source: ".h($fxSource)."</div></div>
      </div>

      <fieldset><legend>Lines</legend>
        <table>
          <thead>
            <tr><th>Ingredient</th><th>Qty (canonical)</th><th>Line total (native)</th><th>Note</th><th></th></tr>
          </thead>
          <tbody id='lines'>
            <tr>
              <td><select name='ing_id[]'>{$opt}</select></td>
              <td><input name='qty[]' type='number' step='0.001'></td>
              <td><input name='uc_native[]' type='number' step='0.0001'></td>
              <td><input name='lnote[]'></td>
              <td><button class='link danger' onclick='return rmLine(this)'>Remove</button></td>
            </tr>
          </tbody>
        </table>
        <p><button class='link' onclick='return addLine()'>+ Add line</button></p>
      </fieldset>

      <button>Save PO</button>
    </form>

    <script>
    (function(){
      var cur  = document.getElementById('curSel');
      var date = document.querySelector(\"input[name='order_date']\");
      function refresh(){
        if (!cur) return;
        var c = cur.value;
        if (c==='BWP'){ document.getElementById('fx').value='1.000000'; document.getElementById('fxsrc').textContent='source: par'; return; }
        var d = date && date.value ? date.value : '';
        fetch('".h(url_for('/fx/quote'))."?currency='+encodeURIComponent(c)+'&date='+encodeURIComponent(d))
          .then(r=>r.json()).then(function(j){
            if (j && j.rate){ document.getElementById('fx').value=(+j.rate).toFixed(6); document.getElementById('fxsrc').textContent='source: '+(j.source||'auto'); }
            else { document.getElementById('fxsrc').textContent='source: manual (no quote)'; }
          }).catch(function(){ document.getElementById('fxsrc').textContent='source: manual (lookup failed)'; });
      }
      if (cur)  cur.addEventListener('change', refresh);
      if (date) date.addEventListener('change', refresh);
      refresh();

      window.addLine = function(){
        var tb = document.getElementById('lines');
        var tr = document.createElement('tr');
        tr.innerHTML = \"<td><select name='ing_id[]'>".str_replace(["\n","\r","'"],["","","&#39;"],$opt)."</select></td>\"+
                       \"<td><input name='qty[]' type='number' step='0.001'></td>\"+
                       \"<td><input name='uc_native[]' type='number' step='0.0001'></td>\"+
                       \"<td><input name='lnote[]'></td>\"+
                       \"<td><button class='link danger' onclick='return rmLine(this)'>Remove</button></td>\";
        tb.appendChild(tr);
        return false;
      };
      window.rmLine = function(btn){
        var tr = btn.closest('tr');
        var tb = document.getElementById('lines');
        if (tb && tr && tb.rows.length > 1) tb.removeChild(tr);
        return false;
      };
    })();
    </script>";
    render('New PO', $body);
    exit;
  }

  // ---- SAVE: POST /po/save ----
  if ($is('POST','/po/save')) {
    require_admin();
    csrf_verify();

    db_tx(function(PDO $pdo) {
      $po_number = trim($_POST['po_number'] ?? '');
      $supplier_name = trim($_POST['supplier'] ?? '');
      $order_date = $_POST['order_date'] ?? date('Y-m-d');
      $currency = $_POST['currency'] ?? 'BWP';
      $fx = (float)($_POST['fx'] ?? 1.0);

      if ($currency !== 'BWP') {
        $src = 'manual';
        if ($fx <= 0) {
          $q = fx_get_rate($pdo, $order_date, $currency, 'BWP');
          if ($q) { $fx = (float)$q['rate']; $src = $q['source']; }
        }
        if ($fx > 0) fx_cache_upsert($pdo, $order_date, $currency, 'BWP', (float)$fx, $src);
        if ($fx <= 0) {
          $q = fx_get_cached($pdo, $order_date, $currency, 'BWP');
          if (!$q) $q = fx_get_manual_prev($pdo, $order_date, $currency, 'BWP');
          if ($q) $fx = (float)$q['rate'];
        }
        if ($fx > 0) fx_upsert_manual($pdo, $order_date, $currency, 'BWP', (float)$fx);
      }

      // supplier upsert
      $supplier_id = null;
      if ($supplier_name !== '') {
        $s = $pdo->prepare("SELECT id FROM suppliers WHERE name=?");
        $s->execute([$supplier_name]); $row = $s->fetch();
        if ($row) $supplier_id = (int)$row['id'];
        else { $s = $pdo->prepare("INSERT INTO suppliers (name) VALUES (?)"); $s->execute([$supplier_name]); $supplier_id = (int)$pdo->lastInsertId(); }
      }

      $stmt = $pdo->prepare("INSERT INTO purchase_orders (po_number, supplier_id, order_date, currency, fx_rate_used, created_by) VALUES (?,?,?,?,?,?)");
      $stmt->execute([$po_number ?: null, $supplier_id, $order_date, $currency, $fx, (int)($_SESSION['user']['id'] ?? 0)]);
      $po_id = (int)$pdo->lastInsertId();

      $ing_ids = $_POST['ing_id'] ?? [];
      $qtys    = $_POST['qty'] ?? [];
      $ucs     = $_POST['uc_native'] ?? [];
      $lns     = $_POST['lnote'] ?? [];

      for ($i=0; $i<count($ing_ids); $i++) {
        $ing_id = (int)$ing_ids[$i];
        $qty    = (float)$qtys[$i];
        $line_total_native = (float)($ucs[$i] ?? 0);
        $ucn = $qty > 0 ? $line_total_native / $qty : 0.0;
        $lnote = trim($lns[$i] ?? '');
        if ($ing_id && $qty > 0 && $ucn > 0) {
          $ucb = $ucn * $fx;
          $p = $pdo->prepare("INSERT INTO purchase_order_lines (purchase_order_id, ingredient_id, qty, unit_cost_native, unit_cost_bwp, line_note) VALUES (?,?,?,?,?,?)");
          $p->execute([$po_id, $ing_id, $qty, $ucn, $ucb, $lnote ?: null]);
          $pol_id = (int)$pdo->lastInsertId();

          $inv = $pdo->prepare("INSERT INTO inventory_txns (ingredient_id, txn_ts, txn_date, txn_type, qty, unit_kind, unit_cost_bwp, source_table, source_id, note, created_by)
                                VALUES (?, NOW(), ?, 'purchase', ?, (SELECT unit_kind FROM ingredients WHERE id=?), ?, 'purchase_order_lines', ?, ?, ?)");
          $inv->execute([$ing_id, $order_date, $qty, $ing_id, $ucb, $pol_id, "PO #$po_id", (int)($_SESSION['user']['id'] ?? 0)]);
        }
      }

      // Recalc WAC for affected ingredients
      if (!function_exists('recalc_wac')) { require_once __DIR__ . '/../app/wac_tools.php'; }
      $iidRows = $pdo->prepare("SELECT DISTINCT ingredient_id FROM purchase_order_lines WHERE purchase_order_id=?");
      $iidRows->execute([$po_id]);
      foreach ($iidRows as $r) recalc_wac($pdo, (int)$r['ingredient_id']);

      return true;
    });

    header('Location: ' . url_for('/po'));
    exit;
  }

  // ---- EDIT: GET /po/edit?id=... ----
  if ($is('GET','/po/edit')) {
    require_admin();
    $id = (int)($_GET['id'] ?? 0); if ($id<=0) { http_response_code(400); exit('Bad id'); }

    $po = $pdo->prepare("
      SELECT po.*, COALESCE(s.name,'') AS supplier_name
      FROM purchase_orders po
      LEFT JOIN suppliers s ON s.id=po.supplier_id
      WHERE po.id=?
    ");
    $po->execute([$id]); $H = $po->fetch();
    if (!$H) { http_response_code(404); render('Edit PO','<p class="err">Not found</p>'); exit; }

    $ing = $pdo->query("SELECT id,name,unit_kind FROM ingredients WHERE is_active=1 ORDER BY name")->fetchAll();
    $buildOpts = function(int $sel) use ($ing): string {
      $o = '';
      foreach ($ing as $i) {
        $sid = (int)$i['id'];
        $o .= "<option value='{$sid}'".($sid===$sel?' selected':'').">".h($i['name'])." (".h($i['unit_kind']).")</option>";
      }
      return $o;
    };

    $lines = $pdo->prepare("
      SELECT pol.*, i.name AS ingredient_name, i.unit_kind
      FROM purchase_order_lines pol
      JOIN ingredients i ON i.id=pol.ingredient_id
      WHERE pol.purchase_order_id=?
      ORDER BY pol.id
    ");
    $lines->execute([$id]); $L = $lines->fetchAll();

    $tok = csrf_token();

    $hdr = "<h1>Edit PO ".h((string)$H['po_number'])."</h1>
      <form method='post' action='".url_for("/po/update")."' id='poEdit'>
        <input type='hidden' name='_csrf' value='".h($tok)."'>
        <input type='hidden' name='id' value='".(int)$id."'>
        <div class='row'>
          <div class='col'><label>PO Number <input name='po_number' value='".h((string)$H['po_number'])."'></label></div>
          <div class='col'><label>Supplier (free text) <input name='supplier' value='".h((string)$H['supplier_name'])."'></label></div>
          <div class='col'><label>Order date <input type='date' name='order_date' value='".h($H['order_date'])."'></label></div>
          <div class='col'><label>Currency <input value='".h($H['currency'])."' disabled></label></div>
          <div class='col'><label>FX→BWP <input value='".number_format((float)$H['fx_rate_used'],6,'.','')."' disabled></label></div>
        </div>";

    $tbl = "<fieldset><legend>Lines</legend>
      <table>
        <thead>
          <tr><th>Ingredient</th><th>Qty (canonical)</th><th>Line total (native)</th><th>Note</th><th></th></tr>
        </thead>
        <tbody id='lines'>";
        if ($L) {
          foreach ($L as $r) {
            $qtyVal = number_format((float)$r['qty'], 3, '.', '');
            $lineTotalNative = number_format(((float)$r['unit_cost_native'] * (float)$r['qty']), 4, '.', '');
            $tbl .= "<tr>
              <td><select name='ing_id[]'>".$buildOpts((int)$r['ingredient_id'])."</select></td>
              <td><input name='qty[]' type='number' step='0.001' required value='".$qtyVal."'></td>
              <td><input name='uc_native[]' type='number' step='0.0001' required value='".$lineTotalNative."'></td>
              <td><input name='lnote[]' value='".h((string)$r['line_note'])."'></td>
              <td><button class='link danger' onclick='return rmLine(this)'>Remove</button></td>
            </tr>";
          }
        } else {
          $tbl .= "<tr>
            <td><select name='ing_id[]'>".$buildOpts(0)."</select></td>
            <td><input name='qty[]' type='number' step='0.001' required></td>
            <td><input name='uc_native[]' type='number' step='0.0001' required></td>
            <td><input name='lnote[]'></td>
            <td><button class='link danger' onclick='return rmLine(this)'>Remove</button></td>
          </tr>";
        }
    $tbl .= "</tbody></table><p><button class='link' onclick='return addLine()'>+ Add line</button></p></fieldset>";

    // Build one blank line row in PHP, then JSON-encode it for JS
    $optsForNew = str_replace(["\n", "\r"], '', $buildOpts(0));
    $rowTpl = "<td><select name='ing_id[]'>".$optsForNew."</select></td>"
            . "<td><input name='qty[]' type='number' step='0.001' required></td>"
            . "<td><input name='uc_native[]' type='number' step='0.0001' required></td>"
            . "<td><input name='lnote[]'></td>"
            . "<td><button class='link danger' onclick='return rmLine(this)'>Remove</button></td>";
    $rowTplJs = json_encode($rowTpl, JSON_UNESCAPED_SLASHES);
    
    $js = "<script>
    (function(){
      window.addLine = function(){
        var tb = document.getElementById('lines');
        var tr = document.createElement('tr');
        tr.innerHTML = " . $rowTplJs . ";
        tb.appendChild(tr);
        return false;
      };
      window.rmLine = function(btn){
        var tr = btn.closest('tr');
        var tb = document.getElementById('lines');
        if (tb && tr && tb.rows.length > 1) tb.removeChild(tr);
        return false;
      };
    })();
    </script>";

    render('Edit PO', $hdr.$tbl."<button>Save changes</button></form>".$js);
    exit;
  }

  // ---- UPDATE: POST /po/update ----
  if ($is('POST','/po/update')) {
    require_admin();
    csrf_verify();

    $id          = (int)($_POST['id'] ?? 0);
    $po_number   = trim($_POST['po_number'] ?? '');
    $supplier_nm = trim($_POST['supplier'] ?? '');
    $order_date  = $_POST['order_date'] ?? date('Y-m-d');
    if ($id<=0) { http_response_code(400); exit('Bad id'); }

    db_tx(function(PDO $pdo) use ($id,$po_number,$supplier_nm,$order_date) {
      // lock header
      $st = $pdo->prepare("SELECT * FROM purchase_orders WHERE id=? FOR UPDATE");
      $st->execute([$id]); $H = $st->fetch();
      if (!$H) throw new RuntimeException('PO not found');
      $fx = (float)$H['fx_rate_used'];

      // Supplier upsert
      $supplier_id = null;
      if ($supplier_nm !== '') {
        $s = $pdo->prepare("SELECT id FROM suppliers WHERE name=?");
        $s->execute([$supplier_nm]); $row = $s->fetch();
        if ($row) $supplier_id = (int)$row['id'];
        else { $s = $pdo->prepare("INSERT INTO suppliers (name) VALUES (?)"); $s->execute([$supplier_nm]); $supplier_id = (int)$pdo->lastInsertId(); }
      }

      // Update header (currency/fx remain locked)
      $pdo->prepare("UPDATE purchase_orders SET po_number=?, supplier_id=?, order_date=? WHERE id=?")
          ->execute([$po_number ?: null, $supplier_id, $order_date, $id]);

      // capture old ingredients
      $oldI = $pdo->prepare("SELECT DISTINCT ingredient_id FROM purchase_order_lines WHERE purchase_order_id=?");
      $oldI->execute([$id]); $oldIds = array_map('intval', array_column($oldI->fetchAll(), 'ingredient_id'));

      // delete old inv txns + lines
      $pdo->prepare("
        DELETE it FROM inventory_txns it
        JOIN purchase_order_lines pol ON pol.id=it.source_id AND it.source_table='purchase_order_lines'
        WHERE pol.purchase_order_id=?
      ")->execute([$id]);
      $pdo->prepare("DELETE FROM purchase_order_lines WHERE purchase_order_id=?")->execute([$id]);

      // reinsert lines from form
        $ing_ids = $_POST['ing_id'] ?? [];
        $qtys    = $_POST['qty'] ?? [];
        $ucs     = $_POST['uc_native'] ?? [];
        $lns     = $_POST['lnote'] ?? [];
        
        $insL = $pdo->prepare("INSERT INTO purchase_order_lines (purchase_order_id, ingredient_id, qty, unit_cost_native, unit_cost_bwp, line_note)
                               VALUES (?,?,?,?,?,?)");
        $insT = $pdo->prepare("INSERT INTO inventory_txns
          (ingredient_id, txn_ts, txn_date, txn_type, qty, unit_kind, unit_cost_bwp, source_table, source_id, note, created_by)
          VALUES (?, NOW(), ?, 'purchase', ?, (SELECT unit_kind FROM ingredients WHERE id=?), ?, 'purchase_order_lines', ?, ?, ?)");
        
        $newIds = [];
        $incomplete = false;
        
        $rowsCount = max(count($ing_ids), count($qtys), count($ucs));
        for ($i = 0; $i < $rowsCount; $i++) {
          $ing_id = isset($ing_ids[$i]) ? (int)$ing_ids[$i] : 0;
          $qty    = isset($qtys[$i]) ? (float)$qtys[$i] : 0.0;
          $totalN = isset($ucs[$i]) ? (float)$ucs[$i] : 0.0;   // this is LINE total (native)
          $lnote  = isset($lns[$i]) ? trim($lns[$i]) : '';
        
          // If the user touched the row (ingredient chosen or qty/total filled), require both qty and total.
          $touched = ($ing_id > 0) || ($qty > 0) || ($totalN > 0) || ($lnote !== '');
          if ($touched && ($ing_id <= 0 || $qty <= 0 || $totalN <= 0)) {
            $incomplete = true;
            continue; // skip inserting this broken row
          }
        
          if ($ing_id && $qty > 0 && $totalN > 0) {
            $ucn = $totalN / $qty;            // per-unit native
            $ucb = $ucn * $fx;                // per-unit BWP via locked-in FX
        
            $insL->execute([$id, $ing_id, $qty, $ucn, $ucb, $lnote ?: null]);
            $pol_id = (int)$pdo->lastInsertId();
        
            $insT->execute([$ing_id, $order_date, $qty, $ing_id, $ucb, $pol_id, "PO #$id (edit)", (int)($_SESSION['user']['id'] ?? 0)]);
            $newIds[$ing_id] = true;
          }
        }
        
        if ($incomplete) {
          throw new RuntimeException('Some lines were incomplete (ingredient, qty, and line total are required). No partial save was done.');
        }

      // Recalc WAC for union(old,new)
      if (!function_exists('recalc_wac')) { require_once __DIR__ . '/../app/wac_tools.php'; }
      $all = array_unique(array_merge($oldIds, array_keys($newIds)));
      foreach ($all as $iid) recalc_wac($pdo, (int)$iid);

      return true;
    });

    header('Location: ' . url_for('/po/view?id='.$id));
    exit;
  }

  // No PO route matched → fall through so the rest of the app can handle it.
})();
