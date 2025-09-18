<?php
declare(strict_types=1);

$env = file_exists(__DIR__.'/app/env.local.php')
  ? require __DIR__.'/app/env.local.php'
  : require __DIR__.'/app/env.example.php';

require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/csrf.php';
require_once __DIR__ . '/app/wac_tools.php';
require_once __DIR__ . '/app/po_normalize_tools.php';
require_once __DIR__ . '/app/fx.php';
if (is_readable(__DIR__ . '/app/enrichment.php')) { require_once __DIR__ . '/app/enrichment.php'; }

require_once __DIR__ . '/app/ui.php';
require_once __DIR__ . '/app/router.php';

start_secure_session(); // lives in app/auth.php

$router = new Router();

/** Register routes (gradually; start with core ones) */
require __DIR__ . '/routes/home.php';
require __DIR__ . '/routes/auth.php';
require __DIR__ . '/routes/fx_api.php';
require __DIR__ . '/routes/fx_admin.php';
require __DIR__ . '/routes/po.php';
require __DIR__ . '/routes/ingredients.php';
require __DIR__ . '/routes/recipes.php';
require __DIR__ . '/routes/batches.php';
require __DIR__ . '/routes/inventory.php';
require __DIR__ . '/routes/admin_tools.php';
require __DIR__ . '/routes/enrich.php';

$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', current_path());
