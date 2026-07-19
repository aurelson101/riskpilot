# Développement

Copier `.env.example` vers `.env`, exécuter `make install`, puis `make start`. Les sources backend et frontend sont montées dans leurs conteneurs. Vite recharge l’interface automatiquement.

Toute évolution doit inclure les tests adaptés, passer `make test` et `make lint`, fournir une migration pour chaque évolution du schéma et préserver l’isolation entre organisations.

`make fixtures` purge la base locale et recharge un jeu cohérent couvrant tous les modules. Le compte principal est `admin@riskpilot.local` avec le mot de passe de développement `ChangeMe123!`. Après une modification des dépendances ou des images, reconstruire avec `docker compose build` puis recréer les services avec `docker compose up -d --force-recreate`.
