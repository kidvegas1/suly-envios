#!/bin/bash
set -euo pipefail

PORT="${PORT:-8080}"

sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf 2>/dev/null || true
sed -i "s/:8080>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf 2>/dev/null || true

exec "$@"
