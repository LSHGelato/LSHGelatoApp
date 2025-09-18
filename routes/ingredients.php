<?php
declare(strict_types=1);

$router->get('/ingredients', function() {
  require_login();
  global $pdo;

  $perPage = 50;
  $page = max(1, (int)($_GET['page'] ?? 1));

  $total = (int)$pdo->query("SELECT COUNT(*) FROM v_ingredients_snapshot")->fetchColumn();

  if ($total === 0) {
    $totalPages = 1;
    $page = 1;
  } else {
    $totalPages = (int)ceil($total / $perPage);
    if ($page > $totalPages) { $page = $totalPages; }
  }

  $offset = ($page - 1) * $perPage;

  $stmt = $pdo->query("SELECT * FROM v_ingredients_snapshot ORDER BY name LIMIT {$perPage} OFFSET {$offset}");
  $rows = $stmt->fetchAll();

  $navLinks = [];
  if ($page > 1) {
    $navLinks[] = "<a href='".url_for("/ingredients?page=".($page - 1))."'>Prev</a>";
  }
  if ($page < $totalPages) {
    $navLinks[] = "<a href='".url_for("/ingredients?page=".($page + 1))."'>Next</a>";
  }
  $nav = $navLinks ? "<p class='pager'>".implode(' | ', $navLinks)."</p>" : '';

  $list = table_open() . "<tr><th>Name</th><th>Unit</th><th>On hand</th><th>WAC (BWP/u)</th><th>Reorder</th>";
  if (role_is('admin')) { $list .= "<th>Actions</th>"; }
  $list .= "</tr>";

  foreach ($rows as $r) {
    $actions = role_is('admin')
      ? "<a href='".url_for("/ingredients/edit?id=".(int)$r['id'])."'>Edit</a>"
        . " &nbsp;|&nbsp; "
        . "<a href='".url_for("/inventory/adjust?id=".(int)$r['id'])."'>Adjust</a>"
      : '';
    $list .= "<tr>"
           . "<td>".h($r['name'])."</td>"
           . "<td>".h($r['unit_kind'])."</td>"
           . "<td>".number_format((float)$r['qty_on_hand'],3)."</td>"
           . "<td>".number_format((float)$r['wac_bwp'],2)."</td>"
           . "<td>".number_format((float)$r['reorder_point'],3)."</td>"
           . (role_is('admin') ? "<td>{$actions}</td>" : "")
           . "</tr>";
  }
  $list .= "</table>";

  $form = "";
  if (role_is('admin')) {
    $tok = csrf_token();
    $form = "<h2>Add Ingredient</h2>
      <form method='post' action='".url_for("/ingredients/create")."' class='row'>
        ".csrf_field($tok)."
        <div class='col'><label>Name <input name='name' required></label></div>
        <div class='col'><label>Category <input name='category'></label></div>
        <div class='col'><label>Unit
            <select name='unit_kind'><option value='g'>g</option><option value='ml'>ml</option></select>
        </label></div>
        <div class='col'><label>Reorder point <input name='reorder_point' type='number' step='0.001' value='0'></label></div>
        <div class='col'><label>Solids % <input name='solids_pct' type='number' step='0.001'></label></div>
        <div class='col'><label>Fat % <input name='fat_pct' type='number' step='0.001'></label></div>
        <div class='col'><label>Sugar % <input name='sugar_pct' type='number' step='0.001'></label></div>
        <button>Add</button>
      </form>";
  }

  render('Ingredients', "<h1>Ingredients</h1>{$nav}{$list}{$nav}{$form}");
});

