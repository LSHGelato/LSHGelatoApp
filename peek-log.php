<?php
// /peek-log.php
header('Content-Type: text/plain; charset=utf-8');
$log = '/home1/raneywor/logs/gelato_php_errors.log';  // your INI setting
if (!is_readable($log)) { echo "Log not readable: $log\n"; exit; }
$lines = @file($log, FILE_IGNORE_NEW_LINES);
if (!$lines) { echo "No lines in log.\n"; exit; }
$tail = array_slice($lines, -120);   // last ~120 lines
echo implode("\n", $tail), "\n";
