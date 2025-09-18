<?php
// enrichment_selftest.php — drop in web root and open in browser.
// Confirms app/enrichment.php is readable & function exists.

header('Content-Type: text/plain; charset=utf-8');
$path = __DIR__ . '/app/enrichment.php';
echo "Self-test for enrichment module\n";
echo "Looking for: $path\n";
if (!is_file($path)) { echo "NOT FOUND\n"; exit; }
if (!is_readable($path)) { echo "File exists but not readable (check permissions 0644)\n"; exit; }
echo "File exists and is readable.\n";
try {
  require_once $path;
  echo "Included enrichment.php.\n";
  echo "Function enrich_one exists? " . (function_exists('enrich_one') ? "YES" : "NO") . "\n";
} catch (Throwable $e) {
  echo "Include threw: " . $e->getMessage() . "\n";
  exit;
}
echo "Done.\n";
