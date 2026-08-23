#!/bin/sh
set -eu

env_file=${1:-.env}
public_url=${2:-}
failed=0
compose_files=${RISKPILOT_COMPOSE_FILES:--f compose.yaml -f compose.prod.yaml}
compose_project=${RISKPILOT_COMPOSE_PROJECT:-riskpilot}

compose() {
  # La liste provient de l'exploitant et contient uniquement les options -f attendues.
  docker compose -p "$compose_project" $compose_files "$@"
}

check() {
  label=$1
  shift
  if "$@"; then printf 'OK   %s\n' "$label"; else printf 'FAIL %s\n' "$label" >&2; failed=1; fi
}

check "secrets et configuration production" ./scripts/check-production-env.sh "$env_file"
check "configuration Compose" compose config -q
check "migrations Doctrine cohérentes" compose run --rm backend php bin/console doctrine:migrations:up-to-date

if [ -n "$public_url" ]; then
  check "healthcheck public" curl -fsS --max-time 10 "$public_url/api/health"
  headers=$(mktemp)
  trap 'rm -f -- "$headers"' EXIT HUP INT TERM
  if curl -fsSI --max-time 10 "$public_url/" > "$headers"; then
    check "HSTS" grep -Eqi '^strict-transport-security:' "$headers"
    check "CSP" grep -Eqi '^content-security-policy:' "$headers"
    check "anti-MIME sniffing" grep -Eqi '^x-content-type-options:[[:space:]]*nosniff' "$headers"
  else
    echo "FAIL en-têtes HTTP publics" >&2; failed=1
  fi
fi

[ "$failed" -eq 0 ] || exit 70
echo "Readiness production validée. Les preuves de restauration et de charge restent à joindre à la livraison."
