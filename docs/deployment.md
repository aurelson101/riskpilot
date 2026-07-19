# Déploiement

Le fichier `compose.yaml` cible le développement. `compose.prod.yaml` supprime les montages de sources, active `APP_ENV=prod`, utilise les images multi-stage et adapte Nginx/healthcheck au port `80` du frontend construit.

Avant un déploiement, fournir des secrets uniques via le gestionnaire de secrets de la plateforme, générer la paire de clés JWT hors image, terminer TLS, sauvegarder PostgreSQL et Redis, puis exécuter les migrations en tâche contrôlée. `APP_URL` doit être l’origine HTTPS publique exacte : RiskPilot l’utilise pour générer les callbacks OAuth Google Workspace et Microsoft 365.

`docker/nginx/production-http.conf` convient derrière un load balancer TLS. Pour une terminaison TLS dans RiskPilot, copier `compose.https.yaml.example` en `compose.https.yaml`, adapter le domaine dans `docker/nginx/https.conf.template`, monter les certificats puis démarrer avec :

```bash
docker compose -f compose.yaml -f compose.prod.yaml -f compose.https.yaml up -d --build
```

Le template redirige le port 80 vers 443, active TLS 1.2/1.3 et HSTS, et transmet le contexte HTTPS aux callbacks OAuth. SMTP2GO est une connexion TCP sortante du backend ; Gmail API et Microsoft Graph sont des connexions HTTPS sortantes. Aucun flux email ne doit être publié par Nginx.

Exécuter `doctrine:migrations:migrate --no-interaction` à chaque livraison, avant de rendre la nouvelle version disponible. Ne jamais lancer `doctrine:fixtures:load` en production : cette commande purge la base avant de charger les données de démonstration. Vérifier ensuite `/api/health`, la connexion, le tableau de bord et un export avec un compte de contrôle.
