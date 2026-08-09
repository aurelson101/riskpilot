# Déploiement

Le fichier `compose.yaml` cible le développement. `compose.prod.yaml` supprime les montages de sources, active `APP_ENV=prod`, utilise les images multi-stage et adapte Nginx/healthcheck au port `80` du frontend construit.

Avant un déploiement, fournir des secrets uniques via le gestionnaire de secrets de la plateforme, générer la paire de clés JWT hors image, terminer TLS, sauvegarder PostgreSQL et Redis, puis exécuter les migrations en tâche contrôlée. `APP_URL` doit être l’origine HTTPS publique exacte : RiskPilot l’utilise pour générer les callbacks OAuth Google Workspace et Microsoft 365.

Docker et les fichiers Compose restent exclusivement en HTTP. `docker/nginx/production-http.conf` sert l’application sur le port publié `8080`. Pour HTTPS, installer un Nginx séparé sur l’hôte ou un reverse proxy et adapter `nginx.conf.example` ; ce fichier relaie vers `127.0.0.1:8080` avec les en-têtes OAuth nécessaires. Le service ponctuel `jwt-init` génère au premier démarrage la paire JWT dans le volume persistant `jwt_keys` ; backend, worker et scheduler la montent ensuite en lecture seule.

```bash
docker compose -f compose.yaml -f compose.prod.yaml up -d --build
```

Le template redirige le port 80 vers 443, active TLS 1.2/1.3 et HSTS, et transmet le contexte HTTPS aux callbacks OAuth. SMTP2GO est une connexion TCP sortante du backend ; Gmail API et Microsoft Graph sont des connexions HTTPS sortantes. Aucun flux email ne doit être publié par Nginx.

Une instance publique réinitialisée toutes les deux heures est fournie dans `deploy/demo`. Elle utilise un nom de projet et des volumes dédiés, expose uniquement `127.0.0.1:18081` et conserve la terminaison TLS sur le Nginx externe.

Exécuter `doctrine:migrations:migrate --no-interaction` à chaque livraison, avant de rendre la nouvelle version disponible. Ne jamais lancer `doctrine:fixtures:load` en production : cette commande purge la base avant de charger les données de démonstration. Vérifier ensuite `/api/health`, la connexion, le tableau de bord et un export avec un compte de contrôle.

## Contrôles d'exploitation

Avant toute mise en production, contrôler le fichier de secrets sans en afficher le contenu :

```bash
./scripts/check-production-env.sh .env
```

Créer une sauvegarde quotidienne sur un stockage chiffré distinct de l'hôte, puis exécuter régulièrement un test de restauration réel et isolé :

```bash
BACKUP_RETENTION_DAYS=30 ./scripts/backup.sh /srv/backups/riskpilot
./scripts/restore-verify.sh /srv/backups/riskpilot/<horodatage>
```

Le test démarre un PostgreSQL éphémère non exposé, importe entièrement le dump avec arrêt sur la première erreur, vérifie qu'il contient des tables et analyse aussi l'archive documentaire et le fichier Redis. Le conteneur de contrôle est toujours supprimé. Une sauvegarde n'est considérée exploitable qu'après réussite de ce test.

Après déploiement, exécuter le contrôle de capacité minimal depuis une machine extérieure :

```bash
LOAD_REQUESTS=100 LOAD_CONCURRENCY=10 LOAD_MAX_P95_MS=750 \
  ./scripts/load-smoke.sh https://riskpilot.example.com
```

Ce smoke test ne remplace pas une campagne métier authentifiée, mais bloque une livraison si `/api/health` retourne une erreur ou dépasse le p95 convenu. Superviser en continu `/api/health`, les réponses 5xx, la latence p95, l'espace disque, PostgreSQL, Redis et la profondeur de la file Messenger. Définir une alerte avant saturation et tester au moins annuellement la restauration, la rotation des secrets, le PRA et le retour à l'image précédente.
