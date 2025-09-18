-- LSH Gelato Base Schema
-- Engine/charset
SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- Users
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','operator') NOT NULL DEFAULT 'operator',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ingredients
CREATE TABLE IF NOT EXISTS ingredients (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  name_normalized VARCHAR(255) NULL,
  category VARCHAR(80) NULL,
  unit_kind ENUM('g','ml') NOT NULL,
  reorder_point DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  solids_pct DECIMAL(6,3) NULL,
  fat_pct DECIMAL(6,3) NULL,
  sugar_pct DECIMAL(6,3) NULL,
  density_g_per_ml DECIMAL(10,6) NULL,
  nutrition_source ENUM('manual','usda_fdc','open_food_facts','ai_suggested') NULL,
  nutrition_source_ref VARCHAR(64) NULL,
  nutrition_confidence TINYINT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  notes TEXT NULL,
  UNIQUE KEY uq_ingredients_name (name),
  KEY idx_ingredients_norm (name_normalized)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //
CREATE TRIGGER trg_ingredients_bi BEFORE INSERT ON ingredients
FOR EACH ROW BEGIN
  SET NEW.name_normalized = LOWER(REPLACE(REPLACE(NEW.name, ',', ''), ' ', ''));
END//
CREATE TRIGGER trg_ingredients_bu BEFORE UPDATE ON ingredients
FOR EACH ROW BEGIN
  SET NEW.name_normalized = LOWER(REPLACE(REPLACE(NEW.name, ',', ''), ' ', ''));
END//
DELIMITER ;

-- Suppliers
CREATE TABLE IF NOT EXISTS suppliers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL UNIQUE,
  contact VARCHAR(190) NULL,
  notes TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Exchange rates
CREATE TABLE IF NOT EXISTS exchange_rates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  rate_date DATE NOT NULL,
  currency ENUM('USD','BWP','ZAR') NOT NULL,
  rate_to_bwp DECIMAL(18,8) NOT NULL,
  UNIQUE KEY uq_rate (rate_date, currency)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Purchase Orders
CREATE TABLE IF NOT EXISTS purchase_orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  po_number VARCHAR(64) NULL,
  supplier_id INT NULL,
  order_date DATE NOT NULL,
  currency ENUM('USD','BWP','ZAR') NOT NULL,
  fx_rate_used DECIMAL(18,8) NOT NULL,
  created_by INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notes TEXT NULL,
  CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
  CONSTRAINT fk_po_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_order_lines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  purchase_order_id INT NOT NULL,
  ingredient_id INT NOT NULL,
  qty DECIMAL(12,3) NOT NULL,
  unit_cost_native DECIMAL(12,4) NOT NULL,
  unit_cost_bwp DECIMAL(12,4) NOT NULL,
  line_note VARCHAR(255) NULL,
  CONSTRAINT fk_pol_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id),
  CONSTRAINT fk_pol_ing FOREIGN KEY (ingredient_id) REFERENCES ingredients(id),
  KEY idx_pol_ing (ingredient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inventory transactions
CREATE TABLE IF NOT EXISTS inventory_txns (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  ingredient_id INT NOT NULL,
  txn_ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  txn_date DATE NOT NULL,
  txn_type ENUM('purchase','adjustment','consumption') NOT NULL,
  qty DECIMAL(12,3) NOT NULL,
  unit_kind ENUM('g','ml') NOT NULL,
  unit_cost_bwp DECIMAL(12,4) NULL,
  source_table ENUM('purchase_order_lines','batches','manual') NOT NULL,
  source_id INT NULL,
  note VARCHAR(255) NULL,
  created_by INT NOT NULL,
  CONSTRAINT fk_inv_ing FOREIGN KEY (ingredient_id) REFERENCES ingredients(id),
  CONSTRAINT fk_inv_user FOREIGN KEY (created_by) REFERENCES users(id),
  KEY idx_inv_ing_date (ingredient_id, txn_date),
  UNIQUE KEY uq_batch_ing_once (ingredient_id, source_table, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- WAC history
CREATE TABLE IF NOT EXISTS ingredient_wac_history (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  ingredient_id INT NOT NULL,
  effective_ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  wac_bwp DECIMAL(12,4) NOT NULL,
  current_flag TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_wac_ing FOREIGN KEY (ingredient_id) REFERENCES ingredients(id),
  KEY idx_wac_ing (ingredient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Recipes
CREATE TABLE IF NOT EXISTS recipes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  style ENUM('gelato','sorbet','other') NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  notes TEXT NULL,
  UNIQUE KEY uq_recipe_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recipe_versions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  recipe_id INT NOT NULL,
  version_no INT NOT NULL,
  default_yield_g DECIMAL(12,3) NOT NULL,
  pac DECIMAL(8,3) NULL,
  pod DECIMAL(8,3) NULL,
  quart_est_g DECIMAL(10,2) NULL,
  pint_est_g DECIMAL(10,2) NULL,
  single_est_g DECIMAL(10,2) NULL,
  alt_resolution ENUM('primary','heaviest') NOT NULL DEFAULT 'primary',
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by INT NOT NULL,
  CONSTRAINT fk_rv_recipe FOREIGN KEY (recipe_id) REFERENCES recipes(id),
  CONSTRAINT fk_rv_user FOREIGN KEY (created_by) REFERENCES users(id),
  UNIQUE KEY uq_recipe_version (recipe_id, version_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recipe_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  recipe_version_id INT NOT NULL,
  choice_group INT NOT NULL DEFAULT 0,
  ingredient_id INT NOT NULL,
  qty DECIMAL(12,3) NOT NULL,
  unit_kind ENUM('g','ml') NOT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  step_notes VARCHAR(255) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_ri_rv FOREIGN KEY (recipe_version_id) REFERENCES recipe_versions(id),
  CONSTRAINT fk_ri_ing FOREIGN KEY (ingredient_id) REFERENCES ingredients(id),
  KEY idx_recipe_items_rv (recipe_version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recipe_steps (
  id INT AUTO_INCREMENT PRIMARY KEY,
  recipe_version_id INT NOT NULL,
  step_no INT NOT NULL,
  instruction TEXT NOT NULL,
  CONSTRAINT fk_steps_rv FOREIGN KEY (recipe_version_id) REFERENCES recipe_versions(id),
  UNIQUE KEY uq_recipe_step (recipe_version_id, step_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Batches
CREATE TABLE IF NOT EXISTS batches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  recipe_version_id INT NOT NULL,
  batch_date DATETIME NOT NULL,
  target_mix_g DECIMAL(12,3) NOT NULL,
  bowl_weight_g DECIMAL(12,3) NULL,
  bowl_plus_mix_g DECIMAL(12,3) NULL,
  actual_mix_g DECIMAL(12,3) NULL,
  notes TEXT NULL,
  created_by INT NOT NULL,
  deduct_inventory TINYINT(1) NOT NULL DEFAULT 1,
  cogs_bwp DECIMAL(14,2) NULL,
  CONSTRAINT fk_batches_rv FOREIGN KEY (recipe_version_id) REFERENCES recipe_versions(id),
  CONSTRAINT fk_batches_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Batch resolutions (with checklist fields)
CREATE TABLE IF NOT EXISTS batch_ingredient_resolutions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  batch_id INT NOT NULL,
  recipe_item_id INT NOT NULL,
  resolved_ingredient_id INT NOT NULL,
  scaled_qty DECIMAL(12,3) NOT NULL,
  unit_kind ENUM('g','ml') NOT NULL,
  checked_flag TINYINT(1) NOT NULL DEFAULT 0,
  measured_qty DECIMAL(12,3) NULL,
  checked_at DATETIME NULL,
  checked_by INT NULL,
  CONSTRAINT fk_bir_batch FOREIGN KEY (batch_id) REFERENCES batches(id),
  CONSTRAINT fk_bir_ri FOREIGN KEY (recipe_item_id) REFERENCES recipe_items(id),
  CONSTRAINT fk_bir_ing FOREIGN KEY (resolved_ingredient_id) REFERENCES ingredients(id),
  CONSTRAINT fk_bir_user FOREIGN KEY (checked_by) REFERENCES users(id),
  UNIQUE KEY uq_bir (batch_id, recipe_item_id),
  KEY idx_bir_batch (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Packaging
CREATE TABLE IF NOT EXISTS package_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  nominal_g_per_unit DECIMAL(10,2) NOT NULL,
  packaging_cost_bwp DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  UNIQUE KEY uq_package_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS batch_packouts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  batch_id INT NOT NULL,
  package_type_id INT NOT NULL,
  unit_count INT NOT NULL,
  CONSTRAINT fk_pack_batch FOREIGN KEY (batch_id) REFERENCES batches(id),
  CONSTRAINT fk_pack_type FOREIGN KEY (package_type_id) REFERENCES package_types(id),
  UNIQUE KEY uq_batch_pack (batch_id, package_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inventory adjustments (writes-through to ledger via application)
CREATE TABLE IF NOT EXISTS inventory_adjustments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ingredient_id INT NOT NULL,
  adj_date DATE NOT NULL,
  qty DECIMAL(12,3) NOT NULL,
  reason VARCHAR(190) NOT NULL,
  created_by INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_adj_ing FOREIGN KEY (ingredient_id) REFERENCES ingredients(id),
  CONSTRAINT fk_adj_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ingredient import staging
CREATE TABLE IF NOT EXISTS ingredient_import_staging (
  id INT AUTO_INCREMENT PRIMARY KEY,
  raw_name VARCHAR(255) NOT NULL,
  category VARCHAR(80) NULL,
  unit_kind ENUM('g','ml') NOT NULL,
  reorder_point DECIMAL(12,3) NULL,
  solids_pct DECIMAL(6,3) NULL,
  fat_pct DECIMAL(6,3) NULL,
  sugar_pct DECIMAL(6,3) NULL,
  density_g_per_ml DECIMAL(10,6) NULL,
  import_batch VARCHAR(64) NOT NULL,
  created_by INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_iis_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Allergens + junction
CREATE TABLE IF NOT EXISTS allergens (
  id TINYINT PRIMARY KEY,
  code VARCHAR(32) UNIQUE NOT NULL,
  name VARCHAR(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO allergens (id, code, name) VALUES
(1,'milk','Milk'),(2,'egg','Egg'),(3,'fish','Fish'),
(4,'shellfish','Crustacean shellfish'),(5,'tree_nut','Tree nuts'),
(6,'peanut','Peanuts'),(7,'wheat','Wheat'),(8,'soy','Soybeans'),
(9,'sesame','Sesame');

CREATE TABLE IF NOT EXISTS ingredient_allergens (
  ingredient_id INT NOT NULL,
  allergen_id TINYINT NOT NULL,
  PRIMARY KEY (ingredient_id, allergen_id),
  CONSTRAINT fk_ingall_ing FOREIGN KEY (ingredient_id) REFERENCES ingredients(id),
  CONSTRAINT fk_ingall_all FOREIGN KEY (allergen_id) REFERENCES allergens(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Duplicate helper
CREATE TABLE IF NOT EXISTS ingredient_similarity (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name_a VARCHAR(255) NOT NULL,
  name_b VARCHAR(255) NOT NULL,
  score DECIMAL(5,3) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_by INT NULL,
  decision ENUM('keep_both','merge_into_a','merge_into_b','rename','skip') NULL,
  decision_note VARCHAR(255) NULL,
  CONSTRAINT fk_sim_user FOREIGN KEY (reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AI enrichment job + results
CREATE TABLE IF NOT EXISTS ai_enrichment_jobs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ingredient_id INT NOT NULL,
  task ENUM('allergens','nutrition') NOT NULL,
  status ENUM('queued','running','succeeded','failed') NOT NULL DEFAULT 'queued',
  requested_by INT NOT NULL,
  requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  run_at TIMESTAMP NULL,
  note VARCHAR(255) NULL,
  CONSTRAINT fk_aiej_ing FOREIGN KEY (ingredient_id) REFERENCES ingredients(id),
  CONSTRAINT fk_aiej_user FOREIGN KEY (requested_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_enrichment_results (
  id INT AUTO_INCREMENT PRIMARY KEY,
  job_id INT NOT NULL,
  ingredient_id INT NOT NULL,
  proposed_allergens JSON NULL,
  proposed_solids_pct DECIMAL(6,3) NULL,
  proposed_fat_pct DECIMAL(6,3) NULL,
  proposed_sugar_pct DECIMAL(6,3) NULL,
  source ENUM('usda_fdc','open_food_facts','ai_inference') NOT NULL,
  source_ref VARCHAR(64) NULL,
  confidence TINYINT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_aier_job FOREIGN KEY (job_id) REFERENCES ai_enrichment_jobs(id),
  CONSTRAINT fk_aier_ing FOREIGN KEY (ingredient_id) REFERENCES ingredients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Views
CREATE OR REPLACE VIEW v_stock_on_hand AS
SELECT
  i.id AS ingredient_id,
  i.name,
  i.unit_kind,
  COALESCE(SUM(t.qty),0) AS qty_on_hand
FROM ingredients i
LEFT JOIN inventory_txns t ON t.ingredient_id = i.id
GROUP BY i.id, i.name, i.unit_kind;

CREATE OR REPLACE VIEW v_current_wac AS
SELECT h.ingredient_id, h.wac_bwp
FROM ingredient_wac_history h
JOIN (
  SELECT ingredient_id, MAX(effective_ts) AS max_ts
  FROM ingredient_wac_history
  GROUP BY ingredient_id
) m ON m.ingredient_id = h.ingredient_id AND m.max_ts = h.effective_ts;

CREATE OR REPLACE VIEW v_ingredients_snapshot AS
SELECT
  i.*,
  s.qty_on_hand,
  w.wac_bwp
FROM ingredients i
LEFT JOIN v_stock_on_hand s ON s.ingredient_id = i.id
LEFT JOIN v_current_wac w   ON w.ingredient_id = i.id;

-- Seed package types
INSERT IGNORE INTO package_types (id, name, nominal_g_per_unit, packaging_cost_bwp) VALUES
(1,'quart', 946.00, 0.00),
(2,'pint', 473.00, 0.00),
(3,'single', 28.35, 0.00);
