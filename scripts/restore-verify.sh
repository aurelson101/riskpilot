#!/bin/sh
set -eu

if [ "$#" -ne 1 ] || [ ! -d "$1" ]; then
  echo "Usage: $0 <sauvegarde>" >&2
  exit 64
fi

backup_directory=$1
(cd "$backup_directory" && sha256sum -c SHA256SUMS)
gzip -t "$backup_directory/postgresql.sql.gz"
tar -tzf "$backup_directory/isms-documents.tar.gz" >/dev/null
test -s "$backup_directory/redis.rdb"

postgres_image=${POSTGRES_RESTORE_IMAGE:-postgres:17-alpine}
redis_image=${REDIS_RESTORE_IMAGE:-redis:7.4-alpine}
restore_container="riskpilot-restore-verify-$$"

cleanup() {
  docker rm -f "$restore_container" >/dev/null 2>&1 || true
}
trap cleanup EXIT HUP INT TERM

docker run -d --name "$restore_container" \
  -e POSTGRES_DB=riskpilot_restore \
  -e POSTGRES_USER=riskpilot_restore \
  -e POSTGRES_PASSWORD=restore-verification-only \
  "$postgres_image" >/dev/null

attempt=0
until docker exec "$restore_container" pg_isready -U riskpilot_restore -d riskpilot_restore >/dev/null 2>&1; do
  attempt=$((attempt + 1))
  if [ "$attempt" -ge 30 ]; then
    echo "PostgreSQL de vérification n'est pas devenu disponible." >&2
    exit 70
  fi
  sleep 1
done

gzip -dc "$backup_directory/postgresql.sql.gz" \
  | docker exec -i "$restore_container" psql -v ON_ERROR_STOP=1 -U riskpilot_restore -d riskpilot_restore >/dev/null

restored_tables=$(docker exec "$restore_container" psql -At -U riskpilot_restore -d riskpilot_restore \
  -c "SELECT count(*) FROM pg_catalog.pg_tables WHERE schemaname = 'public';")
case "$restored_tables" in
  ''|*[!0-9]*) echo "Nombre de tables restaurées invalide." >&2; exit 71 ;;
  0) echo "La restauration PostgreSQL ne contient aucune table applicative." >&2; exit 71 ;;
esac

docker run --rm -v "$backup_directory:/backup:ro" "$redis_image" \
  redis-check-rdb /backup/redis.rdb >/dev/null

echo "Restauration isolée validée : $restored_tables tables PostgreSQL, archive documentaire et RDB Redis valides."
