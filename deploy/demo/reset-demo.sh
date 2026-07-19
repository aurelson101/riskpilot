#!/bin/sh

set -eu

project_dir=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$project_dir"

compose_files="-f compose.yaml -f compose.prod.yaml -f deploy/demo/compose.demo.yaml"

docker compose $compose_files config -q
docker compose $compose_files run --rm --no-deps demo-reset-scheduler /bin/sh -c '
    set -eu
    find /app/var/isms-documents -mindepth 1 -delete
    php bin/console doctrine:migrations:migrate --no-interaction
    php bin/console doctrine:fixtures:load --no-interaction --purge-with-truncate
    php -r '\''$redis = new Redis(); $redis->connect("redis", 6379); $redis->flushDB();'\''
'

status=$(curl --silent --output /dev/null --write-out '%{http_code}' http://127.0.0.1:18081/api/health || true)
if [ "$status" != "200" ]; then
    echo "Reset effectué, mais le contrôle HTTP retourne $status." >&2
    exit 1
fi

echo "Démonstration réinitialisée et disponible (HTTP 200)."
