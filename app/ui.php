<?php
declare(strict_types=1);

function pretty_urls(): bool { return ($_ENV['PRETTY_URLS'] ?? '0') === '1'; }
function url_for(string $p): string { return pretty_urls() ? $p : '/?r=' . ltrim($p, '/'); }
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/** Main page renderer */
function render(string $title, string $body): void {
  // nav
  $nav = "<a href='".url_for("/")."'>Home</a>
          <a href='".url_for("/health")."'>Health</a>
          <a href='".url_for("/ingredients")."'>Ingredients</a>
          <a href='".url_for("/po")."'>PO</a>
          <a href='".url_for("/recipes")."'>Recipes</a>
          <a href='".url_for("/batches")."'>Batches</a>";

  if (!empty($_SESSION['user'])) {
    $role = (string)($_SESSION['user']['role'] ?? '');
    $nav .= " <span class='badge'>".h($role)."</span>";
    if ($role === 'admin') {
      if (is_readable(__DIR__ . '/enrichment.php')) {
        $nav .= " <a href='".url_for("/admin/enrich")."'>Enrich</a>";
      }
      $nav .= " <a href='".url_for("/admin/tools")."'>Admin</a>";
    }
    $nav .= " <a href='".url_for("/logout")."'>Logout</a>";
  } else {
    $nav .= " <a href='".url_for("/login")."'>Login</a>";
  }

  // optional build stamp (for admins only)
  $revHtml = '';
  if (!empty($_SESSION['user']) && (($_SESSION['user']['role'] ?? '') === 'admin')) {
    $revPath = __DIR__ . '/../var/REVISION';
    if (@is_readable($revPath)) {
      $revRaw = @file_get_contents($revPath);
      $rev = is_string($revRaw) ? trim($revRaw) : '';
      if ($rev !== '') {
        $revHtml = "<div class='small muted' style='margin-top:1rem'>Build: " . h($rev) . "</div>";
      }
    }
  }

  echo "<!doctype html><meta charset='utf-8'><title>".h($title)."</title>"
     . "<link rel='stylesheet' href='/assets/app.css'>"
     . "<header><div><strong>LSH Gelato</strong></div><nav>{$nav}</nav></header><hr/>"
     . $body
     . $revHtml;
}

function post_only(): void {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405); exit('Method Not Allowed');
  }
  csrf_verify();
}
function require_login(): void {
  if (empty($_SESSION['user'])) { header('Location: ' . url_for('/login')); exit; }
}
function role_is(string $role): bool {
  return !empty($_SESSION['user']) && (
    $_SESSION['user']['role'] === $role || $_SESSION['user']['role'] === 'admin'
  );
}
function db_tx(callable $fn) {
  global $pdo;
  try { $pdo->beginTransaction(); $res = $fn($pdo); $pdo->commit(); return $res; }
  catch (Throwable $e) { $pdo->rollBack(); http_response_code(500); render('Error', "<p class='err'>".h($e->getMessage())."</p>"); exit; }
}

/** Path canonicalization with PRETTY_URLS fallback */
function current_path(): string {
  if (!pretty_urls()) return '/' . ltrim((string)($_GET['r'] ?? '/'), '/');
  $p = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
  $p = is_string($p) ? $p : '/';
  return $p === '' ? '/' : $p;
}
