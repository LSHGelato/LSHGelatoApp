<?php
$router->get('/fx/quote', function() {
  require_login(); if (!role_is('admin')) { http_response_code(403); exit('Forbidden'); }
  header('Content-Type: application/json');
  $date = $_GET['date'] ?? date('Y-m-d');
  $cur  = strtoupper($_GET['currency'] ?? 'BWP');
  if ($cur === 'BWP') { echo json_encode(['rate'=>1.0,'source'=>'par']); return; }
  global $pdo;
  $q = fx_get_rate($pdo, $date, $cur, 'BWP');
  if ($q) echo json_encode($q); else { http_response_code(404); echo json_encode(['error'=>'no-quote']); }
});
