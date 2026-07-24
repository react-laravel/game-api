#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="${APP_ROOT:-/var/www/game-api}"
WORKSPACE="${GITHUB_WORKSPACE:-$(pwd -P)}"
RELEASES="$APP_ROOT/releases"
SHARED="$APP_ROOT/shared"
CURRENT="$APP_ROOT/current"
STAMP="$(date +%Y%m%d%H%M%S)"
STAGING="$RELEASES/.staging-$STAMP-$$"
RELEASE="$RELEASES/$STAMP"
PREVIOUS="$(readlink -f "$CURRENT" 2>/dev/null || true)"

mkdir -p "$RELEASES" "$SHARED/storage/framework/cache/data" \
  "$SHARED/storage/framework/sessions" "$SHARED/storage/framework/views" \
  "$SHARED/storage/logs" "$SHARED/storage/app/public"
test -s "$SHARED/.env"

cleanup() { rm -rf "$STAGING"; }
trap cleanup EXIT

rsync -a --delete \
  --exclude='.git' --exclude='.env' --exclude='vendor' --exclude='storage' \
  "$WORKSPACE/" "$STAGING/"

cd "$STAGING"
composer install --prefer-dist --no-interaction --optimize-autoloader
rm -rf storage
ln -s "$SHARED/storage" storage
ln -s "$SHARED/.env" .env
mkdir -p bootstrap/cache
chmod -R ug+rwX bootstrap/cache "$SHARED/storage"

vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
php artisan test --no-coverage
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

php artisan config:cache
php artisan view:cache
php artisan migrate --force

mv "$STAGING" "$RELEASE"
ln -sfn "$RELEASE" "$CURRENT"

php "$CURRENT/artisan" queue:restart || true
sudo -n supervisorctl reread
sudo -n supervisorctl update
sudo -n supervisorctl restart game-api:*

rollback_release() {
  if [ -n "$PREVIOUS" ] && [ -d "$PREVIOUS" ]; then
    ln -sfn "$PREVIOUS" "$CURRENT"
    sudo -n supervisorctl restart game-api:*
  fi
}

if ! curl -kfsS --max-time 10 --resolve game-api.dogeow.com:443:127.0.0.1 \
  https://game-api.dogeow.com/up >/dev/null; then
  echo "Game API health endpoint failed" >&2
  rollback_release
  exit 1
fi

user_status="$(curl -ksS --max-time 10 --resolve game-api.dogeow.com:443:127.0.0.1 \
  --output /dev/null --write-out '%{http_code}' https://game-api.dogeow.com/api/user || true)"
if [ "$user_status" != "401" ]; then
  echo "Game API authentication probe returned HTTP $user_status instead of 401" >&2
  rollback_release
  exit 1
fi

if ! ss -ltnH 'sport = :8082' | grep -q .; then
  echo "Game API Reverb is not listening on port 8082" >&2
  rollback_release
  exit 1
fi

find "$RELEASES" -mindepth 1 -maxdepth 1 -type d -name '20*' -printf '%T@ %p\n' \
  | sort -nr | tail -n +2 | cut -d' ' -f2- | xargs -r rm -rf
