<?php
declare(strict_types=1);

/**
 * Determine whether "pretty URLs" are enabled.
 *
 * Controlled by the environment variable PRETTY_URLS.
 *
 * @return bool True when $_ENV['PRETTY_URLS'] === '1'.
 */
function pretty_urls(): bool { return ($_ENV['PRETTY_URLS'] ?? '0') === '1'; }

/**
 * Build an app URL, honoring "pretty URLs" fallback.
 *
 * When PRETTY_URLS is off, it rewrites paths as "/?r=/path".
 *
 * @param string $p Absolute app path beginning with "/" (e.g. "/recipes").
 * @return string Fully-formed URL for the current routing mode.
 */
function url_for(string $p): string { return pretty_urls() ? $p : '/?r=' . ltrim($p, '/'); }

/**
 * HTML-escape a string (safe for text nodes and attributes).
 *
 * @param string|null $s Raw string (or null).
 * @return string Escaped string (empty when input is null).
 */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/**
 * Require admin role; 403 if not authorized.
 *
 * Calls require_login() first, then checks role_is('admin').
 * Terminates the request with 403 Forbidden on failure.
 *
 * @return void
 */
function require_admin(): void {
  require_login();
  if (!role_is('admin')) { http_response_code(403); exit('Forbidden'); }
}

/**
 * Open an HTML table with a header row.
 *
 * @param array<int,string>                    $headers Column headers.
 * @param array<string,string>|string          $attrs   Optional attributes
 *                                                     (assoc array or raw string).
 * @return string The opening <table> and the header <tr>.
 */
function table_open(array $headers, $attrs = ''): string {
  $attrStr = '';
  if (is_array($attrs)) {
    foreach ($attrs as $k => $v) {
      if ($v === null || $v === '') continue;
      $attrStr .= ' ' . h((string)$k) . "='" . h((string)$v) . "'";
    }
  } else {
    $attrs = (string)$attrs;
    if ($attrs !== '' && $attrs[0] !== ' ') $attrs = ' ' . $attrs;
    $attrStr = $attrs;
  }
  $th = '';
  foreach ($headers as $hcell) {
    $th .= '<th>' . h((string)$hcell) . '</th>';
  }
  return "<table{$attrStr}><tr>{$th}</tr>";
}

/**
 * Build a table row (<tr>) from cell definitions.
 *
 * Each cell can be:
 *  - string: treated as raw HTML
 *  - array:  [
 *              'text'  => 'will be escaped'  (mutually exclusive with 'html'),
 *              'html'  => '<b>raw</b>',
 *              'class' => 'right',
 *              'tag'   => 'td'|'th'
 *            ]
 *
 * @param array<int, string|array{text?:string, html?:string, class?:string, tag?:string}> $cells
 * @return string HTML <tr>…</tr>
 */
function table_row(array $cells): string {
  $row = '';
  foreach ($cells as $c) {
    $tag = 'td';
    $class = '';
    if (is_array($c)) {
      if (isset($c['tag']) && is_string($c['tag'])) $tag = $c['tag'];
      if (isset($c['class']) && is_string($c['class'])) $class = $c['class'];
      if (array_key_exists('html', $c)) {
        $content = (string)$c['html']; // raw, caller is responsible
      } elseif (array_key_exists('text', $c)) {
        $content = h((string)$c['text']);
      } else {
        $content = '';
      }
    } else {
      // treat plain string as raw HTML (matches typical $tbl .= usage)
      $content = (string)$c;
    }
    $clsAttr = $class !== '' ? " class='" . h($class) . "'" : '';
    $row .= "<{$tag}{$clsAttr}>{$content}</{$tag}>";
  }
  return "<tr>{$row}</tr>";
}

/**
 * Close an open HTML table.
 *
 * @return string The closing </table> tag.
 */
function table_close(): string {
  return "</table>";
}

/**
 * Render a full page with standard chrome (header/nav) and body content.
 *
 * Adds an admin-only build stamp from var/REVISION when present.
 *
 * @param string $title Page title.
 * @param string $body  Raw HTML body (already escaped where needed).
 * @return void
 */
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

/**
 * Enforce POST requests and verify CSRF token.
 *
 * Calls csrf_verify(), which should be required from app/csrf.php via bootstrap.
 * Sends HTTP 405 and exits if method is not POST.
 *
 * @return void
 */
function post_only(): void {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405); exit('Method Not Allowed');
  }
  csrf_verify();
}

/**
 * Require an authenticated session; redirect to /login if absent.
 *
 * @return void
 */
function require_login(): void {
  if (empty($_SESSION['user'])) { header('Location: ' . url_for('/login')); exit; }
}

/**
 * Check whether the current user has a role (or is admin).
 *
 * @param string $role Required role (e.g., 'admin', 'staff').
 * @return bool True when the user has the role or is admin.
 */
function role_is(string $role): bool {
  return !empty($_SESSION['user']) && (
    $_SESSION['user']['role'] === $role || $_SESSION['user']['role'] === 'admin'
  );
}

/**
 * Execute a callback within a database transaction (commit/rollback wrapper).
 *
 * Example:
 *   $result = db_tx(function(PDO $pdo) { ...; return $value; });
 *
 * @param callable $fn Callback of the form function(PDO $pdo): mixed
 * @return mixed      Whatever the callback returns.
 */
function db_tx(callable $fn) {
  global $pdo;
  try { $pdo->beginTransaction(); $res = $fn($pdo); $pdo->commit(); return $res; }
  catch (Throwable $e) { $pdo->rollBack(); http_response_code(500); render('Error', "<p class='err'>".h($e->getMessage())."</p>"); exit; }
}

/**
 * Get the current request path with PRETTY_URLS fallback.
 *
 * When PRETTY_URLS is off, it uses the query parameter "r".
 *
 * @return string Current request path beginning with "/".
 */
function current_path(): string {
  if (!pretty_urls()) return '/' . ltrim((string)($_GET['r'] ?? '/'), '/');
  $p = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
  $p = is_string($p) ? $p : '/';
  return $p === '' ? '/' : $p;
}
