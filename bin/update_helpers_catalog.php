#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Rebuild docs/helpers.md from app/ui.php function signatures.
 * - Extracts function names & parameter lists via token_get_all
 * - Uses first line of a docblock (if present) as the description
 */

$ui = __DIR__ . '/../app/ui.php';
$out = __DIR__ . '/../docs/helpers.md';

$code = file_get_contents($ui);
if ($code === false) { fwrite(STDERR, "Cannot read $ui\n"); exit(1); }

$T = token_get_all($code);
$funcs = []; // [ ['name'=>..., 'params'=>..., 'desc'=>...], ... ]
$doc = null;

for ($i=0, $n=count($T); $i<$n; $i++) {
  $t = $T[$i];
  if (is_array($t) && $t[0] === T_DOC_COMMENT) {
    $doc = $t[1];
    continue;
  }
  if (is_array($t) && $t[0] === T_FUNCTION) {
    // next non-whitespace token should be function name (T_STRING)
    $j = $i+1;
    while ($j < $n && is_array($T[$j]) && $T[$j][0] === T_WHITESPACE) $j++;
    if ($j >= $n || !is_array($T[$j]) || $T[$j][0] !== T_STRING) { $doc = null; continue; }
    $name = $T[$j][1];

    // grab parameter list tokens until matching ')'
    while ($j < $n && (!is_string($T[$j]) || $T[$j] !== '(')) $j++;
    if ($j >= $n) { $doc = null; continue; }

    $params = '(';
    $depth = 1;
    $j++;
    for (; $j < $n; $j++) {
      $tt = $T[$j];
      $params .= is_array($tt) ? $tt[1] : $tt;
      if ($tt === '(') $depth++;
      if ($tt === ')') { $depth--; if ($depth===0) break; }
    }
    // normalize whitespace
    $params = preg_replace('/\s+/', ' ', $params);
    $params = preg_replace('/\s*,\s*/', ', ', $params);
    $params = trim($params);

    // description: first non-empty docblock line (without asterisks) if present
    $desc = '';
    if ($doc) {
      $lines = preg_split('/\R/', $doc);
      foreach ($lines as $ln) {
        $ln = trim($ln, "/* \t\r\n");
        if ($ln !== '' && strpos($ln, '@') !== 0) { $desc = $ln; break; }
      }
    }
    $funcs[] = ['name'=>$name, 'params'=>$params, 'desc'=>$desc];
    $doc = null;
  }
}

// Known buckets (rough grouping by name)
$groups = [
  'Navigation & Rendering' => ['pretty_urls','url_for','render','h'],
  'Auth & Roles'           => ['require_login','require_admin','role_is'],
  'Request / CSRF / Flow'  => ['post_only','current_path'],
  'DB Transactions'        => ['db_tx'],
  'Table Helpers (HTML)'   => ['table_open','table_row','table_close'],
];

// Build markdown
$md  = "# UI Helpers Catalog\n\n";
$md .= "Authoritative list of global helpers available to routes and Codex prompts.\n";
$md .= "If you add or change a helper, update this file (or run the generator in `bin/update_helpers_catalog.php`).\n\n";

$byName = [];
foreach ($funcs as $f) $byName[$f['name']] = $f;

$seen = [];

foreach ($groups as $title => $names) {
  $md .= "## $title\n\n";
  foreach ($names as $nm) {
    if (!isset($byName[$nm])) continue;
    $f = $byName[$nm];
    $sig = $f['name'].$f['params'];
    $desc = $f['desc'] !== '' ? $f['desc'] : '';
    $md .= "- `{$sig}`  \n";
    if ($desc !== '') $md .= "  {$desc}\n";
    $md .= "\n";
    $seen[$nm] = true;
  }
}

// Any other top-level functions from app/ui.php (catch-all)
$rest = array_diff(array_keys($byName), array_keys($seen));
if ($rest) {
  $md .= "## Other Helpers\n\n";
  sort($rest);
  foreach ($rest as $nm) {
    $f = $byName[$nm];
    $sig = $f['name'].$f['params'];
    $desc = $f['desc'] !== '' ? $f['desc'] : '';
    $md .= "- `{$sig}`  \n";
    if ($desc !== '') $md .= "  {$desc}\n";
    $md .= "\n";
  }
}

// Contract block for Codex & PRs
$md .= "> **Contract for Codex/PRs**  \n";
$md .= "> Use only the helpers listed above. If a new helper is needed, add it to `app/ui.php`, then update this file (or run the generator) in the same PR.\n";

if (!is_dir(dirname($out))) mkdir(dirname($out), 0775, true);
file_put_contents($out, $md);

echo "Updated $out with ".count($funcs)." helpers from app/ui.php\n";
