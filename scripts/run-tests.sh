#!/usr/bin/env bash
# Run automated tests (TDD gate before deploy).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "== PHP syntax =="
while IFS= read -r -d '' f; do
  php -l "$f" >/dev/null
done < <(find api includes -name '*.php' -print0)

echo "== gemini sanitizer =="
php scripts/test-gemini-sanitize.php

echo "== All tests passed =="
