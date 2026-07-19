# Démonstration `demo.aurelson.com`

Cette superposition démarre une instance isolée sous le nom Docker `riskpilot_demo`, accessible localement sur `127.0.0.1:18081`. Les données PostgreSQL, Redis, JWT et documentaires ne sont jamais partagées avec une autre installation RiskPilot.

## Premier démarrage

Depuis la racine du dépôt :

```bash
cp .env.example .env
# Remplacer tous les secrets et définir APP_URL=https://demo.aurelson.com
docker compose -f compose.yaml -f compose.prod.yaml -f deploy/demo/compose.demo.yaml build
docker compose -f compose.yaml -f compose.prod.yaml -f deploy/demo/compose.demo.yaml run --rm backend php bin/console doctrine:migrations:migrate --no-interaction
docker compose -f compose.yaml -f compose.prod.yaml -f deploy/demo/compose.demo.yaml up -d
deploy/demo/reset-demo.sh
```

Identifiants rechargés à chaque reset : `admin@riskpilot.local` / `ChangeMe123!`.

## Reset automatique

Le service `demo-reset-scheduler` attend deux heures, puis recharge les fixtures, vide Redis et supprime les fichiers documentaires déposés pendant la démonstration. Il recommence toutes les deux heures et redémarre avec le projet. Le reset manuel utilise exactement le même périmètre via `deploy/demo/reset-demo.sh`.

## HTTPS

Docker reste exclusivement en HTTP et n’écoute que sur la boucle locale. Installer `deploy/demo/nginx.demo.conf` sur le reverse proxy hôte, obtenir le certificat Let's Encrypt de `demo.aurelson.com`, puis vérifier la configuration avec `nginx -t` avant rechargement.
