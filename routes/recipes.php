<?php
declare(strict_types=1);

/* ---------- Recipes list ---------- */
$router->get('/recipes', function () {
  require_login();
  global $pdo;

  // Pull recipes with a count; we’ll render version links per row
  $rs = $pdo->query("
    SELECT r.id AS rid, r.name, r.style, COUNT(rv.id) AS versions
    FROM recipes r
    LEFT JOIN recipe_versions rv ON rv.recipe_id = r.id
    GROUP BY r.id, r.name, r.style
    ORDER BY r.name
  ")->fetchAll();

  // Preload all versions so we can render links without N+1 queries
  $allv = $pdo->query("
    SELECT id, recipe_id, version_no
    FROM recipe_versions
    ORDER BY recipe_id, version_no DESC
  ")->fetchAll();
  $byRecipe = [];
  foreach ($allv as $v) { $byRecipe[(int)$v['recipe_id']][] = $v; }

  $list = table_open() . "<tr><th>Name</th><th>Style</th><th>Versions</th><th>Actions</th></tr>";
  foreach ($rs as $r) {
    $rid = (int)$r['rid'];
    $links = '—';
    if (!empty($byRecipe[$rid])) {
      $parts = [];
      foreach ($byRecipe[$rid] as $v) {
        $parts[] = "<a href='".url_for("/recipes/view?rv_id=".(int)$v['id'])."'>v".(int)$v['version_no']."</a>";
      }
      $links = implode(' &nbsp; ', $parts);
    }
    $newv = role_is('admin') ? "<a href='".url_for("/recipes/newversion?recipe_id=".$rid)."'>New version</a>" : "";
    $list .= "<tr>
      <td>".h($r['name'])."</td>
      <td>".h($r['style'])."</td>
      <td>{$links}</td>
      <td>{$newv}</td>
    </tr>";
  }
  $list .= "</table>";

  // Create Recipe + Version (now includes package-weight fields)
  $form = "";
  if (role_is('admin')) {
    $tok = csrf_token();
    $form = "<h2>Create Recipe + Version</h2>
      <form method='post' action='".url_for("/recipes/create")."' class='row'>
        ".csrf_field($tok)."
        <div class='col'><label>Recipe name <input name='name' required></label></div>
        <div class='col'><label>Style
          <select name='style'><option>gelato</option><option>sorbet</option><option>other</option></select></label></div>
        <div class='col'><label>Default yield (g) <input name='yield' type='number' step='0.001' value='1100'></label></div>
        <div class='col'><label>PAC <input name='pac' type='number' step='0.001'></label></div>
        <div class='col'><label>POD <input name='pod' type='number' step='0.001'></label></div>
        <div class='col'><label>Alt resolution <select name='alt'><option value='primary'>primary</option><option value='heaviest'>heaviest</option></select></label></div>
        <div class='col'><label>Quart est (g) <input name='quart_est_g' type='number' step='0.01'></label></div>
        <div class='col'><label>Pint est (g) <input name='pint_est_g' type='number' step='0.01'></label></div>
        <div class='col'><label>Single est (g) <input name='single_est_g' type='number' step='0.01'></label></div>
        <button>Create</button>
      </form>";
  }

  render('Recipes', "<h1>Recipes</h1>".$list.$form);
});

$router->post('/recipes/create', function () {
  require_admin();
  post_only();
  global $pdo;

  // Transaction: insert recipe, then version
  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare("INSERT INTO recipes (name, style, is_active) VALUES (?,?,1)");
    $st->execute([trim($_POST['name'] ?? ''), $_POST['style'] ?? 'gelato']);
    $rid = (int)$pdo->lastInsertId();

    $st = $pdo->prepare("
      INSERT INTO recipe_versions
        (recipe_id, version_no, default_yield_g, pac, pod,
         quart_est_g, pint_est_g, single_est_g,
         alt_resolution, created_by)
      VALUES (?,?,?,?,?,?,?,?,?,?)
    ");
    $st->execute([
      $rid,
      1,
      (float)($_POST['yield'] ?? 1100),
      $_POST['pac']  !== '' ? (float)$_POST['pac']  : null,
      $_POST['pod']  !== '' ? (float)$_POST['pod']  : null,
      $_POST['quart_est_g']  !== '' ? (float)$_POST['quart_est_g']  : null,
      $_POST['pint_est_g']   !== '' ? (float)$_POST['pint_est_g']   : null,
      $_POST['single_est_g'] !== '' ? (float)$_POST['single_est_g'] : null,
      $_POST['alt'] ?? 'primary',
      (int)($_SESSION['user']['id'] ?? 0),
    ]);
    $rvid = (int)$pdo->lastInsertId();
    $pdo->commit();

    header('Location: ' . url_for('/recipes/items?rv_id='.$rvid));
  } catch (Throwable $e) { $pdo->rollBack(); render('Recipes', "<p class='err'>".h($e->getMessage())."</p>"); }
});

/* ---------- New version ---------- */
$router->get('/recipes/newversion', function () {
  require_admin();
  global $pdo;
  $recipe_id = (int)($_GET['recipe_id'] ?? 0);
  if ($recipe_id<=0) { http_response_code(400); exit('Bad recipe id'); }

  $v = $pdo->prepare("SELECT COALESCE(MAX(version_no),0)+1 AS nextv FROM recipe_versions WHERE recipe_id=?");
  $v->execute([$recipe_id]); $nextv = (int)$v->fetch()['nextv'];
  $tok = csrf_token();

  $body = "<h1>New Version</h1>
  <form method='post' action='".url_for("/recipes/newversion/save")."'>
    ".csrf_field($tok)."
    <input type='hidden' name='recipe_id' value='".(int)$recipe_id."'>
    <label>Version # <input name='version_no' type='number' value='".(int)$nextv."'></label>
    <label>Default yield (g) <input name='yield' type='number' step='0.001' value='1100'></label>
    <label>PAC <input name='pac' type='number' step='0.001'></label>
    <label>POD <input name='pod' type='number' step='0.001'></label>
    <label>Alt resolution <select name='alt'><option value='primary'>primary</option><option value='heaviest'>heaviest</option></select></label>
    <fieldset><legend>Package weight estimates</legend>
      <label>Quart (g) <input name='quart_est_g' type='number' step='0.01'></label>
      <label>Pint (g)  <input name='pint_est_g' type='number' step='0.01'></label>
      <label>Single (g)<input name='single_est_g' type='number' step='0.01'></label>
    </fieldset>
    <button>Create version</button>
  </form>";
  render('New Version', $body);
});

$router->post('/recipes/newversion/save', function () {
  require_admin();
  post_only(); global $pdo;

  $st = $pdo->prepare("
    INSERT INTO recipe_versions
      (recipe_id, version_no, default_yield_g, pac, pod,
       quart_est_g, pint_est_g, single_est_g,
       alt_resolution, created_by)
    VALUES (?,?,?,?,?,?,?,?,?,?)
  ");
  $st->execute([
    (int)$_POST['recipe_id'],
    (int)$_POST['version_no'],
    (float)$_POST['yield'],
    $_POST['pac']  !== '' ? (float)$_POST['pac']  : null,
    $_POST['pod']  !== '' ? (float)$_POST['pod']  : null,
    $_POST['quart_est_g']  !== '' ? (float)$_POST['quart_est_g']  : null,
    $_POST['pint_est_g']   !== '' ? (float)$_POST['pint_est_g']   : null,
    $_POST['single_est_g'] !== '' ? (float)$_POST['single_est_g'] : null,
    $_POST['alt'] ?? 'primary',
    (int)($_SESSION['user']['id'] ?? 0),
  ]);
  $rvid = (int)$pdo->lastInsertId();
  header("Location: " . url_for("/recipes/items?rv_id=".$rvid));
});

/* ---------- Inline editor for package weights ---------- */
$router->post('/recipes/version/weights', function () {
  require_admin();
  post_only(); global $pdo;

  $rv_id = (int)($_POST['rv_id'] ?? 0);
  if ($rv_id<=0) { http_response_code(400); exit('Bad id'); }
  $pdo->prepare("
    UPDATE recipe_versions
    SET quart_est_g = ?, pint_est_g = ?, single_est_g = ?
    WHERE id = ?
  ")->execute([
    $_POST['quart_est_g']  !== '' ? (float)$_POST['quart_est_g']  : null,
    $_POST['pint_est_g']   !== '' ? (float)$_POST['pint_est_g']   : null,
    $_POST['single_est_g'] !== '' ? (float)$_POST['single_est_g'] : null,
    $rv_id
  ]);

  header('Location: ' . url_for('/recipes/items?rv_id='.$rv_id));
});

$router->get('/recipes/view', function () {
  require_login();
  global $pdo;

  $rv_id = (int)($_GET['rv_id'] ?? 0);
  if ($rv_id<=0) { http_response_code(400); exit('Bad recipe version id'); }

  // Version + recipe header
  $st = $pdo->prepare("
    SELECT rv.*, r.name AS recipe_name
    FROM recipe_versions rv
    JOIN recipes r ON r.id = rv.recipe_id
    WHERE rv.id=?
  ");
  $st->execute([$rv_id]); $v = $st->fetch();
  if (!$v) { http_response_code(404); render('Recipe','<p class="err">Not found</p>'); return; }

  // Ingredients (+ current WAC snapshot for costing)
  $it = $pdo->prepare("
    SELECT
      ri.choice_group  AS cg,
      ri.qty,
      ri.unit_kind,
      ri.is_primary,
      ri.step_notes,
      i.name           AS ingredient_name,
      COALESCE(w.wac_bwp,0) AS wac
    FROM recipe_items ri
    JOIN ingredients i     ON i.id = ri.ingredient_id
    LEFT JOIN v_current_wac w ON w.ingredient_id = i.id
    WHERE ri.recipe_version_id = ?
    ORDER BY (ri.choice_group=0) DESC, ri.choice_group, ri.sort_order, i.name
  ");
  $it->execute([$rv_id]); $rows = $it->fetchAll();

  // Steps
  $steps = $pdo->prepare("
    SELECT step_no, instruction
    FROM recipe_steps
    WHERE recipe_version_id=?
    ORDER BY step_no
  ");
  $steps->execute([$rv_id]); $S = $steps->fetchAll();

  // Cost total
  $total_cost = 0.0;
  foreach ($rows as $r) { $total_cost += (float)$r['qty'] * (float)$r['wac']; }

  // Header
  $head = "<div class='row' style='align-items:center;justify-content:space-between'>
    <div>
      <h1 style='margin:0'>".h($v['recipe_name'])." — v".(int)$v['version_no']."</h1>
      <p class='small' style='margin:.25rem 0 0'>
        Default yield: ".number_format((float)$v['default_yield_g'],3)." g
        • Alt: ".h($v['alt_resolution'])."
        • Est. weights (g): quart ".h((string)$v['quart_est_g']).", pint ".h((string)$v['pint_est_g']).", single ".h((string)$v['single_est_g'])."
      </p>
      <p class='small' style='margin:.25rem 0 0'><strong>Estimated total cost:</strong> ".number_format($total_cost,2)." BWP</p>
    </div>
    <div><a class='btn' href='".url_for("/recipes/items?rv_id=".$rv_id)."'>Edit this version</a></div>
  </div>";

  // Ingredients table (includes per-line extended cost and the per-ingredient step note)
  $tbl = "<h2>Ingredients</h2>"
    . table_open()
    . "<tr>
      <th>Group</th><th class='left'>Ingredient</th><th class='right'>Qty</th><th>Unit</th>
      <th>Primary</th><th class='right'>Cost (BWP)</th><th class='left'>Step notes</th>
    </tr>";
  foreach ($rows as $r) {
    $ext = (float)$r['qty'] * (float)$r['wac'];
    $tbl .= "<tr>
      <td class='center'>".(int)$r['cg']."</td>
      <td class='left'>".h($r['ingredient_name'])."</td>
      <td class='right'>".number_format((float)$r['qty'],3)."</td>
      <td>".h($r['unit_kind'])."</td>
      <td class='center'>".((int)$r['is_primary']?'✔':'')."</td>
      <td class='right'>".number_format($ext,4)."</td>
      <td class='left'>".h((string)$r['step_notes'])."</td>
    </tr>";
  }
  $tbl .= "<tr><td colspan='5' class='right'><strong>Total</strong></td>
              <td class='right'><strong>".number_format($total_cost,2)."</strong></td>
              <td></td></tr></table>";

  // Steps block
  $steps_html = "<h2>Steps</h2>";
  if ($S) {
    $steps_html .= "<ol>";
    foreach ($S as $s) { $steps_html .= "<li>".h($s['instruction'])."</li>"; }
    $steps_html .= "</ol>";
  } else {
    $steps_html .= "<p class='muted'>No steps added yet.</p>";
  }

  render('Recipe View', $head.$tbl.$steps_html);
});


/* ---------- Items page ---------- */
$router->get('/recipes/items', function() {
  require_login();
  global $pdo;

  $rv_id = (int)($_GET['rv_id'] ?? 0);
  if ($rv_id<=0) { http_response_code(400); exit('Bad recipe version id'); }

  $rv = $pdo->prepare("SELECT rv.*, r.name AS recipe_name FROM recipe_versions rv JOIN recipes r ON r.id=rv.recipe_id WHERE rv.id=?");
  $rv->execute([$rv_id]); $version = $rv->fetch();
  if (!$version) { http_response_code(404); exit('No such version'); }

  $items = $pdo->prepare("SELECT ri.*, i.name, COALESCE(w.wac_bwp,0) AS wac
                          FROM recipe_items ri
                          JOIN ingredients i ON i.id=ri.ingredient_id
                          LEFT JOIN v_current_wac w ON w.ingredient_id=i.id
                          WHERE ri.recipe_version_id=?
                          ORDER BY ri.choice_group, ri.sort_order, i.name");
  $items->execute([$rv_id]); $rows = $items->fetchAll();

    $tbl = table_open() . "<tr>
      <th>Group</th><th>Ingredient</th><th>Qty</th><th>Unit</th>
      <th>Primary</th><th>Cost (BWP)</th><th>Step notes</th><th>Actions</th>
    </tr>";
    
    $total_cost = 0.0;
    
    foreach ($rows as $r) {
      $tok  = csrf_token();
      $qty  = (float)$r['qty'];              // recipe quantity in canonical unit
      $per  = (float)$r['wac'];              // BWP per canonical unit
      $cost = $qty * $per;                   // item cost for this row
    
      // Include in total if not an alternate OR it's the primary choice
      if ((int)$r['choice_group'] === 0 || (int)$r['is_primary'] === 1) {
        $total_cost += $cost;
      }
    
      $tbl .= "<tr>
        <td>".(int)$r['choice_group']."</td>
        <td>".h($r['name'])."</td>
        <td>".number_format($qty,3)."</td>
        <td>".h($r['unit_kind'])."</td>
        <td>".((int)$r['is_primary'] ? '✔' : '')."</td>
        <td class='right' title='@ ".number_format($per,4)." per ".h($r['unit_kind'])."'>"
            .number_format($cost,2).
        "</td>
        <td>".h((string)$r['step_notes'])."</td>
        <td>
          <a href='".url_for("/recipes/items/edit?id=".(int)$r['id'])."'>Edit</a>
          <form method='post' action='".url_for("/recipes/items/delete")."' style='display:inline' onsubmit='return confirm(\"Remove this item?\")'>
            ".csrf_field($tok)."
            <input type='hidden' name='id' value='".(int)$r['id']."'>
            <input type='hidden' name='rv_id' value='".(int)$rv_id."'>
            <button class='link danger'>Remove</button>
          </form>
        </td>
      </tr>";
    }
    $tbl .= "</table>";

  $batch_btn = "<a class='btn' href='".url_for("/batches/new?rv_id=".(int)$rv_id)."'>Start batch</a>";

  $tokPkg  = csrf_token();
  $pkgForm = "";
  if (role_is('admin')) {
    $pkgForm = "
      <form method='post' action='".url_for("/recipes/version/weights")."' class='row' style='gap:.75rem;align-items:end;margin-top:.35rem'>
        ".csrf_field($tokPkg)."
        <input type='hidden' name='rv_id' value='".(int)$rv_id."'>
        <div class='col'><label>Quart est (g)
          <input name='quart_est_g' type='number' step='0.01' value='".h((string)$version['quart_est_g'])."'></label></div>
        <div class='col'><label>Pint est (g)
          <input name='pint_est_g' type='number' step='0.01' value='".h((string)$version['pint_est_g'])."'></label></div>
        <div class='col'><label>Single est (g)
          <input name='single_est_g' type='number' step='0.01' value='".h((string)$version['single_est_g'])."'></label></div>
        <div class='col'><button>Save</button></div>
      </form>";
  }

  $hdr = "<div class='row' style='align-items:center;justify-content:space-between'>
            <div>
              <h1 style='margin:0'>".h($version['recipe_name'])." — v".(int)$version['version_no']."</h1>
              <p class='small' style='margin-top:.25rem'>
                Default yield: ".number_format((float)$version['default_yield_g'],3)." g
                • Alt: ".h($version['alt_resolution'])."
              </p>
              <p class='small' style='margin:.25rem 0 0'>
                Package estimates (g):
                quart ".h((string)$version['quart_est_g'])." •
                pint ".h((string)$version['pint_est_g'])." •
                single ".h((string)$version['single_est_g'])."
              </p>
              ".($pkgForm)."
            </div>
            <div>{$batch_btn}</div>
          </div>";

  $ing = $pdo->query("SELECT id,name,unit_kind FROM ingredients WHERE is_active=1 ORDER BY name")->fetchAll();
  $opt = ""; foreach ($ing as $i) { $opt .= "<option value='".(int)$i['id']."'>".h($i['name'])." (".h($i['unit_kind']).")</option>"; }
  $tok = csrf_token();
  $form = "<h3>Add ingredient to version</h3>
    <form method='post' action='".url_for("/recipes/items/add")."' class='row'>
      ".csrf_field($tok)."
      <input type='hidden' name='rv_id' value='".(int)$rv_id."'>
      <div class='col'><label>Ingredient <select name='ingredient_id'>{$opt}</select></label></div>
      <div class='col'><label>Qty <input type='number' name='qty' step='0.001' required></label></div>
      <div class='col'><label>Unit
        <select name='unit_kind'><option>g</option><option>ml</option></select></label></div>
      <div class='col'><label>Choice group <input name='choice_group' type='number' value='0'></label></div>
      <div class='col'><label>Primary?
        <select name='is_primary'><option value='0'>No</option><option value='1'>Yes</option></select></label></div>
      <div class='col'><label>Step notes <input name='step_notes'></label></div>
      <button>Add item</button>
    </form>";

  // Steps (list + add + library + clone) — identical to your previous logic:
  $steps = $pdo->prepare("SELECT * FROM recipe_steps WHERE recipe_version_id=? ORDER BY step_no");
  $steps->execute([$rv_id]);
  $tokSteps = csrf_token();
  $steps_html = "<h3>Steps</h3><ol>";
  foreach ($steps as $s) {
    $steps_html .= "<li>"
      . h($s['instruction'])
      . " <form method='post' action='".url_for("/recipes/steps/move")."' style='display:inline'>
           ".csrf_field($tokSteps)."
           <input type='hidden' name='id' value='".(int)$s['id']."'>
           <input type='hidden' name='rv_id' value='".(int)$rv_id."'>
           <button class='link' name='dir' value='up'>&uarr;</button>
           <button class='link' name='dir' value='down'>&darr;</button>
          </form>
          <a href='".url_for("/recipes/steps/edit?id=".(int)$s['id'])."'>Edit</a>
          <form method='post' action='".url_for("/recipes/steps/delete")."' style='display:inline' onsubmit='return confirm(\"Delete this step?\")'>
            ".csrf_field($tokSteps)."
            <input type='hidden' name='id' value='".(int)$s['id']."'>
            <input type='hidden' name='rv_id' value='".(int)$rv_id."'>
            <button class='link danger'>Delete</button>
          </form>"
      . "</li>";
  }
  $steps_html .= "</ol>";

  $ns = $pdo->prepare("SELECT COALESCE(MAX(step_no),0)+1 FROM recipe_steps WHERE recipe_version_id=?");
  $ns->execute([$rv_id]);
  $next_no = (int)$ns->fetchColumn();

  $tok_step = csrf_token();
  $steps_form = "<h4>Add step</h4>
    <form method='post' action='".url_for("/recipes/steps/add")."' class='row'>
      ".csrf_field($tok_step)."
      <input type='hidden' name='rv_id' value='".(int)$rv_id."'>
      <div class='col'><label>Step # <input name='step_no' type='number' min='1' value='".(int)$next_no."'></label></div>
      <div class='col' style='flex:2'><label>Instruction <input name='instruction' required placeholder='e.g., Heat milk to 45°C; dissolve sugars; chill…'></label></div>
      <div class='col'><label><input type='checkbox' name='save_to_library' value='1'> Save to library</label></div>
      <button>Add step</button>
    </form>";

  $lib = $pdo->query("SELECT id,instruction FROM step_library ORDER BY usage_count DESC, id DESC LIMIT 200")->fetchAll();
  if ($lib) {
    $opts = "";
    foreach ($lib as $L) {
      $short = mb_strimwidth($L['instruction'], 0, 80, '…', 'UTF-8');
      $opts .= "<option value='".(int)$L['id']."'>".h($short)."</option>";
    }
    $tok_lib = csrf_token();
    $steps_form .= "<h4>Insert from library</h4>
      <form method='post' action='".url_for("/recipes/steps/add-from-library")."' class='row'>
        ".csrf_field($tok_lib)."
        <input type='hidden' name='rv_id' value='".(int)$rv_id."'>
        <div class='col'><label>Step # <input name='step_no' type='number' min='1' value='".(int)$next_no."'></label></div>
        <div class='col' style='flex:2'><label>Library <select name='library_id'>{$opts}</select></label></div>
        <button>Add</button>
      </form>";
  }

  $rv_opts = $pdo->query("
    SELECT rv.id AS rv_id, r.name, rv.version_no
    FROM recipe_versions rv
    JOIN recipes r ON r.id = rv.recipe_id
    WHERE rv.id <> ".(int)$rv_id."
    ORDER BY r.name, rv.version_no DESC
    LIMIT 100
  ")->fetchAll();

  if ($rv_opts) {
    $o = "";
    foreach ($rv_opts as $vrow) {
      $o .= "<option value='".(int)$vrow['rv_id']."'>".h($vrow['name'])." v".(int)$vrow['version_no']."</option>";
    }
    $tok_clone = csrf_token();
    $steps_form .= "<h4>Clone steps from another version</h4>
      <form method='post' action='".url_for("/recipes/steps/clone")."' class='row' onsubmit='return confirm(\"Append all steps from the selected version?\")'>
        ".csrf_field($tok_clone)."
        <input type='hidden' name='rv_id' value='".(int)$rv_id."'>
        <div class='col' style='flex:2'><label>Source version <select name='from_rv_id'>{$o}</select></label></div>
        <button>Clone steps</button>
      </form>";
  }

  $link_batch = "<p><a href='".url_for("/batches/new?rv_id=".(int)$rv_id)."'>Create batch from this version</a></p>";
  render('Recipe Items', $hdr.$tbl.$form.$steps_html.$steps_form.$link_batch);
});

/* ---------- Item CRUD ---------- */
$router->get('/recipes/items/edit', function() {
  require_admin();
  global $pdo;
  $id = (int)($_GET['id'] ?? 0);
  $st = $pdo->prepare('SELECT ri.*, i.name FROM recipe_items ri JOIN ingredients i ON i.id=ri.ingredient_id WHERE ri.id=?');
  $st->execute([$id]); $ri = $st->fetch();
  if (!$ri) { http_response_code(404); render('Edit item','<p class="err">Not found</p>'); return; }
  $tok = csrf_token();
  $body = "<h1>Edit item — ".h($ri['name'])."</h1>
  <form method='post' action='".url_for("/recipes/items/update")."'>
    ".csrf_field($tok)."
    <input type='hidden' name='id' value='".(int)$ri['id']."'>
    <input type='hidden' name='rv_id' value='".(int)$ri['recipe_version_id']."'>
    <label>Choice group <input type='number' name='choice_group' value='".(int)$ri['choice_group']."'></label>
    <label>Qty <input type='number' step='0.001' name='qty' value='".number_format((float)$ri['qty'],3,'.','')."'></label>
    <label>Unit
      <select name='unit_kind'><option ".($ri['unit_kind']=='g'?'selected':'').">g</option>
                               <option ".($ri['unit_kind']=='ml'?'selected':'').">ml</option></select></label>
    <label>Primary?
      <select name='is_primary'><option value='0' ".(!$ri['is_primary']?'selected':'').">No</option>
                                 <option value='1' ".($ri['is_primary']?'selected':'').">Yes</option></select></label>
    <label>Step notes <input name='step_notes' value='".h((string)$ri['step_notes'])."'></label>
    <button>Save</button>
  </form>";
  render('Edit item', $body);
});

$router->post('/recipes/items/update', function() {
  require_admin();
  post_only(); global $pdo;
  $rv_id = (int)($_POST['rv_id'] ?? 0);
  $stmt = $pdo->prepare("UPDATE recipe_items
    SET choice_group=?, qty=?, unit_kind=?, is_primary=?, step_notes=?
    WHERE id=?");
  $stmt->execute([
    (int)($_POST['choice_group'] ?? 0),
    (float)($_POST['qty'] ?? 0),
    $_POST['unit_kind'] ?? 'g',
    (int)($_POST['is_primary'] ?? 0),
    trim($_POST['step_notes'] ?? ''),
    (int)$_POST['id']
  ]);
  header("Location: " . url_for("/recipes/items?rv_id=".$rv_id));
  exit;
});

$router->post('/recipes/items/delete', function() {
  require_admin();
  post_only(); global $pdo;
  $rv_id = (int)($_POST['rv_id'] ?? 0);
  $id    = (int)($_POST['id'] ?? 0);
  $pdo->prepare("DELETE FROM recipe_items WHERE id=?")->execute([$id]);
  header("Location: " . url_for("/recipes/items?rv_id=".$rv_id));
  exit;
});

$router->post('/recipes/items/add', function() {
  require_admin();
  post_only(); global $pdo;

  $rv_id        = (int)($_POST['rv_id'] ?? 0);
  $ingredientId = (int)($_POST['ingredient_id'] ?? 0);
  $qty          = (float)($_POST['qty'] ?? 0);
  $unit         = ($_POST['unit_kind'] ?? 'g') === 'ml' ? 'ml' : 'g';
  $choiceGroup  = (int)($_POST['choice_group'] ?? 0);
  $isPrimary    = (int)($_POST['is_primary'] ?? 0) ? 1 : 0;
  $stepNotes    = trim($_POST['step_notes'] ?? '');

  if ($rv_id <= 0) { http_response_code(400); render('Add item error', '<p class="err">Bad recipe version id.</p>'); return; }
  if ($ingredientId <= 0) { render('Add item error', '<p class="err">Please choose an ingredient.</p>'); return; }
  if ($qty <= 0) { render('Add item error', '<p class="err">Quantity must be greater than zero.</p>'); return; }

  try {
    $chk = $pdo->prepare('SELECT 1 FROM recipe_versions WHERE id=?');
    $chk->execute([$rv_id]);
    if (!$chk->fetchColumn()) { render('Add item error','<p class="err">Recipe version not found.</p>'); return; }

    $ci = $pdo->prepare('SELECT unit_kind FROM ingredients WHERE id=? AND is_active=1');
    $ci->execute([$ingredientId]);
    $ingUnit = $ci->fetchColumn();
    if ($ingUnit === false) { render('Add item error','<p class="err">Ingredient not found or inactive.</p>'); return; }

    $st = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM recipe_items WHERE recipe_version_id=?');
    $st->execute([$rv_id]);
    $sort = (int)($st->fetchColumn() ?: 1);

    $ins = $pdo->prepare('
      INSERT INTO recipe_items
        (recipe_version_id, choice_group, ingredient_id, qty, unit_kind, is_primary, step_notes, sort_order)
      VALUES
        (?,?,?,?,?,?,?,?)
    ');
    $ins->execute([$rv_id, $choiceGroup, $ingredientId, $qty, $unit, $isPrimary, $stepNotes !== '' ? $stepNotes : null, $sort]);

    header('Location: ' . url_for('/recipes/items?rv_id='.$rv_id));
    exit;

  } catch (Throwable $e) {
    render('Add item error', "<p class='err'>".h($e->getMessage())."</p><p><a href='".url_for("/recipes/items?rv_id=".$rv_id)."'>Back</a></p>");
  }
});

/* ---------- Steps ---------- */
$router->post('/recipes/steps/add', function() {
  require_admin();
  post_only(); global $pdo;
  $rv_id  = (int)($_POST['rv_id'] ?? 0);
  $instr  = trim($_POST['instruction'] ?? '');
  $stepNo = ($_POST['step_no'] ?? '') === '' ? null : (int)$_POST['step_no'];
  $saveLib = !empty($_POST['save_to_library']);

  if ($rv_id <= 0 || $instr === '') { render('Add step error','<p class="err">Version and instruction are required.</p>'); return; }

  try {
    if ($stepNo === null || $stepNo < 1) {
      $st = $pdo->prepare('SELECT COALESCE(MAX(step_no),0)+1 FROM recipe_steps WHERE recipe_version_id=?');
      $st->execute([$rv_id]);
      $stepNo = (int)$st->fetchColumn();
    }

    $pdo->beginTransaction();
    $ins = $pdo->prepare('INSERT INTO recipe_steps (recipe_version_id, step_no, instruction) VALUES (?,?,?)');
    $ins->execute([$rv_id, $stepNo, $instr]);

    if ($saveLib) {
      $up = $pdo->prepare('INSERT INTO step_library (instruction) VALUES (?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');
      $up->execute([$instr]);
      $lib_id = (int)$pdo->lastInsertId();
      $pdo->prepare('UPDATE step_library SET usage_count = usage_count + 1 WHERE id=?')->execute([$lib_id]);
    }

    $pdo->commit();
    header('Location: ' . url_for('/recipes/items?rv_id='.$rv_id)); exit;

  } catch (Throwable $e) { $pdo->rollBack(); render('Add step error', "<p class='err'>".h($e->getMessage())."</p>"); }
});

$router->post('/recipes/steps/add-from-library', function() {
  require_admin();
  post_only(); global $pdo;
  $rv_id = (int)($_POST['rv_id'] ?? 0);
  $libId = (int)($_POST['library_id'] ?? 0);
  $stepNo = ($_POST['step_no'] ?? '') === '' ? null : (int)$_POST['step_no'];
  if ($rv_id <= 0 || $libId <= 0) { render('Add step error','<p class="err">Version and library step are required.</p>'); return; }

  try {
    $instr = $pdo->prepare('SELECT instruction FROM step_library WHERE id=?');
    $instr->execute([$libId]);
    $text = $instr->fetchColumn();
    if ($text === false) { render('Add step error','<p class="err">Library step not found.</p>'); return; }

    if ($stepNo === null || $stepNo < 1) {
      $st = $pdo->prepare('SELECT COALESCE(MAX(step_no),0)+1 FROM recipe_steps WHERE recipe_version_id=?');
      $st->execute([$rv_id]);
      $stepNo = (int)$st->fetchColumn();
    }

    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO recipe_steps (recipe_version_id, step_no, instruction) VALUES (?,?,?)')->execute([$rv_id, $stepNo, $text]);
    $pdo->prepare('UPDATE step_library SET usage_count = usage_count + 1 WHERE id=?')->execute([$libId]);
    $pdo->commit();

    header('Location: ' . url_for('/recipes/items?rv_id='.$rv_id)); exit;

  } catch (Throwable $e) { $pdo->rollBack(); render('Add step error', "<p class='err'>".h($e->getMessage())."</p>"); }
});

$router->get('/recipes/steps/edit', function() {
  require_admin();
  global $pdo;
  $id = (int)($_GET['id'] ?? 0);
  $st = $pdo->prepare('SELECT * FROM recipe_steps WHERE id=?');
  $st->execute([$id]); $row = $st->fetch();
  if (!$row) { render('Edit step','<p class="err">Not found.</p>'); return; }
  $tok = csrf_token();
  $body = "<h1>Edit step</h1>
    <form method='post' action='".url_for("/recipes/steps/update")."'>
      ".csrf_field($tok)."
      <input type='hidden' name='id' value='".(int)$row['id']."'>
      <input type='hidden' name='rv_id' value='".(int)$row['recipe_version_id']."'>
      <label>Step # <input name='step_no' type='number' min='1' value='".(int)$row['step_no']."'></label>
      <label>Instruction <input name='instruction' value='".h($row['instruction'])."'></label>
      <label><input type='checkbox' name='save_to_library' value='1'> Save to library</label>
      <button>Save</button>
    </form>";
  render('Edit step', $body);
});

$router->post('/recipes/steps/update', function() {
  require_admin();
  post_only(); global $pdo;

  $id = (int)($_POST['id'] ?? 0);
  $rv_id = (int)($_POST['rv_id'] ?? 0);
  $stepNo = max(1, (int)($_POST['step_no'] ?? 1));
  $instr  = trim($_POST['instruction'] ?? '');
  $saveLib = !empty($_POST['save_to_library']);

  if ($id<=0 || $rv_id<=0 || $instr==='') { render('Edit step','<p class="err">All fields required.</p>'); return; }

  try {
    $pdo->beginTransaction();

    $cur = $pdo->prepare('SELECT step_no FROM recipe_steps WHERE id=? FOR UPDATE');
    $cur->execute([$id]); $curNo = (int)$cur->fetchColumn();

    if ($curNo !== $stepNo) {
      $tmp = -1;
      $pdo->prepare('UPDATE recipe_steps SET step_no=? WHERE recipe_version_id=? AND step_no=?')
          ->execute([$tmp, $rv_id, $stepNo]);
      $pdo->prepare('UPDATE recipe_steps SET step_no=? WHERE id=?')->execute([$stepNo, $id]);
      $pdo->prepare('UPDATE recipe_steps SET step_no=? WHERE recipe_version_id=? AND step_no=?')
          ->execute([$curNo, $rv_id, $tmp]);
    }

    $pdo->prepare('UPDATE recipe_steps SET instruction=? WHERE id=?')->execute([$instr, $id]);

    if ($saveLib) {
      $up = $pdo->prepare('INSERT INTO step_library (instruction) VALUES (?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');
      $up->execute([$instr]);
      $lib_id = (int)$pdo->lastInsertId();
      $pdo->prepare('UPDATE step_library SET usage_count = usage_count + 1 WHERE id=?')->execute([$lib_id]);
    }

    $pdo->commit();
    header('Location: ' . url_for('/recipes/items?rv_id='.$rv_id)); exit;

  } catch (Throwable $e) { $pdo->rollBack(); render('Edit step', "<p class='err'>".h($e->getMessage())."</p>"); }
});

$router->post('/recipes/steps/delete', function() {
  require_admin();
  post_only(); global $pdo;
  $id = (int)($_POST['id'] ?? 0);
  $rv_id = (int)($_POST['rv_id'] ?? 0);
  if ($id<=0 || $rv_id<=0) { render('Delete step','<p class="err">Bad request.</p>'); return; }

  try {
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM recipe_steps WHERE id=?')->execute([$id]);
    $pdo->prepare('SET @n := 0')->execute();
    $pdo->prepare('UPDATE recipe_steps SET step_no = (@n := @n + 1) WHERE recipe_version_id=? ORDER BY step_no, id')
        ->execute([$rv_id]);
    $pdo->commit();
    header('Location: ' . url_for('/recipes/items?rv_id='.$rv_id)); exit;

  } catch (Throwable $e) { $pdo->rollBack(); render('Delete step', "<p class='err'>".h($e->getMessage())."</p>"); }
});

$router->post('/recipes/steps/move', function() {
  require_admin();
  post_only(); global $pdo;
  $id = (int)($_POST['id'] ?? 0);
  $rv_id = (int)($_POST['rv_id'] ?? 0);
  $dir = ($_POST['dir'] ?? '') === 'up' ? 'up' : 'down';
  if ($id<=0 || $rv_id<=0) { render('Move step','<p class="err">Bad request.</p>'); return; }

  try {
    $pdo->beginTransaction();

    $cur = $pdo->prepare('SELECT step_no FROM recipe_steps WHERE id=? FOR UPDATE');
    $cur->execute([$id]); $curNo = (int)$cur->fetchColumn();

    if ($dir === 'up') {
      $neighbor = $pdo->prepare('SELECT id, step_no FROM recipe_steps WHERE recipe_version_id=? AND step_no < ? ORDER BY step_no DESC LIMIT 1 FOR UPDATE');
      $neighbor->execute([$rv_id, $curNo]);
    } else {
      $neighbor = $pdo->prepare('SELECT id, step_no FROM recipe_steps WHERE recipe_version_id=? AND step_no > ? ORDER BY step_no ASC LIMIT 1 FOR UPDATE');
      $neighbor->execute([$rv_id, $curNo]);
    }
    $nb = $neighbor->fetch();
    if ($nb) {
      $nid = (int)$nb['id'];
      $nNo = (int)$nb['step_no'];
      $tmp = -1;
      $pdo->prepare('UPDATE recipe_steps SET step_no=? WHERE id=?')->execute([$tmp, $id]);
      $pdo->prepare('UPDATE recipe_steps SET step_no=? WHERE id=?')->execute([$curNo, $nid]);
      $pdo->prepare('UPDATE recipe_steps SET step_no=? WHERE id=?')->execute([$nNo, $id]);
    }

    $pdo->commit();
    header('Location: ' . url_for('/recipes/items?rv_id='.$rv_id)); exit;

  } catch (Throwable $e) { $pdo->rollBack(); render('Move step', "<p class='err'>".h($e->getMessage())."</p>"); }
});

$router->post('/recipes/steps/clone', function() {
  require_admin();
  post_only(); global $pdo;
  $rv_id = (int)($_POST['rv_id'] ?? 0);
  $from  = (int)$_POST['from_rv_id'];
  if ($rv_id<=0 || $from<=0 || $rv_id===$from) { render('Clone steps','<p class="err">Bad source/target.</p>'); return; }

  try {
    $pdo->beginTransaction();
    $st = $pdo->prepare('SELECT instruction FROM recipe_steps WHERE recipe_version_id=? ORDER BY step_no');
    $st->execute([$from]);
    $src = $st->fetchAll();

    $nx = $pdo->prepare('SELECT COALESCE(MAX(step_no),0)+1 FROM recipe_steps WHERE recipe_version_id=?');
    $nx->execute([$rv_id]);
    $n = (int)$nx->fetchColumn();

    $ins = $pdo->prepare('INSERT INTO recipe_steps (recipe_version_id, step_no, instruction) VALUES (?,?,?)');
    foreach ($src as $row) $ins->execute([$rv_id, $n++, $row['instruction']]);

    $pdo->commit();
    header('Location: ' . url_for('/recipes/items?rv_id='.$rv_id)); exit;

  } catch (Throwable $e) { $pdo->rollBack(); render('Clone steps', "<p class='err'>".h($e->getMessage())."</p>"); }
});
