#!/usr/bin/env bash
set -euo pipefail

# Production deploy for WorshipPlanning (run on the server).
# Usage: ./scripts/deploy.sh
# Override: DEPLOY_PATH=/var/www/html/WorshipPlanner BRANCH=main ./scripts/deploy.sh

DEPLOY_PATH="${DEPLOY_PATH:-/var/www/WorshipPlanner}"
BRANCH="${BRANCH:-main}"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
# Web server user that serves PHP / writes uploads (Fedora httpd = apache).
WEB_USER="${WEB_USER:-apache}"
WEB_GROUP="${WEB_GROUP:-$WEB_USER}"

cd "$DEPLOY_PATH"

echo "==> Deploying $(basename "$DEPLOY_PATH") @ $(date -Is)"
echo "    path=$DEPLOY_PATH branch=$BRANCH"

if [[ ! -f artisan ]]; then
    echo "ERROR: $DEPLOY_PATH does not look like a Laravel app (no artisan)." >&2
    exit 1
fi

if [[ ! -f .env ]]; then
    echo "ERROR: missing .env — create it on the server before deploying." >&2
    exit 1
fi

if [[ "${SKIP_GIT:-0}" != "1" ]]; then
    echo "==> Fetching $BRANCH"
    git fetch --prune origin "$BRANCH"
    git checkout "$BRANCH"
    git reset --hard "origin/$BRANCH"
else
    echo "==> SKIP_GIT=1 (already updated by caller)"
fi

echo "==> Composer"
$COMPOSER_BIN install --no-dev --optimize-autoloader --no-interaction

echo "==> Maintenance mode"
$PHP_BIN artisan down --retry=60 || true

cleanup() {
    $PHP_BIN artisan up || true
}
trap cleanup EXIT

echo "==> Frontend build"
if [[ -f package-lock.json ]]; then
    npm ci --no-audit --no-fund
else
    npm install --no-audit --no-fund
fi
npm run build

echo "==> Migrate"
# One-time: the 44 original migrations were squashed into 0001_01_01_000000_create_initial_schema.
# A database that already ran the last original migration has that exact schema, so record the
# squashed migration as run instead of letting it try to recreate existing tables. No-op afterwards.
$PHP_BIN artisan tinker --execute '
    $squashed = "0001_01_01_000000_create_initial_schema";
    $lastOriginal = "2026_10_28_193651_add_whatsapp_template_to_message_templates_table";
    $migrations = DB::table("migrations");
    if (Schema::hasTable("migrations") && $migrations->clone()->where("migration", $lastOriginal)->exists() && ! $migrations->clone()->where("migration", $squashed)->exists()) {
        DB::transaction(function () use ($squashed) {
            DB::table("migrations")->delete();
            DB::table("migrations")->insert(["migration" => $squashed, "batch" => 1]);
        });
        echo "Adopted squashed initial schema migration.\n";
    }
'
$PHP_BIN artisan migrate --force

echo "==> Optimize"
$PHP_BIN artisan optimize:clear
$PHP_BIN artisan optimize
$PHP_BIN artisan storage:link 2>/dev/null || true

echo "==> Permissions (storage / bootstrap/cache)"
# php-fpm/httpd runs as $WEB_USER; keep writable (incl. livewire-tmp — must not be 0700).
mkdir -p storage/app/public storage/app/private/livewire-tmp storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache 2>/dev/null || true
find storage bootstrap/cache -type d -exec chmod g+s {} \; 2>/dev/null || true
chmod 2775 storage/app/private storage/app/private/livewire-tmp 2>/dev/null || true

# Own uploads/cache as the web user so logos/avatars are readable/writable.
# Prefer passwordless sudo (see scripts/sudoers-worshipplanner.example) — do NOT put a sudo password in CI secrets.
fix_web_ownership() {
    local target=$1
    if chown -R "${WEB_USER}:${WEB_GROUP}" "$target" 2>/dev/null; then
        echo "    chown ${WEB_USER}:${WEB_GROUP} $target"
        return 0
    fi
    if sudo -n chown -R "${WEB_USER}:${WEB_GROUP}" "$target" 2>/dev/null; then
        echo "    sudo chown ${WEB_USER}:${WEB_GROUP} $target"
        return 0
    fi
    echo "WARN: could not chown $target → ${WEB_USER}:${WEB_GROUP}." >&2
    echo "      On the server once (as root):" >&2
    echo "        install -m 440 scripts/sudoers-worshipplanner.example /etc/sudoers.d/worshipplanner-deploy" >&2
    echo "        visudo -cf /etc/sudoers.d/worshipplanner-deploy" >&2
    echo "      Or: sudo chown -R ${WEB_USER}:${WEB_GROUP} $DEPLOY_PATH/storage $DEPLOY_PATH/bootstrap/cache" >&2
    return 1
}

fix_web_ownership "$DEPLOY_PATH/storage" || true
fix_web_ownership "$DEPLOY_PATH/bootstrap/cache" || true
chmod -R ug+rwX storage bootstrap/cache 2>/dev/null || true

trap - EXIT
$PHP_BIN artisan up

echo "==> Done $(git rev-parse --short HEAD)"
