<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../db.php';

if (!isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) {
    fwrite(STDERR, "DB bootstrap failed: \$pdo not initialized.\n");
    exit(1);
}
/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'];

function has_table(PDO $pdo, string $name): bool {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
  $stmt->execute([$name]);
  return (bool)$stmt->fetchColumn();
}
function has_column(PDO $pdo, string $table, string $col): bool {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $stmt->execute([$table,$col]);
  return (bool)$stmt->fetchColumn();
}

$pdo->beginTransaction();
try {
  if (!has_table($pdo,'allergens')) {
    $pdo->exec("CREATE TABLE allergens (
      id INT AUTO_INCREMENT PRIMARY KEY,
      code VARCHAR(64) NOT NULL UNIQUE,
      name VARCHAR(120) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("INSERT INTO allergens (code,name) VALUES
      ('milk','Milk'),('egg','Egg'),('peanut','Peanut'),('tree_nut','Tree nuts'),
      ('sesame','Sesame'),('soy','Soy'),('wheat','Wheat'),('fish','Fish'),('shellfish','Crustacean shellfish')
    ");
  }
  if (!has_table($pdo,'ingredient_allergens')) {
    $pdo->exec("CREATE TABLE ingredient_allergens (
      ingredient_id INT NOT NULL,
      allergen_id INT NOT NULL,
      PRIMARY KEY (ingredient_id, allergen_id),
      FOREIGN KEY (ingredient_id) REFERENCES ingredients(id),
      FOREIGN KEY (allergen_id) REFERENCES allergens(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  }
  if (!has_table($pdo,'ai_enrichment_jobs')) {
    $pdo->exec("CREATE TABLE ai_enrichment_jobs (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      ingredient_id INT NOT NULL,
      task VARCHAR(64) NOT NULL,
      status ENUM('queued','succeeded','applied','failed') NOT NULL DEFAULT 'queued',
      requested_by INT NULL,
      note VARCHAR(255) NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (ingredient_id) REFERENCES ingredients(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  }
  if (!has_table($pdo,'ai_enrichment_results')) {
    $pdo->exec("CREATE TABLE ai_enrichment_results (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      job_id BIGINT NOT NULL,
      ingredient_id INT NOT NULL,
      proposed_allergens JSON NULL,
      proposed_solids_pct DECIMAL(6,3) NULL,
      proposed_fat_pct DECIMAL(6,3) NULL,
      proposed_sugar_pct DECIMAL(6,3) NULL,
      source VARCHAR(40) NOT NULL,
      source_ref VARCHAR(80) NULL,
      confidence INT NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (job_id) REFERENCES ai_enrichment_jobs(id),
      FOREIGN KEY (ingredient_id) REFERENCES ingredients(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  }
  if (!has_column($pdo,'ingredients','category')) {
    $pdo->exec("ALTER TABLE ingredients ADD COLUMN category VARCHAR(190) NULL AFTER name");
  }
  if (!has_column($pdo,'ingredients','nutrition_source')) {
    $pdo->exec("ALTER TABLE ingredients ADD COLUMN nutrition_source VARCHAR(40) NULL");
  }
  if (!has_column($pdo,'ingredients','nutrition_source_ref')) {
    $pdo->exec("ALTER TABLE ingredients ADD COLUMN nutrition_source_ref VARCHAR(80) NULL");
  }
  if (!has_column($pdo,'ingredients','nutrition_confidence')) {
    $pdo->exec("ALTER TABLE ingredients ADD COLUMN nutrition_confidence INT NULL");
  }

  $pdo->commit();
  echo "Migration OK\n";
} catch (Throwable $e) {
  $pdo->rollBack();
  http_response_code(500);
  echo "Migration failed: ".$e->getMessage()."\n";
}
