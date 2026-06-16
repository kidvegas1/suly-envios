#!/usr/bin/env bash
# Deploy via Render Blueprint API (uses render.yaml in repo root).
# Requires: RENDER_API_KEY (Account Settings → API Keys)
# Usage: export RENDER_API_KEY=rnd_... && bash scripts/render-blueprint-deploy.sh
set -euo pipefail

if [[ -z "${RENDER_API_KEY:-}" ]]; then
  echo "Set RENDER_API_KEY first (https://dashboard.render.com/u/settings#api-keys)" >&2
  exit 1
fi

REPO="${RENDER_BLUEPRINT_REPO:-https://github.com/kidvegas1/suly-envios}"
BRANCH="${RENDER_BLUEPRINT_BRANCH:-main}"

echo "Creating Render blueprint from ${REPO}@${BRANCH}..."
RESP="$(curl -sS -X POST "https://api.render.com/v1/blueprints" \
  -H "Authorization: Bearer ${RENDER_API_KEY}" \
  -H "Content-Type: application/json" \
  -d "{\"repo\":\"${REPO}\",\"branch\":\"${BRANCH}\"}")"

echo "$RESP" | php -r '
$j = json_decode(file_get_contents("php://stdin"), true);
if (!$j) { fwrite(STDERR, "Invalid JSON response\n"); exit(1); }
if (!empty($j["message"])) { echo "Render API: ".$j["message"]."\n"; exit(1); }
echo json_encode($j, JSON_PRETTY_PRINT)."\n";
'

echo ""
echo "Next: open Render Dashboard → Blueprint → set secret env vars (DATABASE_URL, SUPABASE_*, GEMINI_API_KEY, APP_URL)."
echo "Then verify: curl https://suly-envios.onrender.com/api/health"
