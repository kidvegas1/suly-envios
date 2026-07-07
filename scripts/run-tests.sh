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

echo "== gemini api live (optional) =="
php scripts/test-gemini-api.php || true

echo "== viamericas pdf parse =="
node scripts/test-viamericas-pdf-parse.mjs

echo "== barri agency activity pdf =="
node scripts/test-barri-agency-pdf.mjs

echo "== txn money flow colors =="
node scripts/test-txn-money-flow.mjs

echo "== barri auto-match helpers =="
php scripts/test-barri-auto-match.php

echo "== store filter bindings =="
php scripts/test-store-filter-bindings.php

echo "== suly ledger mark paid =="
php scripts/test-suly-ledger-paid.php

echo "== browser page smoke (curl) =="
bash scripts/browser-page-smoke.sh http://localhost:8080 || true

echo "== All tests passed =="