$router->get('/ingredients/edit', function() {
  require_admin();
  global $pdo;

  $id = (int)($_GET['id'] ?? 0);
  $st = $pdo->prepare("SELECT * FROM ingredients WHERE id=?");
  $st->execute([$id]); $ing = $st->fetch();
  if (!$ing) { http_response_code(404); render('Edit Ingredient', '<p class="err">Not found</p>'); return; }

  // Current on-hand (from snapshot view; fall back to 0)
  $q = $pdo->prepare("SELECT COALESCE(qty_on_hand,0) FROM v_ingredients_snapshot WHERE id=?");
  $q->execute([$id]); $on_hand = (float)($q->fetchColumn() ?? 0);

  $tok = csrf_token();

  // Quick inventory panel (links to Adjust + History)
  $quick = "<aside style='margin:1rem 0;padding:.75rem;border:1px solid #ddd;border-radius:8px'>
              <p class='small muted' style='margin:.25rem 0'>Quick inventory</p>
              <p>On hand now: <strong>".number_format($on_hand,3)."</strong> ".h($ing['unit_kind'])."</p>
              <p>
                <a class='btn' href='".url_for("/inventory/adjust?id=".$id)."'>Adjust on hand</a>
                &nbsp;
                <a class='btn' href='".url_for("/inventory/history?id=".$id)."'>View history</a>
              </p>
            </aside>";

  $body = "<h1>Edit ingredient — ".h($ing['name'])."</h1>
  {$quick}
  <form method='post' action='".url_for("/ingredients/update")."'>
    ".csrf_field($tok)."
    <input type='hidden' name='id' value='".(int)$ing['id']."'>
    <label>Name <input name='name' value='".h($ing['name'])."'></label>
    <label>Category <input name='category' value='".h((string)$ing['category'])."'></label>
    <label>Unit
      <select name='unit_kind'>
        <option ".($ing['unit_kind']=='g'?'selected':'').">g</option>
        <option ".($ing['unit_kind']=='ml'?'selected':'').">ml</option>
      </select>
    </label>
    <label>Reorder point <input type='number' step='0.001' name='reorder_point' value='".h((string)$ing['reorder_point'])."'></label>
    <label>Solids % <input type='number' step='0.001' name='solids_pct' value='".h((string)$ing['solids_pct'])."'></label>
    <label>Fat % <input type='number' step='0.001' name='fat_pct' value='".h((string)$ing['fat_pct'])."'></label>
    <label>Sugar % <input type='number' step='0.001' name='sugar_pct' value='".h((string)$ing['sugar_pct'])."'></label>
    <label>Active?
      <select name='is_active'>
        <option value='1' ".($ing['is_active']?'selected':'').">Yes</option>
        <option value='0' ".(!$ing['is_active']?'selected':'').">No</option>
      </select>
    </label>
    <button>Save changes</button>
  </form>
  <p><a class='danger' href='".url_for("/ingredients/delete?id=".(int)$ing['id'])."' onclick=\"return confirm('Deactivate this ingredient?');\">Deactivate</a></p>";

  render('Edit Ingredient', $body);
});

$router->post('/ingredients/update', function() {
  require_admin();
  post_only(); global $pdo;
  $stmt = $pdo->prepare("UPDATE ingredients
    SET name=?, category=?, unit_kind=?, reorder_point=?, solids_pct=?, fat_pct=?, sugar_pct=?, is_active=?
    WHERE id=?");
  $stmt->execute([
    trim($_POST['name'] ?? ''), trim($_POST['category'] ?? ''), $_POST['unit_kind'] ?? 'g',
    $_POST['reorder_point'] !== '' ? (float)$_POST['reorder_point'] : 0,
    $_POST['solids_pct'] !== '' ? (float)$_POST['solids_pct'] : null,
    $_POST['fat_pct']   !== '' ? (float)$_POST['fat_pct']   : null,
    $_POST['sugar_pct'] !== '' ? (float)$_POST['sugar_pct'] : null,
    (int)($_POST['is_active'] ?? 1),
    (int)$_POST['id'],
  ]);
  header('Location: ' . url_for('/ingredients'));
});

$router->get('/ingredients/delete', function() {
  require_admin();
  global $pdo;
  $id = (int)($_GET['id'] ?? 0);
  $pdo->prepare("UPDATE ingredients SET is_active=0 WHERE id=?")->execute([$id]);
  header('Location: ' . url_for('/ingredients'));
});

$router->post('/ingredients/create', function() {
  require_admin();
  post_only(); global $pdo;
  $stmt = $pdo->prepare("INSERT INTO ingredients (name, category, unit_kind, reorder_point, solids_pct, fat_pct, sugar_pct, is_active) VALUES (?,?,?,?,?,?,?,1)");
  $stmt->execute([
    trim($_POST['name'] ?? ''), trim($_POST['category'] ?? ''), $_POST['unit_kind'] ?? 'g',
    (float)($_POST['reorder_point'] ?? 0),
    $_POST['solids_pct'] !== '' ? (float)$_POST['solids_pct'] : null,
    $_POST['fat_pct']   !== '' ? (float)$_POST['fat_pct']   : null,
    $_POST['sugar_pct'] !== '' ? (float)$_POST['sugar_pct'] : null
  ]);
  header('Location: ' . url_for('/ingredients'));
});
