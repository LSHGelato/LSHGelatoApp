#!/usr/bin/env bash
set -euo pipefail

# Resolve repo root relative to this script file
SCRIPT_DIR="$(cd -- "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
if [[ -z "$ROOT" ]]; then
  echo "Error: could not locate repo root. Make sure this script lives inside the repo." >&2
  exit 1
fi
cd "$ROOT"

git fetch --all --prune
git pull --ff-only origin main

# Optional: lint PHP
if command -v php >/dev/null; then
  find . -name '*.php' -print0 | xargs -0 -n1 -P4 php -l
fi

# --- Write build revision stamp (commit + UTC time) ---
mkdir -p "$ROOT/var"
rev="$(git -C "$ROOT" rev-parse --short HEAD)"
ts="$(TZ=UTC date +'%Y-%m-%dT%H:%M:%SZ')"   # UTC timestamp; stable across servers
printf "%s\n" "$rev $ts" > "$ROOT/var/REVISION"

echo "Deploy complete ✔  (repo: $ROOT)"
