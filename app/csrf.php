<?php
declare(strict_types=1);

function csrf_token(): string {
    start_secure_session();
    $t = bin2hex(random_bytes(16));
    if (!isset($_SESSION['csrf_pool']) || !is_array($_SESSION['csrf_pool'])) {
        $_SESSION['csrf_pool'] = [];
    }
    $_SESSION['csrf_pool'][$t] = time();
    return $t;
}

function csrf_field(): string {
    $tok = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
    return "<input type='hidden' name='_csrf' value='".$tok."'>";
}

function csrf_verify(): void {
    start_secure_session();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }

    // Resolve routed path even for /?r=/login
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    if ($path === '/' && isset($_GET['r'])) {
        $path = '/' . ltrim((string)$_GET['r'], '/');
    }

    // Exempt /login POST to avoid false "Bad CSRF"
    if ($path === '/login') {
        return;
    }

    $tok  = (string)($_POST['_csrf'] ?? $_POST['csrf'] ?? '');
    $pool = $_SESSION['csrf_pool'] ?? [];
    $now  = time();
    $ttl  = 7200;

    // purge expired
    foreach ($pool as $k => $ts) {
        if (($now - (int)$ts) > $ttl) unset($pool[$k]);
    }

    $ok = ($tok !== '') && isset($pool[$tok]);
    if ($ok) {
        unset($pool[$tok]);
        $_SESSION['csrf_pool'] = $pool;
        return;
    }

    http_response_code(400);
    exit('Bad CSRF');
}
