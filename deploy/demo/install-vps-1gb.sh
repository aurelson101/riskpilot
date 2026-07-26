#!/bin/sh

set -eu

project_dir=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$project_dir"

compose_files="-f compose.yaml -f compose.prod.yaml -f deploy/demo/compose.demo.yaml -f deploy/demo/compose.vps-1gb.yaml"
export COMPOSE_PARALLEL_LIMIT=1
export BUILDKIT_PROGRESS=plain
export PHP_BUILD_JOBS=1

docker compose $compose_files config -q

# Construire une seule fois l'image PHP de production. Worker, scheduler et
# jwt-init utilisent ensuite exactement cette même image.
docker compose $compose_files build backend
docker compose $compose_files build frontend
docker compose $compose_files build demo-reset-scheduler

docker compose $compose_files run --rm backend php bin/console doctrine:migrations:migrate --no-interaction
docker compose $compose_files up -d
# Nginx resolves Compose service names when it starts. Recreate it after the
# backend/frontend so its upstream addresses cannot point to replaced containers.
docker compose $compose_files up -d --force-recreate nginx

docker compose $compose_files ps
