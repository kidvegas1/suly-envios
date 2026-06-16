#!/usr/bin/env bash
# Trigger a Render deploy for suly-envios (use after git push if auto-deploy did not start).
set -euo pipefail

SERVICE_ID="${RENDER_SERVICE_ID:-srv-d8oq3kcvikkc73feh150}"
KEY_FILE="${RENDER_API_KEY_FILE:-$HOME/.cursor/secrets/render.env}"

if [[ -f "$KEY_FILE" ]]; then
  # shellcheck source=/dev/null
  source "$KEY_FILE"
fi

if [[ -z "${RENDER_API_KEY:-}" ]]; then
  echo "Set RENDER_API_KEY or run scripts/setup-render-mcp.sh first." >&2
  exit 1
fi

echo "Triggering deploy for service $SERVICE_ID ..."
curl -fsS -X POST "https://api.render.com/v1/services/${SERVICE_ID}/deploys" \
  -H "Authorization: Bearer ${RENDER_API_KEY}" \
  -H "Content-Type: application/json" \
  -d '{"clearCache":"do_not_clear"}'
echo ""
echo "Deploy triggered. Check: https://dashboard.render.com/web/srv-d8oq3kcvikkc73feh150"
