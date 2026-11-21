#!/bin/sh
# Recette SAML/SCIM : Keycloak réel + RiskPilot isolé (aucun contact avec la démo).
set -e
cd ~/rp-dev
docker network inspect rpdev-net >/dev/null 2>&1 || docker network create rpdev-net >/dev/null
docker start rpdev-pg rpdev-redis >/dev/null 2>&1 || true
docker rm -f rpdev-kc rpdev-app >/dev/null 2>&1 || true
docker run -d --name rpdev-kc --network rpdev-net --memory 700m \
  -e KC_BOOTSTRAP_ADMIN_USERNAME=admin -e KC_BOOTSTRAP_ADMIN_PASSWORD=Recette-Admin-1 -e KC_HTTP_ENABLED=true -e KC_HOSTNAME=https://keycloak.recette.test -e KC_PROXY_HEADERS=xforwarded -e KC_HOSTNAME_STRICT=false \
  -e JAVA_OPTS_KC_HEAP="-Xms128m -Xmx400m" \
  -v $HOME/rp-dev/recette/realm.json:/opt/keycloak/data/import/realm.json:ro \
  quay.io/keycloak/keycloak:26.0 start-dev --import-realm >/dev/null
# base RiskPilot dédiée
docker exec rpdev-pg psql -U app -d postgres -c "DROP DATABASE IF EXISTS app_recette" -c "CREATE DATABASE app_recette" >/dev/null
ENVS="--env-file $HOME/rp-dev/test.env -e APP_URL=https://rp.recette.test -e DEFAULT_URI=https://rp.recette.test -e APP_ENV=prod -e APP_DEBUG=0 -e DATABASE_URL=postgresql://app:app@rpdev-pg:5432/app_recette?serverVersion=17&charset=utf8"
RUN="docker run --rm --network rpdev-net --memory 500m -u $(id -u):$(id -g) -v $HOME/rp-dev/backend:/app -w /app $ENVS --entrypoint php riskpilot-backend:b27ef6693a04"
$RUN bin/console cache:clear --no-warmup -q
$RUN bin/console doctrine:migrations:migrate -n -q
$RUN bin/console app:user:create-admin "Recette Org" admin@riskpilot.test "Recette-Admin-12345" Root Admin -q
docker run -d --name rpdev-app --network rpdev-net --memory 400m -u $(id -u):$(id -g) -v $HOME/rp-dev/backend:/app -w /app $ENVS --entrypoint php riskpilot-backend:b27ef6693a04 -S 0.0.0.0:8080 -t public public/index.php >/dev/null
echo "attente Keycloak…"
for i in $(seq 1 60); do
  if docker exec rpdev-kc sh -c 'exec 3<>/dev/tcp/127.0.0.1/8080 && printf "GET /realms/riskpilot HTTP/1.0\r\n\r\n" >&3 && head -1 <&3' 2>/dev/null | grep -q "200"; then echo "Keycloak prêt"; exit 0; fi
  sleep 5
done
echo "Keycloak non prêt"; docker logs --tail 20 rpdev-kc; exit 1
