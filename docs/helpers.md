# UI Helpers Catalog

Authoritative list of global helpers available to routes and Codex prompts.
If you add or change a helper, update this file (or run the generator in `bin/update_helpers_catalog.php`).

## Navigation & Rendering

- `pretty_urls()`  
  Determine whether "pretty URLs" are enabled.

- `url_for(string $p)`  
  Build an app URL, honoring "pretty URLs" fallback.

- `render(string $title, string $body)`  
  Render a full page with standard chrome (header/nav) and body content.

- `h(?string $s)`  
  HTML-escape a string (safe for text nodes and attributes).

## Auth & Roles

- `require_login()`  
  Require an authenticated session; redirect to /login if absent.

- `require_admin()`  
  Require admin role; 403 if not authorized.

- `role_is(string $role)`  
  Check whether the current user has a role (or is admin).

## Request / CSRF / Flow

- `post_only()`  
  Enforce POST requests and verify CSRF token.

- `current_path()`  
  Get the current request path with PRETTY_URLS fallback.

## DB Transactions

- `db_tx(callable $fn)`  
  Execute a callback within a database transaction (commit/rollback wrapper).

## Table Helpers (HTML)

- `table_open(array $headers, $attrs = '')`  
  Open an HTML table with a header row.

- `table_row(array $cells)`  
  Build a table row (<tr>) from cell definitions.

- `table_close()`  
  Close an open HTML table.

> **Contract for Codex/PRs**  
> Use only the helpers listed above. If a new helper is needed, add it to `app/ui.php`, then update this file (or run the generator) in the same PR.
