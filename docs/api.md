# API

L’API REST est servie sous `/api` et sa documentation OpenAPI sous `/docs`. Les réponses d’erreur métier utiliseront un code stable, un message lisible et un objet `errors` pour les violations de validation.

Le point `GET /api/health` permet de vérifier le service sans authentification. Les ressources métier sont protégées par JWT et isolées par organisation.

## Authentification

- `POST /api/auth/login` : échange email/mot de passe contre un JWT de 15 minutes.
- `GET /api/me` : profil de l’utilisateur courant.
- `PUT /api/me` : modification du prénom, du nom et de l’adresse email du profil courant.
- `PUT /api/me/password` : changement du mot de passe courant.

## Administration

- `GET|POST /api/users` et `GET|PUT /api/users/{id}`.
- `GET|POST /api/organizations` et `GET|PUT /api/organizations/{id}`.
- `GET /api/audit-logs` : 500 dernières mutations visibles par l’administrateur.

Les endpoints utilisateurs appliquent le tenant de l’utilisateur authentifié au niveau du repository. Une ressource d’une autre organisation est renvoyée comme inexistante.

## Inventaire et contexte de risque

- `GET|POST /api/scopes` et `GET|PUT /api/scopes/{id}` ;
- `GET|POST /api/assets` et `GET|PUT /api/assets/{id}` ;
- `GET|POST /api/threats` et `GET|PUT /api/threats/{id}` ;
- `GET|POST /api/vulnerabilities` et `GET|PUT /api/vulnerabilities/{id}`.

La lecture est ouverte aux utilisateurs authentifiés. Les mutations exigent le rôle Risk Manager ou un rôle supérieur. Les relations vers un parent, un responsable, un périmètre ou un actif sont résolues exclusivement dans l’organisation courante.

## Plans d’action et notifications

- `GET|POST /api/actions` et `GET|PUT /api/actions/{id}` ;
- `GET|POST /api/actions/{id}/comments` ;
- `GET /api/notifications` et `PUT /api/notifications/{id}/read`.

Les actions et toutes leurs relations sont limitées au tenant courant. Les notifications ne sont visibles que par leur destinataire.

## Conformité

- `GET|POST /api/frameworks` et `GET|PUT /api/frameworks/{id}` ;
- `GET|POST /api/frameworks/{id}/requirements` et `PUT /api/requirements/{id}` ;
- `GET|POST /api/compliance-assessments` et `GET|PUT /api/compliance-assessments/{id}` ;
- `GET /api/compliance-assessments/{id}/results` et `PUT /api/compliance-results/{id}`.

Les référentiels sont partagés, tandis que les évaluations, résultats et actions correctives sont systématiquement résolus dans l’organisation courante.

## Tableau de bord et exports

- `GET /api/dashboard` : indicateurs consolidés, niveaux de risque, actions à échéance, principaux risques et conformité par référentiel ;
- `GET /api/exports/risks.csv` : registre des risques en CSV ;
- `GET /api/exports/actions.csv` : plans d’action en CSV ;
- `GET /api/exports/compliance/{id}.csv` : résultats d’une évaluation en CSV.

Les exports sont encodés en UTF-8 avec séparateur point-virgule. Ils neutralisent les cellules susceptibles d’être interprétées comme des formules par un tableur et appliquent les mêmes contrôles JWT et tenant que les écrans.
