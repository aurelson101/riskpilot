# RiskPilot

RiskPilot est une plateforme GRC open source développée de zéro pour gérer les risques cyber, la conformité et les plans d’action. Le dépôt couvre désormais les étapes 1 à 7 : socle technique, authentification multi-tenant, risques, plans d’action, notifications, conformité, tableaux de bord, exports et données de démonstration.

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

Chargez facultativement le jeu de démonstration reproductible :

```bash
make fixtures
```

Cette commande remplace les données de la base courante. Pour une base vide sans démonstration, créez le premier administrateur :

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

La [roadmap](docs/roadmap.md) maintient les écarts restants et leur ordre de priorité avant une exploitation critique.

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

## Référentiels et conformité

L’écran `/compliance` regroupe les référentiels et les évaluations. Une évaluation porte sur un périmètre et génère un résultat pour chaque exigence active. Les évaluateurs saisissent un niveau de maturité de 0 à 5, un statut conforme, partiel, non conforme, non applicable ou non évalué, ainsi que des preuves et une action corrective facultative. Le score global exclut les exigences non applicables ou non évaluées.

Les API principales sont `GET|POST /api/frameworks`, `GET|POST /api/frameworks/{id}/requirements`, `GET|POST /api/compliance-assessments`, `GET /api/compliance-assessments/{id}/results` et `PUT /api/compliance-results/{id}`.

## Tableau de bord, exports et démonstration

Le tableau de bord consolide les risques par niveau, les actions proches de leur échéance et la conformité par référentiel. Les boutons d’export produisent des fichiers CSV UTF-8 pour le registre des risques, les plans d’action et une évaluation de conformité, toujours limités à l’organisation courante.

Les fixtures créent une organisation, trois utilisateurs, plusieurs périmètres, 10 actifs, 10 menaces, 10 vulnérabilités, 15 risques, 20 actions et une évaluation d’un référentiel générique. Elles sont réservées au développement :

- `admin@riskpilot.local` / `ChangeMe123!` ;
- `risk.manager@riskpilot.local` / `ChangeMe123!` ;
- `action.owner@riskpilot.local` / `ChangeMe123!`.

Le compte administrateur est super-administrateur. Depuis l’interface, il peut créer et modifier les utilisateurs et organisations, gérer les inventaires, risques, actions et évaluations, archiver ou désactiver les ressources importantes, et consulter le journal d’audit. Le rôle « Lecteur » hérité pour l’autorisation interne n’est pas présenté comme rôle assigné.

## Tests

Après démarrage :

```bash
make test
make lint
curl http://localhost:8080/api/health
```

## Limitations connues

Le compose fourni cible d’abord le développement. Le renouvellement/révocation avancé des sessions, le stockage externe des preuves, l’import de référentiels sous licence et l’observabilité de production restent à intégrer avant une exploitation critique.

Licence : AGPL-3.0-or-later.
