#!/usr/bin/env bash
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
  echo "run this script as root" >&2
  exit 1
fi

SOURCE_ROOT="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)}"
DB_PASSWORD_FILE=/root/.game-db-password
SECRET_DIR=/root/.game-secrets
API_ROOT=/var/www/game-api
FRONTEND_ROOT=/var/www/game
INSTALL_FRONTEND_NGINX="${INSTALL_FRONTEND_NGINX:-1}"

install -d -m 700 "$SECRET_DIR"

generate_secret() {
  local file="$1"
  local bytes="$2"
  if [ ! -s "$file" ]; then
    openssl rand -hex "$bytes" > "$file"
    chmod 600 "$file"
  fi
}

generate_secret "$DB_PASSWORD_FILE" 32
if [ ! -s "$SECRET_DIR/app-key" ]; then
  openssl rand 32 > "$SECRET_DIR/app-key"
  chmod 600 "$SECRET_DIR/app-key"
fi
generate_secret "$SECRET_DIR/sso-client-secret" 32
generate_secret "$SECRET_DIR/reverb-app-id" 8
generate_secret "$SECRET_DIR/reverb-app-key" 16
generate_secret "$SECRET_DIR/reverb-app-secret" 32

db_password="$(cat "$DB_PASSWORD_FILE")"
app_key="base64:$(openssl base64 -A < "$SECRET_DIR/app-key")"
sso_secret="$(cat "$SECRET_DIR/sso-client-secret")"
reverb_id="$(cat "$SECRET_DIR/reverb-app-id")"
reverb_key="$(cat "$SECRET_DIR/reverb-app-key")"
reverb_secret="$(cat "$SECRET_DIR/reverb-app-secret")"

install -d -o www-data -g www-data -m 775 "$API_ROOT/shared" "$API_ROOT/releases"
install -d -o www-data -g www-data -m 775 "$FRONTEND_ROOT/shared" "$FRONTEND_ROOT/releases"

cat > "$API_ROOT/shared/.env" <<EOF
APP_NAME="DogeOW Game API"
APP_ENV=production
APP_KEY=$app_key
APP_DEBUG=false
APP_URL=https://game.dogeow.com
FRONTEND_URL=https://game.dogeow.com
APP_LOCALE=zh_CN
APP_FALLBACK_LOCALE=zh_CN
LOG_CHANNEL=stack
LOG_LEVEL=info

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=game
DB_USERNAME=game
DB_PASSWORD=$db_password

SESSION_DRIVER=redis
SESSION_CONNECTION=session
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_COOKIE=game_session
SESSION_PATH=/
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax

CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_QUEUE=game
BROADCAST_CONNECTION=reverb
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_DB=11
REDIS_CACHE_DB=12
REDIS_SESSION_DB=13
REDIS_PREFIX=game:

IDENTITY_API_URL=https://next-api.dogeow.com
IDENTITY_SSO_CLIENT_SECRET=$sso_secret

REVERB_APP_ID=$reverb_id
REVERB_APP_KEY=$reverb_key
REVERB_APP_SECRET=$reverb_secret
REVERB_HOST=127.0.0.1
REVERB_PORT=8082
REVERB_SCHEME=http
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8082
EOF

cat > "$FRONTEND_ROOT/.env.production" <<EOF
GAME_API_ORIGIN=http://127.0.0.1:8002
NEXT_PUBLIC_ACCOUNT_URL=https://next.dogeow.com
NEXT_PUBLIC_REVERB_APP_KEY=$reverb_key
NEXT_PUBLIC_REVERB_HOST=game.dogeow.com
NEXT_PUBLIC_REVERB_PORT=443
NEXT_PUBLIC_REVERB_SCHEME=https
EOF

chown www-data:www-data "$API_ROOT/shared/.env" "$FRONTEND_ROOT/.env.production"
chmod 640 "$API_ROOT/shared/.env" "$FRONTEND_ROOT/.env.production"

set_env() {
  local file="$1"
  local key="$2"
  local value="$3"
  if grep -q "^${key}=" "$file"; then
    sed -i "s|^${key}=.*|${key}=${value}|" "$file"
  else
    printf '\n%s=%s\n' "$key" "$value" >> "$file"
  fi
}

central_envs=()
for candidate in \
  "${CENTRAL_ENV:-}" \
  /var/www/dogeow-api/.env \
  /var/www/dogeow-api/shared/.env \
  /var/www/dogeow-api/current/.env; do
  [ -n "$candidate" ] && [ -f "$candidate" ] || continue
  canonical="$(readlink -f "$candidate")"
  already_added=false
  for existing in "${central_envs[@]:-}"; do
    if [ "$existing" = "$canonical" ]; then
      already_added=true
      break
    fi
  done
  if [ "$already_added" = false ]; then
    central_envs+=("$canonical")
  fi
done

if [ "${#central_envs[@]}" -eq 0 ]; then
  echo "cannot find the central dogeow-api .env" >&2
  exit 1
fi

for central_env in "${central_envs[@]}"; do
  set_env "$central_env" GAME_SSO_CLIENT_SECRET "$sso_secret"
  set_env "$central_env" GAME_SSO_CALLBACK_URL "https://game.dogeow.com/auth/callback"
  set_env "$central_env" GAME_SSO_RETURN_ORIGINS "https://game.dogeow.com,http://localhost:3002,http://127.0.0.1:3002"
  set_env "$central_env" SSO_TICKET_LIFETIME_SECONDS 60
done

if [ "$INSTALL_FRONTEND_NGINX" = "1" ]; then
  install -o root -g root -m 644 "$SOURCE_ROOT/deploy/nginx-game.conf" /etc/nginx/conf.d/game.dogeow.com.conf
else
  echo "Deferring game.dogeow.com Nginx cutover until the new frontend is healthy"
fi
install -o root -g root -m 644 "$SOURCE_ROOT/deploy/nginx-game-api.conf" /etc/nginx/conf.d/game-api.dogeow.com.conf
install -o root -g root -m 644 "$SOURCE_ROOT/deploy/supervisor-game-api.conf" /etc/supervisor/conf.d/game-api.conf
install -o root -g root -m 644 "$SOURCE_ROOT/deploy/game-api.cron" /etc/cron.d/game-api
touch /var/log/game-api-scheduler.log
chown www-data:www-data /var/log/game-api-scheduler.log

nginx -t
systemctl reload nginx

echo "Game production infrastructure prepared"
