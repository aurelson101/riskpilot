# API

L’API REST est servie sous `/api` et sa documentation OpenAPI sous `/docs`. Les réponses d’erreur métier utiliseront un code stable, un message lisible et un objet `errors` pour les violations de validation.

Le point `GET /api/health` permet de vérifier le service sans authentification. Les ressources métier, filtres, pagination et permissions seront ajoutés dans les étapes suivantes.

## Authentification

- `POST /api/auth/login` : échange email/mot de passe contre un JWT de 15 minutes.
- `GET /api/me` : profil de l’utilisateur courant.
- `PUT /api/me/password` : changement du mot de passe courant.

## Administration

- `GET|POST /api/users` et `GET|PUT /api/users/{id}`.
- `GET|POST /api/organizations` et `GET|PUT /api/organizations/{id}`.

Les endpoints utilisateurs appliquent le tenant de l’utilisateur authentifié au niveau du repository. Une ressource d’une autre organisation est renvoyée comme inexistante.
