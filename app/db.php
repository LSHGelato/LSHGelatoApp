<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$dsn = 'mysql:host=' . ($_ENV['DB_HOST'] ?? 'localhost')
     . ';dbname=' . ($_ENV['DB_NAME'] ?? 'raneywor_gelato')
     . ';charset=utf8mb4';

$options = [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES => false,
];

try {
  $pdo = new PDO($dsn, $_ENV['DB_USER'] ?? '', $_ENV['DB_PASS'] ?? '', $options);
  // Stricter SQL mode
  $pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
} catch (Throwable $e) {
  http_response_code(500);
  echo 'Database connection failed.';
  exit;
}

// Force MySQL session time zone to match PHP/app
$tzName = $_ENV['APP_TZ'] ?? 'Africa/Johannesburg';   // SAST
$offset = (new DateTime('now', new DateTimeZone($tzName)))->format('P'); // e.g. "+02:00"

// Try the named zone first (if MySQL tz tables are loaded), ignore failures
try { $pdo->exec("SET time_zone = " . $pdo->quote($tzName)); } catch (Throwable $e) { /* ignore */ }

// Always finish by setting the numeric offset (guaranteed to work)
$pdo->exec("SET time_zone = " . $pdo->quote($offset));
