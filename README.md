# RiskPilot

RiskPilot est une plateforme GRC open source développée de zéro pour gérer les risques cyber, la conformité et les plans d’action. Ce dépôt contient le socle de l’étape 1 : Symfony 7.4 LTS, API Platform, React/TypeScript et l’infrastructure Docker.

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

Les écrans `/scopes`, `/assets`, `/threats` et `/vulnerabilities` donnent accès à l’inventaire de l’organisation. Les API associées permettent la création et la modification aux Risk Managers et administrateurs, avec contrôle systématique des relations entre tenants.

Le compte de développement `admin@riskpilot.local` / `ChangeMe123!` n’est créé que dans la base locale utilisée pendant le développement. Les fixtures reproductibles seront ajoutées à l’étape 7.

## Tests

Après démarrage :

```bash
make test
make lint
curl http://localhost:8080/api/health
```

## Limitations connues

Les étapes 1 à 3 fournissent l’environnement, l’authentification, l’isolation multi-tenant et les catalogues de contexte du risque. Les formulaires avancés, la pagination et les relations graphiques entre actifs seront enrichis avec les modules de risque. Les refresh tokens, la réinitialisation par email et les fixtures reproductibles seront intégrés avec les notifications et les données de démonstration.

Licence : AGPL-3.0-or-later.
