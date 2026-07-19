# Déploiement

Le fichier `compose.yaml` cible le développement. `compose.prod.yaml` supprime les montages de sources, active `APP_ENV=prod`, utilise les images multi-stage et adapte Nginx/healthcheck au port `80` du frontend construit.

Avant un déploiement, fournir des secrets uniques via le gestionnaire de secrets de la plateforme, générer la paire de clés JWT hors image, terminer TLS, sauvegarder PostgreSQL et Redis, puis exécuter les migrations en tâche contrôlée. `APP_URL` doit être l’origine HTTPS publique exacte : RiskPilot l’utilise pour générer les callbacks OAuth Google Workspace et Microsoft 365.

Docker et les fichiers Compose restent exclusivement en HTTP. `docker/nginx/production-http.conf` sert l’application sur le port publié `8080`. Pour HTTPS, installer un Nginx séparé sur l’hôte ou un reverse proxy et adapter `nginx.conf.example` ; ce fichier relaie vers `127.0.0.1:8080` avec les en-têtes OAuth nécessaires. Le service ponctuel `jwt-init` génère au premier démarrage la paire JWT dans le volume persistant `jwt_keys` ; backend, worker et scheduler la montent ensuite en lecture seule.

```bash
docker compose -f compose.yaml -f compose.prod.yaml up -d --build
```

Le template redirige le port 80 vers 443, active TLS 1.2/1.3 et HSTS, et transmet le contexte HTTPS aux callbacks OAuth. SMTP2GO est une connexion TCP sortante du backend ; Gmail API et Microsoft Graph sont des connexions HTTPS sortantes. Aucun flux email ne doit être publié par Nginx.

Exécuter `doctrine:migrations:migrate --no-interaction` à chaque livraison, avant de rendre la nouvelle version disponible. Ne jamais lancer `doctrine:fixtures:load` en production : cette commande purge la base avant de charger les données de démonstration. Vérifier ensuite `/api/health`, la connexion, le tableau de bord et un export avec un compte de contrôle.
