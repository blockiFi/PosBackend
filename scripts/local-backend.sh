#!/bin/bash
# Runs the API on this machine against its own SQLite database. Data persists
# between runs.
#
#   bash scripts/local-backend.sh             LAN only (seeds on first run)
#   bash scripts/local-backend.sh --fresh     wipe and reseed first
#   bash scripts/local-backend.sh --tunnel    also expose it on a public
#                                             https://*.trycloudflare.com URL
#
# Flags combine. PORT (default 8020) and HOST (default 0.0.0.0) can be overridden.
set -euo pipefail

cd "$(dirname "$0")/.."

PORT="${PORT:-8020}"
HOST="${HOST:-0.0.0.0}"
FRESH=false
TUNNEL=false
for arg in "$@"; do
    case "$arg" in
        --fresh) FRESH=true ;;
        --tunnel) TUNNEL=true ;;
        *) echo "Unknown option: $arg" >&2; exit 1 ;;
    esac
done

export DB_CONNECTION=sqlite
export DB_DATABASE="$PWD/storage/local.sqlite"
export CACHE_STORE=file
export SESSION_DRIVER=file
export QUEUE_CONNECTION=sync
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"

if lsof -nP -iTCP:"$PORT" -sTCP:LISTEN >/dev/null 2>&1; then
    echo "Port $PORT is already in use. Stop that process or run with PORT=<other>." >&2
    exit 1
fi

if $FRESH; then
    rm -f "$DB_DATABASE"
fi

if [[ ! -f "$DB_DATABASE" ]]; then
    touch "$DB_DATABASE"
    php artisan migrate --force --no-interaction
    php artisan db:seed --class=E2ePlaywrightSeeder --force --no-interaction
else
    php artisan migrate --force --no-interaction
fi

PUBLIC_URL=""
if $TUNNEL; then
    if ! command -v cloudflared >/dev/null; then
        echo "cloudflared is not installed. Run: brew install cloudflared" >&2
        exit 1
    fi

    TUNNEL_LOG="$PWD/storage/logs/cloudflared.log"
    : > "$TUNNEL_LOG"
    cloudflared tunnel --no-autoupdate --url "http://127.0.0.1:$PORT" >"$TUNNEL_LOG" 2>&1 &
    TUNNEL_PID=$!
    trap 'kill "$TUNNEL_PID" 2>/dev/null || true' EXIT

    for _ in $(seq 1 30); do
        PUBLIC_URL="$(grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' "$TUNNEL_LOG" | head -1 || true)"
        [[ -n "$PUBLIC_URL" ]] && break
        sleep 1
    done

    if [[ -z "$PUBLIC_URL" ]]; then
        echo "Cloudflare tunnel did not start. See $TUNNEL_LOG" >&2
        exit 1
    fi

    export APP_URL="$PUBLIC_URL"
fi

LAN_IP="$(ipconfig getifaddr en0 2>/dev/null || ipconfig getifaddr en1 2>/dev/null || true)"

cat <<EOF

Local API running
  This Mac:        http://127.0.0.1:$PORT/api
EOF
if [[ -n "$LAN_IP" ]]; then
    echo "  Same network:    http://$LAN_IP:$PORT/api"
fi
if [[ -n "$PUBLIC_URL" ]]; then
    echo "  Anywhere:        $PUBLIC_URL/api   (new URL every run)"
fi
cat <<EOF

Seeded logins (password: password)
  owner@e2e.test, storekeeper@e2e.test (PIN 123456), staff@e2e.test
  POS branch setup code: 246810 (valid 24h after seeding; rerun with --fresh to renew)

EOF

if $TUNNEL; then
    php artisan serve --host="$HOST" --port="$PORT"
else
    exec php artisan serve --host="$HOST" --port="$PORT"
fi
