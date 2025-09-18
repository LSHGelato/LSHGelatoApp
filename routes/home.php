<?php
$router->get('/', function() {
  render('Home', "<h1>Welcome</h1><p>App is alive on PHP ".h(PHP_VERSION).".</p><p>Use the nav to explore.</p>");
});

$router->get('/health', function() {
  global $pdo;
  try { $pdo->query("SELECT 1")->fetch(); render('Health', "<p class='ok'>DB OK</p>"); }
  catch (Throwable $e) { render('Health', "<p class='err'>DB error</p>"); }
});
