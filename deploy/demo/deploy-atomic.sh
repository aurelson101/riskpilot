#!/bin/sh
set -eu

cd "$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)"
compose="docker compose -p riskpilot_demo -f compose.yaml -f compose.prod.yaml -f deploy/demo/compose.demo.yaml -f deploy/demo/compose.vps-1gb.yaml -f deploy/demo/compose.sequential.yaml"
release=${1:-$(git rev-parse --short=12 HEAD)}
backend_candidate="riskpilot-backend:$release"
frontend_candidate="riskpilot-frontend:$release"
reset_candidate="riskpilot-demo-reset:$release"
state=$(mktemp -d)
cleanup() { rm -rf -- "$state"; }
trap cleanup EXIT HUP INT TERM

for service in backend frontend demo-reset-scheduler; do
  container="riskpilot_demo-${service}-1"
  docker inspect -f '{{.Config.Image}}' "$container" > "$state/$service" 2>/dev/null || :
done

rollback() {
  echo "Échec de livraison : retour aux images précédentes." >&2
  [ -s "$state/backend" ] && [ -s "$state/frontend" ] && [ -s "$state/demo-reset-scheduler" ] || {
    echo "Images précédentes incomplètes ; rollback automatique impossible." >&2
    return 1
  }
  RISKPILOT_BACKEND_IMAGE=$(cat "$state/backend") \
  RISKPILOT_FRONTEND_IMAGE=$(cat "$state/frontend") \
  RISKPILOT_DEMO_RESET_IMAGE=$(cat "$state/demo-reset-scheduler") \
    deploy/demo/start-sequential.sh || true
}
trap 'rollback' INT TERM HUP

RISKPILOT_BACKEND_IMAGE=$backend_candidate $compose build backend
RISKPILOT_FRONTEND_IMAGE=$frontend_candidate $compose build frontend
RISKPILOT_DEMO_RESET_IMAGE=$reset_candidate $compose build demo-reset-scheduler

# Les migrations doivent être rétrocompatibles avec la version précédente.
RISKPILOT_BACKEND_IMAGE=$backend_candidate $compose run --rm backend php bin/console doctrine:migrations:migrate --no-interaction
if ! RISKPILOT_BACKEND_IMAGE=$backend_candidate RISKPILOT_FRONTEND_IMAGE=$frontend_candidate RISKPILOT_DEMO_RESET_IMAGE=$reset_candidate \
  deploy/demo/start-sequential.sh; then
  rollback
  exit 70
fi
curl -fsS --max-time 10 http://127.0.0.1:18081/api/health >/dev/null || { rollback; exit 71; }
printf '%s\n' "$release" > .deployed-release
echo "Livraison atomique active : $release"
