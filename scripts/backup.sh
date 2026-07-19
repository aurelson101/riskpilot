#!/bin/sh
set -eu

if [ "$#" -ne 1 ] || [ -z "$1" ] || [ "$1" = "/" ]; then
  echo "Usage: $0 <répertoire-de-sauvegarde>" >&2
  exit 64
fi

backup_root=$1
timestamp=$(date -u +%Y%m%dT%H%M%SZ)
destination="$backup_root/$timestamp"
mkdir -p "$destination"

docker compose exec -T postgres sh -c 'pg_dump --clean --if-exists --no-owner -U "$POSTGRES_USER" "$POSTGRES_DB"' | gzip -9 > "$destination/postgresql.sql.gz"
docker compose exec -T backend sh -c 'if [ -d /app/var/isms-documents ]; then tar -C /app/var/isms-documents -czf - .; else tar -czf - --files-from /dev/null; fi' > "$destination/isms-documents.tar.gz"
docker compose exec -T redis redis-cli --rdb /tmp/riskpilot.rdb >/dev/null
docker compose cp redis:/tmp/riskpilot.rdb "$destination/redis.rdb" >/dev/null
docker compose exec -T redis rm -f /tmp/riskpilot.rdb

(cd "$destination" && sha256sum postgresql.sql.gz isms-documents.tar.gz redis.rdb > SHA256SUMS)
cat > "$destination/metadata.json" <<EOF
{"createdAt":"$(date -u +%Y-%m-%dT%H:%M:%SZ)","formatVersion":1,"contents":["postgresql","isms-documents","redis"]}
EOF

retention_days=${BACKUP_RETENTION_DAYS:-30}
case "$retention_days" in *[!0-9]*|'') echo "BACKUP_RETENTION_DAYS doit être un entier positif." >&2; exit 65;; esac
find "$backup_root" -mindepth 1 -maxdepth 1 -type d -name '????????T??????Z' -mtime "+$retention_days" -exec rm -rf -- {} +
echo "Sauvegarde créée et vérifiée : $destination"
