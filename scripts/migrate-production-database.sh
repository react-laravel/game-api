#!/usr/bin/env bash
set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-/var/backups/game-extraction}"
STAMP="$(date +%Y%m%d%H%M%S)"
ARCHIVE="$BACKUP_DIR/next-monopoly-tables-$STAMP.dump"
PASSWORD_FILE="/root/.game-db-password"

sudo install -d -o postgres -g postgres -m 700 "$BACKUP_DIR"

source_table_count="$(sudo -u postgres psql --dbname=next -Atqc "SELECT count(*) FROM pg_tables WHERE schemaname='public' AND tablename LIKE 'monopoly\\_%' ESCAPE '\\'")"
if [ "$source_table_count" -ne 4 ]; then
  echo "expected 4 Monopoly tables in next, found $source_table_count" >&2
  exit 1
fi

if sudo -u postgres psql -Atqc "SELECT 1 FROM pg_database WHERE datname='game'" | grep -q 1; then
  table_count="$(sudo -u postgres psql -d game -Atqc "SELECT count(*) FROM pg_tables WHERE schemaname='public' AND tablename LIKE 'monopoly\\_%' ESCAPE '\\'")"
  if [ "$table_count" -gt 0 ]; then
    echo "game database already contains Monopoly tables; refusing to overwrite it" >&2
    exit 1
  fi
fi

echo "Creating recoverable production snapshot: $ARCHIVE"
sudo -u postgres pg_dump --format=custom --no-owner --dbname=next \
  --table='public.monopoly_*' --file="$ARCHIVE"
sudo chmod 600 "$ARCHIVE"

if ! sudo test -s "$PASSWORD_FILE"; then
  sudo sh -c "umask 077; openssl rand -hex 32 > '$PASSWORD_FILE'"
fi

db_password="$(sudo cat "$PASSWORD_FILE")"
if ! sudo -u postgres psql -Atqc "SELECT 1 FROM pg_roles WHERE rolname='game'" | grep -q 1; then
  sudo -u postgres psql -v ON_ERROR_STOP=1 -c "CREATE ROLE game LOGIN PASSWORD '$db_password'"
else
  sudo -u postgres psql -v ON_ERROR_STOP=1 -c "ALTER ROLE game LOGIN PASSWORD '$db_password'"
fi

if ! sudo -u postgres psql -Atqc "SELECT 1 FROM pg_database WHERE datname='game'" | grep -q 1; then
  sudo -u postgres createdb --owner=game game
fi

echo "Restoring Monopoly tables into the independent game database"
sudo -u postgres pg_restore --exit-on-error --no-owner --role=game --dbname=game "$ARCHIVE"

sudo -u postgres psql -v ON_ERROR_STOP=1 --dbname=game <<'SQL'
CREATE TABLE IF NOT EXISTS migrations (
    id bigserial PRIMARY KEY,
    migration varchar(255) NOT NULL,
    batch integer NOT NULL
);
ALTER TABLE migrations OWNER TO game;
ALTER SEQUENCE migrations_id_seq OWNER TO game;
GRANT USAGE, CREATE ON SCHEMA public TO game;

INSERT INTO migrations (migration, batch)
SELECT migration, 1
FROM (VALUES
    ('2026_07_07_000001_create_monopoly_tables'),
    ('2026_07_08_000002_add_houses_built_this_turn_to_monopoly_players')
) AS expected(migration)
WHERE NOT EXISTS (
    SELECT 1 FROM migrations WHERE migrations.migration = expected.migration
);
SQL

echo "Validating restored table inventory"
tables="$(sudo -u postgres psql --dbname=next -Atqc "SELECT tablename FROM pg_tables WHERE schemaname='public' AND tablename LIKE 'monopoly\\_%' ESCAPE '\\' ORDER BY tablename")"
while IFS= read -r table; do
  [ -n "$table" ] || continue
  source_count="$(sudo -u postgres psql --dbname=next -Atqc "SELECT count(*) FROM $table")"
  target_count="$(sudo -u postgres psql --dbname=game -Atqc "SELECT count(*) FROM $table")"
  echo "$table source=$source_count target=$target_count"
  [ "$source_count" = "$target_count" ] || {
    echo "row count mismatch for $table" >&2
    exit 1
  }

  source_hash="$(sudo -u postgres psql --dbname=next -Atqc "COPY (SELECT * FROM public.\"$table\" ORDER BY id) TO STDOUT" | sha256sum | cut -d' ' -f1)"
  target_hash="$(sudo -u postgres psql --dbname=game -Atqc "COPY (SELECT * FROM public.\"$table\" ORDER BY id) TO STDOUT" | sha256sum | cut -d' ' -f1)"
  echo "$table sha256 source=$source_hash target=$target_hash"
  [ "$source_hash" = "$target_hash" ] || {
    echo "content hash mismatch for $table" >&2
    exit 1
  }
done <<< "$tables"

echo "Snapshot retained at $ARCHIVE"
