# Déploiement

Le fichier `compose.yaml` cible le développement. `compose.prod.yaml` supprime les montages de sources, active `APP_ENV=prod` et utilise les images de production multi-stage.

Avant un déploiement, fournir des secrets uniques via le gestionnaire de secrets de la plateforme, générer la paire de clés JWT hors image, terminer TLS devant Nginx, sauvegarder PostgreSQL et Redis, puis exécuter les migrations en tâche contrôlée.
