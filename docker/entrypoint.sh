#!/bin/bash
set -euo pipefail

PORT="${PORT:-8080}"

sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf 2>/dev/null || true
sed -i "s/:8080>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf 2>/dev/null || true

# Bootstrap Postgres schema on first boot (Render Postgres / empty DATABASE_URL target).
if [ -n "${DATABASE_URL:-}" ] && [ -f /var/www/html/supabase/migrations/001_initial.sql ]; then
  if command -v psql >/dev/null 2>&1; then
    users_count="$(psql "$DATABASE_URL" -tAc "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'users'" 2>/dev/null || echo 0)"
    users_count="$(echo "$users_count" | tr -d '[:space:]')"
    if [ "${users_count:-0}" = "0" ]; then
      table_count="$(psql "$DATABASE_URL" -tAc "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public'" 2>/dev/null || echo 0)"
      table_count="$(echo "$table_count" | tr -d '[:space:]')"
      if [ "${table_count:-0}" != "0" ]; then
        echo "[entrypoint] Partial schema detected (${table_count} tables); resetting public schema..."
        psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -c "DROP SCHEMA public CASCADE; CREATE SCHEMA public; GRANT ALL ON SCHEMA public TO public;"
      fi
      echo "[entrypoint] Seeding Postgres schema (001_initial.sql)..."
      psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f /var/www/html/supabase/migrations/001_initial.sql
      echo "[entrypoint] Schema seed complete."
    else
      echo "[entrypoint] Postgres schema already present (users table found)."
      status_col="$(psql "$DATABASE_URL" -tAc "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'suly_ledger' AND column_name = 'status'" 2>/dev/null || echo 0)"
      status_col="$(echo "$status_col" | tr -d '[:space:]')"
      if [ "${status_col:-0}" = "0" ] && [ -f /var/www/html/migrate-suly-ledger-status.sql ]; then
        echo "[entrypoint] Applying suly_ledger status migration..."
        psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f /var/www/html/migrate-suly-ledger-status.sql
      fi
      finance_col="$(psql "$DATABASE_URL" -tAc "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'barri_reports' AND column_name = 'finance_class'" 2>/dev/null || echo 0)"
      finance_col="$(echo "$finance_col" | tr -d '[:space:]')"
      if [ "${finance_col:-0}" = "0" ] && [ -f /var/www/html/migrate-side-finances.sql ]; then
        echo "[entrypoint] Applying side finances migration..."
        psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f /var/www/html/migrate-side-finances.sql
      fi
      flags_tbl="$(psql "$DATABASE_URL" -tAc "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'company_flags'" 2>/dev/null || echo 0)"
      flags_tbl="$(echo "$flags_tbl" | tr -d '[:space:]')"
      if [ "${flags_tbl:-0}" = "0" ] && [ -f /var/www/html/migrate-company-flags.sql ]; then
        echo "[entrypoint] Applying company flags migration..."
        psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f /var/www/html/migrate-company-flags.sql
      fi
      activity_tbl="$(psql "$DATABASE_URL" -tAc "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'client_activity_log'" 2>/dev/null || echo 0)"
      activity_tbl="$(echo "$activity_tbl" | tr -d '[:space:]')"
      if [ "${activity_tbl:-0}" = "0" ] && [ -f /var/www/html/migrate-client-security.sql ]; then
        echo "[entrypoint] Applying client security migration..."
        psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f /var/www/html/migrate-client-security.sql
      fi
    fi
  else
    echo "[entrypoint] WARNING: psql not found; skipping Postgres schema bootstrap."
  fi
fi

exec "$@"
