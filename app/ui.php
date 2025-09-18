<?php
declare(strict_types=1);

function pretty_urls(): bool { return ($_ENV['PRETTY_URLS'] ?? '0') === '1'; }
function url_for(string $p): string { return pretty_urls() ? $p : '/?r=' . ltrim($p, '/'); }
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/**
 * Require admin role (403 if not)
 */
function require_admin(): void {
  require_login();
  if (!role_is('admin')) { http_response_code(403); exit('Forbidden'); }
}

/**
 * Lightweight table helpers Codex can call.
 * They return HTML strings so you can concatenate into buffers.
 */

/**
 * Open a table with headers.
 * @param array $headers  Simple strings for column headers
 * @param array|string $attrs  Optional attributes (['class'=>'x','id'=>'y'] or "class='x' id='y'")
 */
function table_open(...$args): string {
  // Parse flexible arguments
  $headers = null;
  $attrs   = '';

  if (count($args) === 0) {
    // no headers, no attrs
  } elseif (count($args) === 1) {
    if (is_array($args[0])) $headers = $args[0]; else $attrs = (string)$args[0];
  } else {
    $headers = is_array($args[0]) ? $args[0] : null;
    $attrs   = $args[1];
  }

  // Build attribute string
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

  // No headers → plain table
  if (empty($headers)) return "<table{$attrStr}>";

  // Headers provided → render a header row
  $th = '';
  foreach ($headers as $hcell) {
    $th .= '<th>' . h((string)$hcell) . '</th>';
  }
  return "<table{$attrStr}><tr>{$th}</tr>";
}

/**
 * Add a table row. Each cell can be:
 *  - a string (treated as raw HTML)
 *  - or an array: ['text'=>'safe string'] or ['html'=>'raw html', 'class'=>'right', 'tag'=>'td']
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

/** Close the table */
function table_close(): string {
  return "</table>";
}

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
