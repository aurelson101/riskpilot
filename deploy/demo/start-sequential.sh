#!/bin/sh
set -eu

cd /home/son/demo.riskpilot.site
compose="docker compose -p riskpilot_demo -f compose.yaml -f compose.prod.yaml -f deploy/demo/compose.demo.yaml -f deploy/demo/compose.vps-1gb.yaml -f deploy/demo/compose.sequential.yaml"

wait_healthy() {
    container="$1"
    attempts=0
    until [ "$(docker inspect -f '{{.State.Health.Status}}' "$container" 2>/dev/null || true)" = "healthy" ]; do
        attempts=$((attempts + 1))
        if [ "$attempts" -ge 60 ]; then
            docker logs --tail 80 "$container" || true
            echo "$container did not become healthy" >&2
            exit 1
        fi
        sleep 2
    done
}

$compose up -d --no-build --no-deps postgres redis
wait_healthy riskpilot_demo-postgres-1
wait_healthy riskpilot_demo-redis-1

$compose up -d --no-build --no-deps jwt-init
attempts=0
until [ "$(docker inspect -f '{{.State.Status}}' riskpilot_demo-jwt-init-1 2>/dev/null || true)" = "exited" ]; do
    attempts=$((attempts + 1))
    [ "$attempts" -lt 30 ] || exit 1
    sleep 1
done
[ "$(docker inspect -f '{{.State.ExitCode}}' riskpilot_demo-jwt-init-1)" = "0" ]

$compose up -d --no-build --force-recreate --no-deps backend frontend
wait_healthy riskpilot_demo-backend-1
wait_healthy riskpilot_demo-frontend-1

# Nginx résout les noms Docker au démarrage. Le recréer après le backend évite
# qu'il conserve l'ancienne adresse du conteneur et serve temporairement des 502.
$compose up -d --no-build --force-recreate --no-deps nginx
$compose up -d --no-build --no-deps worker scheduler demo-reset-scheduler
wait_healthy riskpilot_demo-worker-1
wait_healthy riskpilot_demo-scheduler-1
wait_healthy riskpilot_demo-demo-reset-scheduler-1

attempts=0
until curl -fsS http://127.0.0.1:18081/api/health >/dev/null; do
    attempts=$((attempts + 1))
    if [ "$attempts" -ge 30 ]; then
        $compose logs --tail 80 nginx backend || true
        echo "RiskPilot API did not become reachable through Nginx" >&2
        exit 1
    fi
    sleep 2
done
