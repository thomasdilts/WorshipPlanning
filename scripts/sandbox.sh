#!/usr/bin/env bash
set -euo pipefail

# Isolated redesign sandbox: own SQLite database, sessions, cache, storage, logs and mail.
# Nothing here can reach your normal .env database, the live server, SMTP, SMS, WhatsApp or the Bible API.
#
#   scripts/sandbox.sh setup            # wipe + migrate + seed synthetic data
#   scripts/sandbox.sh serve [port]     # http://localhost:8001 (default)
#   scripts/sandbox.sh artisan <args>   # any artisan command inside the sandbox
#   scripts/sandbox.sh reset            # delete the sandbox entirely

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SANDBOX_DIR="$ROOT/storage/sandbox"
PORT=8001
if [[ "${1:-}" == "serve" && -n "${2:-}" ]]; then
    PORT="$2"
fi

mkdir -p \
    "$SANDBOX_DIR/app/public" \
    "$SANDBOX_DIR/framework/cache/data" \
    "$SANDBOX_DIR/framework/sessions" \
    "$SANDBOX_DIR/framework/views" \
    "$SANDBOX_DIR/framework/testing" \
    "$SANDBOX_DIR/logs"

export APP_ENV=sandbox
export APP_DEBUG=true
export APP_URL="http://localhost:${PORT}"
export LARAVEL_STORAGE_PATH="$SANDBOX_DIR"
export DB_CONNECTION=sqlite
export DB_DATABASE="$SANDBOX_DIR/database.sqlite"
export DB_URL=
export DB_HOST=127.0.0.1
export DB_PORT=3306
export DB_SOCKET=
export DB_USERNAME=sandbox
export DB_PASSWORD=
export SESSION_DRIVER=database
export CACHE_STORE=database
export QUEUE_CONNECTION=sync
export MAIL_MAILER=array
export FILESYSTEM_DISK=local
export LOG_CHANNEL=single
export ZAMAR_BIBLE_API_URL=http://127.0.0.1:9
export ZAMAR_BIBLE_API_KEY=

cd "$ROOT"

if [[ ! -f "$DB_DATABASE" && "${1:-}" != "setup" && "${1:-}" != "reset" ]]; then
    echo "Sandbox database missing. Run: scripts/sandbox.sh setup" >&2
    exit 1
fi

case "${1:-}" in
    setup)
        rm -f "$DB_DATABASE"
        touch "$DB_DATABASE"
        php artisan migrate:fresh --seed --seeder=SandboxSeeder --force --no-interaction
        echo "Sandbox ready. Logins (password: password): admin@worshipplanning.test, calum@example.test, planner@example.test, anna@example.test"
        ;;
    serve)
        exec php artisan serve --host=127.0.0.1 --port="$PORT"
        ;;
    artisan)
        shift
        exec php artisan "$@"
        ;;
    reset)
        rm -rf "$SANDBOX_DIR"
        echo "Sandbox removed."
        ;;
    *)
        echo "Usage: scripts/sandbox.sh {setup|serve [port]|artisan <args>|reset}" >&2
        exit 2
        ;;
esac
