<?php
declare(strict_types=1);

// Load .env (very small loader; no Composer needed)
$envPath = __DIR__ . '/../.env';
if (is_readable($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if ($line[0] === '#') continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        $_ENV[$k] = $v;
    }
}

// app/bootstrap.php
// before doing anything that calls date()/time()
$tz = $_ENV['APP_TZ'] ?? 'Africa/Johannesburg'; // SAST, no DST
if (!@date_default_timezone_set($tz)) {
  // last-ditch safety
  date_default_timezone_set('UTC');
}
