# UI Helpers Catalog

Authoritative list of global helpers available to routes and Codex prompts.
If you add or change a helper, update this file (or run the generator in `bin/update_helpers_catalog.php`).

## Navigation & Rendering

- `pretty_urls()`  

- `url_for(string $p)`  

- `render(string $title, string $body)`  
  Main page renderer

- `h(?string $s)`  

## Auth & Roles

- `require_login()`  

- `require_admin()`  
  Require admin role (403 if not)

- `role_is(string $role)`  

## Request / CSRF / Flow

- `post_only()`  

- `current_path()`  
  Path canonicalization with PRETTY_URLS fallback

## DB Transactions

- `db_tx(callable $fn)`  

## Table Helpers (HTML)

- `table_open(...$args)`  
  Open a table with headers.

- `table_row(array $cells)`  
  Add a table row. Each cell can be:

- `table_close()`  
  Close the table

> **Contract for Codex/PRs**  
> Use only the helpers listed above. If a new helper is needed, add it to `app/ui.php`, then update this file (or run the generator) in the same PR.
