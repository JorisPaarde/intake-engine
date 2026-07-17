#!/usr/bin/env bash
#
# Draait OP DE SERVER, aangeroepen door de deploy-workflow.
# Activeert een nieuwe release atomisch:
#   1. koppelt shared .env en storage/
#   2. draait migraties en cachet config/routes/views
#   3. wisselt de `current`-symlink om
#   4. herstart de queue en ruimt oude releases op
#
# Gebruik: activate.sh <deploy_path> <release_relpath> <php_bin>
#
set -euo pipefail

DEPLOY_PATH="$1"          # bv. /home/user/apps/intake-engine-staging
RELEASE="$2"              # bv. releases/abc123def456
PHP_BIN="${3:-php}"

RELEASE_PATH="$DEPLOY_PATH/$RELEASE"
SHARED="$DEPLOY_PATH/shared"

[ -f "$RELEASE_PATH/artisan" ] || { echo "Geen artisan in $RELEASE_PATH"; exit 1; }
[ -f "$SHARED/.env" ] || { echo "FOUT: $SHARED/.env ontbreekt. Maak deze eerst aan (zie docs/DEPLOYMENT.md)."; exit 1; }

echo "==> Shared resources koppelen"
mkdir -p "$SHARED/storage/app/public" \
         "$SHARED/storage/framework/cache/data" \
         "$SHARED/storage/framework/sessions" \
         "$SHARED/storage/framework/views" \
         "$SHARED/storage/logs"
ln -sfn "$SHARED/.env" "$RELEASE_PATH/.env"
rm -rf "$RELEASE_PATH/storage"
ln -sfn "$SHARED/storage" "$RELEASE_PATH/storage"

cd "$RELEASE_PATH"

echo "==> storage:link + migraties + template-reference"
"$PHP_BIN" artisan storage:link --force
"$PHP_BIN" artisan migrate --force
# Alleen idempotente reference-data (airco-template). Geen demo-users/intakes.
"$PHP_BIN" artisan db:seed --class=IntakeTemplateSeeder --force

echo "==> Caches verversen"
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache
"$PHP_BIN" artisan event:cache

echo "==> Release activeren"
ln -sfn "$RELEASE_PATH" "$DEPLOY_PATH/current.tmp" && mv -Tf "$DEPLOY_PATH/current.tmp" "$DEPLOY_PATH/current"

echo "==> Queue herstarten"
"$PHP_BIN" artisan queue:restart

echo "==> Oude releases opruimen (bewaar laatste 5)"
cd "$DEPLOY_PATH/releases"
ls -1t | tail -n +6 | xargs -r rm -rf

echo "==> Deploy gereed: $RELEASE"
