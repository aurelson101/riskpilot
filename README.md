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

## Commandes

`make start`, `make stop`, `make restart`, `make logs`, `make migrate`, `make fixtures`, `make test`, `make lint`, `make shell-backend`, `make shell-frontend` et `make reset` couvrent le cycle de développement courant.

## Structure

- `backend/` : API Symfony, organisée en couches Domain, Application, Infrastructure et Api.
- `frontend/` : SPA React, TypeScript, Vite et Material UI.
- `docker/` : configuration Nginx et infrastructure locale.
- `docs/` : architecture, sécurité, données, API, déploiement et développement.

## Comptes de démonstration

Les fixtures et le compte `admin@riskpilot.local` / `ChangeMe123!` seront ajoutés à l’étape dédiée. Aucun compte par défaut n’est créé dans ce socle.

## Tests

Après démarrage :

```bash
make test
make lint
curl http://localhost:8080/api/health
```

## Limitations connues

Cette première étape fournit l’environnement exécutable et les dépendances structurantes. L’authentification, les entités, migrations, fixtures et écrans métier sont planifiés dans les étapes 2 à 7 du cahier des charges.

Licence : AGPL-3.0-or-later.
