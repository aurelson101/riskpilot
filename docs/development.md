# Développement

Copier `.env.example` vers `.env`, exécuter `make install`, puis `make start`. Les sources backend et frontend sont montées dans leurs conteneurs. Vite recharge l’interface automatiquement.

Toute évolution doit inclure les tests adaptés, passer `make test` et `make lint`, fournir une migration pour chaque évolution du schéma et préserver l’isolation entre organisations.
