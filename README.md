# DogeOW Game API

Independent Laravel API for DogeOW games. The first migrated domain is Monopoly.

## Boundaries

- One application database: `game`.
- Central DogeOW accounts are received through a short-lived SSO ticket and kept only as an encrypted session identity snapshot.
- No SQL connection or foreign key points to the central account database.
- Monopoly room updates use authenticated private Reverb channels.

## Local verification

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan test
vendor/bin/pint --test
vendor/bin/phpstan analyse
```

## Production cutover

For a no-downtime first installation, run provisioning with
`INSTALL_FRONTEND_NGINX=0`, deploy both services, verify ports 8002, 8082 and
3011, then install `deploy/nginx-game.conf` and reload Nginx for the final
cutover. The database migration validates both row counts and deterministic
SHA-256 content hashes before the old database can be retired.
