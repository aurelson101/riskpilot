# RiskPilot

RiskPilot est une plateforme GRC open source développée de zéro pour gérer les risques cyber, la conformité et les plans d’action. Le dépôt couvre désormais les étapes 1 à 5 : socle technique, authentification multi-tenant, contexte et moteur de risque, plans d’action et notifications.

## Prérequis

- Docker 24+ avec Docker Compose v2
- GNU Make
- Ports `8080` (application) et `8025` (Mailpit) disponibles

PHP, Composer, Node et PostgreSQL n’ont pas besoin d’être installés sur l’hôte.

## Installation

```bash
cp .env.example .env
make install
make start
```

L’application est disponible sur <http://localhost:8080>, l’API sur <http://localhost:8080/api> et Mailpit sur <http://localhost:8025>.

Après l’installation, créez le premier administrateur :

```bash
docker compose exec backend php bin/console app:user:create-admin \
  "Mon organisation" admin@example.com "un-mot-de-passe-robuste"
```

## Commandes

`make start`, `make stop`, `make restart`, `make logs`, `make migrate`, `make fixtures`, `make test`, `make lint`, `make shell-backend`, `make shell-frontend` et `make reset` couvrent le cycle de développement courant.

## Structure

- `backend/` : API Symfony, organisée en couches Domain, Application, Infrastructure et Api.
- `frontend/` : SPA React, TypeScript, Vite et Material UI.
- `docker/` : configuration Nginx et infrastructure locale.
- `docs/` : architecture, sécurité, données, API, déploiement et développement.

## Authentification et administration

La connexion JWT est disponible sur `POST /api/auth/login`. Les jetons expirent après 15 minutes. `GET /api/me` retourne le profil courant. Les administrateurs gèrent les utilisateurs de leur propre organisation ; seuls les super-administrateurs peuvent gérer plusieurs organisations.

Les écrans `/scopes`, `/assets`, `/threats`, `/vulnerabilities` et `/security-controls` donnent accès à l’inventaire de l’organisation. Le registre `/risks` présente les scores brut, actuel et résiduel. La matrice interactive `/risk-matrix` restitue ces évaluations sur une grille 5 × 5 selon les seuils configurés par organisation. Les API associées permettent la création et la modification aux Risk Managers et administrateurs, avec contrôle systématique des relations entre tenants.

## Moteur de risque

Un scénario associe un périmètre, un actif, une menace, des vulnérabilités, des mesures de sécurité et un responsable. Chaque évaluation utilise une vraisemblance et un impact de 1 à 5 ; le score est leur produit. Les seuils par défaut sont faible jusqu’à 4, modéré jusqu’à 9, élevé jusqu’à 16 et critique au-delà. Ils sont personnalisables sur l’organisation.

Les principales API sont `GET|POST /api/risks`, `GET|PUT /api/risks/{id}`, `GET|POST /api/security-controls`, `GET|PUT /api/security-controls/{id}` et `GET /api/risk-matrix?scoreType=current`.

## Plans d’action et notifications

L’écran `/actions` propose les vues tableau, Kanban et calendrier. Une action est associée à un risque, éventuellement à une mesure de sécurité, et suit son responsable, sa priorité, ses dates, sa progression, ses coûts, la réduction de risque attendue, ses preuves et ses commentaires. Le statut `OVERDUE` est calculé automatiquement lorsque l’échéance est dépassée.

Les affectations, changements de responsable et alertes d’échéance produisent une notification dans `/notifications` et un email asynchrone traité par Symfony Messenger. La commande suivante génère les alertes d’échéance :

```bash
docker compose exec backend php bin/console app:actions:notify-deadlines
```

Les API principales sont `GET|POST /api/actions`, `GET|PUT /api/actions/{id}`, `GET|POST /api/actions/{id}/comments`, `GET /api/notifications` et `PUT /api/notifications/{id}/read`.

Le compte de développement `admin@riskpilot.local` / `ChangeMe123!` n’est créé que dans la base locale utilisée pendant le développement. Les fixtures reproductibles seront ajoutées à l’étape 7.

## Tests

Après démarrage :

```bash
make test
make lint
curl http://localhost:8080/api/health
```

## Limitations connues

Les étapes 1 à 5 fournissent l’environnement, l’authentification, l’isolation multi-tenant, les catalogues de contexte, le registre, la cotation, la matrice, les plans d’action et les notifications. La pagination et les relations graphiques entre actifs seront enrichies dans les prochaines étapes. Les refresh tokens, la réinitialisation par email et les fixtures reproductibles seront intégrés avec les données de démonstration.

Licence : AGPL-3.0-or-later.
