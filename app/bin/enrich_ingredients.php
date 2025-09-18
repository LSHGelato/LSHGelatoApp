<?php
declare(strict_types=1);
require_once __DIR__ . '/../enrichment.php';

$uid = 1; // system/audit user id; adjust if desired

if (isset($argv[1])) {
  $id = (int)$argv[1];
  try {
    $res = enrich_one($pdo, $id, $uid, true, 80);
    echo "OK ingredient #$id ".json_encode($res).PHP_EOL;
  } catch (Throwable $e) {
    fwrite(STDERR, "ERR #$id ".$e->getMessage().PHP_EOL);
    exit(1);
  }
  exit(0);
}

$stmt = $pdo->query("SELECT id FROM ingredients WHERE (solids_pct IS NULL OR fat_pct IS NULL OR sugar_pct IS NULL) AND is_active=1 ORDER BY id DESC LIMIT 10");
$ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
foreach ($ids as $id) {
  try {
    $res = enrich_one($pdo, (int)$id, $uid, true, 80);
    echo "OK #$id\n";
    sleep(1);
  } catch (Throwable $e) {
    fwrite(STDERR, "ERR #$id ".$e->getMessage()."\n");
  }
}
