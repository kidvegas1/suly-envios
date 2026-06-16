#!/bin/bash
set -euo pipefail

PORT="${PORT:-8080}"

sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf 2>/dev/null || true
sed -i "s/:8080>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf 2>/dev/null || true

# Bootstrap Postgres schema on first boot (Render Postgres / empty DATABASE_URL target).
if [ -n "${DATABASE_URL:-}" ] && [ -f /var/www/html/supabase/migrations/001_initial.sql ]; then
  if command -v psql >/dev/null 2>&1; then
    table_count="$(psql "$DATABASE_URL" -tAc "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'stores'" 2>/dev/null || echo 0)"
    if [ "${table_count:-0}" = "0" ]; then
      echo "[entrypoint] Seeding Postgres schema (001_initial.sql)..."
      psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f /var/www/html/supabase/migrations/001_initial.sql
      echo "[entrypoint] Schema seed complete."
    fi
  fi
fi

exec "$@"
