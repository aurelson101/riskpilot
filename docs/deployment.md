# Déploiement

Le fichier `compose.yaml` cible le développement. `compose.prod.yaml` supprime les montages de sources, active `APP_ENV=prod` et utilise les images de production multi-stage.

Avant un déploiement, fournir des secrets uniques via le gestionnaire de secrets de la plateforme, générer la paire de clés JWT hors image, terminer TLS devant Nginx, sauvegarder PostgreSQL et Redis, puis exécuter les migrations en tâche contrôlée.

Exécuter `doctrine:migrations:migrate --no-interaction` à chaque livraison, avant de rendre la nouvelle version disponible. Ne jamais lancer `doctrine:fixtures:load` en production : cette commande purge la base avant de charger les données de démonstration. Vérifier ensuite `/api/health`, la connexion, le tableau de bord et un export avec un compte de contrôle.
