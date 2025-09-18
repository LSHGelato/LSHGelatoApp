<?php
$router->get('/admin/fx', function() {
  require_login(); if (!role_is('admin')) { http_response_code(403); exit('Forbidden'); }

  global $pdo;
  $rows = $pdo->query("
    SELECT rate_date, currency, rate_to_bwp
    FROM exchange_rates
    ORDER BY rate_date DESC, currency
    LIMIT 200
  ")->fetchAll();

  $tbl = "<table><tr><th>Date</th><th>Currency</th><th>Rate → BWP</th></tr>";
  foreach ($rows as $r) {
    $tbl .= "<tr><td>".h($r['rate_date'])."</td><td>".h($r['currency'])."</td>"
          . "<td class='right'>".number_format((float)$r['rate_to_bwp'],6)."</td></tr>";
  }
  $tbl .= "</table>";

  $body = "<h1>Exchange rates</h1>
    <p><a href='".url_for("/admin/fx/export")."'>Export CSV</a></p>
    {$tbl}

    <h3>Add or update a single rate</h3>
    <form method='post' action='".h(url_for('/admin/fx/save'))."' class='row'>
      ".csrf_field()."
      <div class='col'><label>Date <input type='date' name='rate_date' required></label></div>
      <div class='col'><label>Currency
        <select name='currency' required>
          <option>USD</option><option>BWP</option><option>ZAR</option>
        </select></label></div>
      <div class='col'><label>Rate → BWP <input type='number' step='0.000001' name='rate_to_bwp' required></label></div>
      <button>Save</button>
    </form>

    <h3>Bulk paste (CSV from Google Sheets)</h3>
    <p class='small'>Columns: <code>rate_date, currency, rate_to_bwp</code>. Example:<br>
       <code>2024-06-01, USD, 13.497500</code></p>
    <form method='post' action='".h(url_for('/admin/fx/bulk'))."'>
      ".csrf_field()."
      <textarea name='csv' rows='10' style='width:100%;font-family:monospace' placeholder='YYYY-MM-DD, USD, 13.4975'></textarea>
      <p><button>Import</button></p>
    </form>";
  render('FX Admin', $body);
});

$router->post('/admin/fx/save', function() {
  require_login(); if (!role_is('admin')) { http_response_code(403); exit('Forbidden'); }
  post_only();

  global $pdo;
  $d = trim($_POST['rate_date'] ?? '');
  $c = strtoupper(trim($_POST['currency'] ?? ''));
  $r = (float)($_POST['rate_to_bwp'] ?? 0);

  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !in_array($c, ['USD','BWP','ZAR'], true) || $r <= 0) {
    render('FX Admin', "<p class='err'>Bad input.</p><p><a href='".url_for("/admin/fx")."'>Back</a></p>"); return;
  }
  if ($c === 'BWP') { $r = 1.0; }
  $st = $pdo->prepare("
    INSERT INTO exchange_rates (rate_date, currency, rate_to_bwp)
    VALUES (?,?,?)
    ON DUPLICATE KEY UPDATE rate_to_bwp = VALUES(rate_to_bwp)
  ");
  $st->execute([$d, $c, $r]);
  header('Location: ' . url_for('/admin/fx')); exit;
});

$router->post('/admin/fx/bulk', function() {
  require_login(); if (!role_is('admin')) { http_response_code(403); exit('Forbidden'); }
  post_only();

  global $pdo;
  $csv = $_POST['csv'] ?? '';
  $lines = preg_split('/\R/', $csv);
  $ok=0; $bad=0;

  $ins = $pdo->prepare("
    INSERT INTO exchange_rates (rate_date, currency, rate_to_bwp)
    VALUES (?,?,?)
    ON DUPLICATE KEY UPDATE rate_to_bwp = VALUES(rate_to_bwp)
  ");

  foreach ($lines as $ln) {
    $ln = trim($ln);
    if ($ln==='' || $ln[0]==='#') continue;
    $parts = array_map('trim', str_getcsv($ln));
    if (count($parts) < 3) { $bad++; continue; }
    [$d,$c,$r] = $parts;
    $c = strtoupper($c); $r = (float)$r;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$d) || !in_array($c,['USD','BWP','ZAR'], true) || $r<=0) { $bad++; continue; }
    if ($c === 'BWP') { $r = 1.0; }
    try { $ins->execute([$d,$c,$r]); $ok++; } catch(Throwable $e){ $bad++; }
  }
  render('FX Admin', "<p class='ok'>Imported {$ok} row(s). Skipped {$bad}.</p><p><a href='".url_for("/admin/fx")."'>Back</a></p>");
});

$router->get('/admin/fx/export', function() {
  require_login(); if (!role_is('admin')) { http_response_code(403); exit('Forbidden'); }
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="exchange_rates.csv"');
  global $pdo;
  echo "rate_date,currency,rate_to_bwp\n";
  $st = $pdo->query("
    SELECT rate_date, currency, rate_to_bwp
    FROM exchange_rates
    ORDER BY rate_date, currency
  ");
  foreach ($st as $row) {
    echo "{$row['rate_date']},".strtoupper($row['currency']).",".(float)$row['rate_to_bwp']."\n";
  }
  exit;
});
