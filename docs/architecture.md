# Architecture de RiskPilot

Ce document décrit l’architecture réellement exécutée par le dépôt. Il sert de point d’entrée aux développeurs, exploitants et auditeurs techniques. Les contrats HTTP détaillés sont dans [api.md](api.md), le modèle métier dans [data-model.md](data-model.md), les mesures de sécurité dans [security.md](security.md) et le déploiement dans [deployment.md](deployment.md).

## Vue d’ensemble

RiskPilot est un monorepo composé d’une SPA React et d’une API Symfony. Nginx constitue l’unique point d’entrée HTTP du compose local.

```text
Navigateur
    |
    v
Nginx :8080
    |-- /api, /docs ----------> Symfony / PHP-FPM
    |                              |-- PostgreSQL (données métier)
    |                              |-- Redis (transport asynchrone)
    |                              |-- volume privé (documents ISMS)
    |                              `-- SMTP ou API OAuth 2.0
    |
    `-- autres routes ----------> React / Vite

Redis ----> worker Symfony Messenger ----> SMTP, Gmail API ou Microsoft Graph
```

En développement, Mailpit reçoit les emails SMTP sur le réseau Docker et expose son interface sur le port `8025`. En production, `compose.prod.yaml` construit les images de production tandis que le HTTPS reste fourni par un reverse proxy externe.

## Organisation du monorepo

| Chemin | Responsabilité |
| --- | --- |
| `backend/src/Controller` | Adaptateurs HTTP, validation d’entrée, autorisations et orchestration |
| `backend/src/Api` | DTO, conversion JSON et réponses API communes |
| `backend/src/Domain` | Règles métier indépendantes, notamment le calcul de risque |
| `backend/src/Application` | Services applicatifs : utilisateur courant, notifications, stockage et messagerie |
| `backend/src/Entity` | Modèle persistant Doctrine et invariants proches des agrégats |
| `backend/src/Repository` | Requêtes Doctrine et filtrage par organisation |
| `backend/src/Security` | MFA, chiffrement de secrets, contrôle des comptes et ACL documentaires |
| `backend/src/Message*` | Messages et traitements asynchrones Symfony Messenger |
| `frontend/src/pages` | Écrans fonctionnels chargés à la demande |
| `frontend/src/auth` | Session JWT et profil utilisateur côté navigateur |
| `frontend/src/api` | Client Axios et types partagés par les pages |
| `docker` | Nginx HTTP interne et configuration des images |
| `docs` | Architecture, API, sécurité, modèle de données et exploitation |

L’architecture backend est pragmatique : le domaine du risque est isolé dans `Domain`, tandis que plusieurs règles de gestion restent portées par les entités et contrôleurs historiques. Une évolution doit déplacer les règles réutilisables vers `Domain` ou `Application` sans contourner les contrôles tenant des repositories et contrôleurs.

## Flux d’une requête authentifiée

1. La SPA envoie le JWT dans l’en-tête `Authorization: Bearer`.
2. Le firewall Symfony vérifie la signature et recharge l’utilisateur depuis PostgreSQL.
3. `ActiveUserChecker` refuse immédiatement un compte inactif ou verrouillé.
4. Les règles `access_control` exigent au minimum `ROLE_VIEWER` pour les routes `/api` privées.
5. Le contrôleur récupère l’utilisateur via `CurrentUser` et vérifie le rôle métier nécessaire.
6. Le repository limite la lecture à l’organisation courante.
7. Les relations transmises par identifiant sont à nouveau recherchées dans la même organisation.
8. Doctrine écrit la transaction et `AuditSubscriber` journalise les opérations HTTP sensibles.

Le frontend masque les fonctions non autorisées pour améliorer l’expérience, mais cette visibilité n’est jamais une barrière de sécurité. L’API reste l’autorité finale.

## Multi-tenant et autorisations

L’organisation est la frontière de sécurité principale. Les entités métier tenant-aware portent une relation vers `Organization`. Toute liste, lecture directe et résolution de relation doit inclure cette organisation dans sa requête.

Les rôles globaux à l’organisation sont hiérarchisés :

```text
ROLE_SUPER_ADMIN
    `-- ROLE_ADMIN
          `-- ROLE_RISK_MANAGER
                `-- ROLE_VIEWER

ROLE_AUDITOR -------> ROLE_VIEWER
ROLE_ACTION_OWNER --> ROLE_VIEWER
```

`ROLE_SUPER_ADMIN` administre plusieurs organisations. `ROLE_ADMIN` administre son tenant. Les rôles spécialisés limitent les écritures sur les risques, actions, audits ou ressources de conformité.

### Conformité continue

`StatementOfApplicability` représente une version de SoA liée à une organisation, un référentiel et un périmètre. Ses lignes relient chaque exigence aux contrôles, risques, actions et références de preuve. Une approbation fige la version ; `revise` duplique les lignes dans une version suivante et marque l’ancienne comme remplacée. `SecurityControlTest` apporte la preuve de conception ou d’efficacité opérationnelle avec planification de la prochaine revue. `RequirementMapping` relie deux exigences et autorise, explicitement, la résolution de preuves héritées dans le tenant courant avec provenance et couverture.

### Assurance, audits et CAPA

`AuditProgram` porte le plan annuel d’une organisation. Il agrège des `AuditEngagement` rattachés à un périmètre, un auditeur principal, une équipe, des dates et une déclaration d’indépendance. Les `AuditFinding` séparent observation, opportunité et non-conformité mineure/majeure. Le workflow CAPA documente cause racine, correction, actions corrective/préventive et preuve ; la revue d’efficacité interdit l’auto-validation et rouvre automatiquement l’action si elle est jugée inefficace. Ces objets sont audités par le subscriber général mais restent distincts d’`AuditLog`, qui est le journal technique append-only.

### Tiers et évaluations externes

`ThirdParty` est isolé par organisation et porte le contexte contractuel, les dépendances, la criticité, le plan de sortie et le score courant. `SupplierAssessment` conserve un instantané versionné des questions et pondérations. Le portail public ne donne accès qu’au questionnaire désigné par un jeton aléatoire de 256 bits jusqu’à son expiration ; aucune API du tenant n’y est exposée. La soumission est ensuite revue par un utilisateur autorisé avant consolidation du cyberscore.

### Résilience

`SecurityIncident` porte le cycle opérationnel, les impacts structurés, la chronologie, les preuves et les relations vers actifs, tiers, risques et actions du même tenant. Une clôture soumise à notification réglementaire est refusée tant que la date d’envoi manque. `ContinuityProcess` porte la BIA, contrôle `RTO <= MTPD`, documente RPO, dépendances, PCA/PRA et conserve les exercices, participants, écarts et améliorations sous forme d’instantanés.

### Registre réglementaire

`RegulatoryRecord` utilise un type fermé et des schémas de validation métier pour les traitements, AIPD, violations, obligations et exceptions. Les détails restent structurés en JSON afin d’accepter les évolutions réglementaires sans mélanger les tenants. Les exceptions sont temporaires et appliquent une séparation demandeur/approbateur.

### Pilotage exécutif

`ExecutiveGovernanceRecord` applique un schéma par objectif, indicateur, revue de direction, scénario financier ou investissement. Les fourchettes financières sont contrôlées et les dossiers conservent coût, charge, réduction attendue et ROI. L’endpoint Vision 360° agrège directement les repositories tenant-scoped afin que le rapport ne repose pas sur une copie désynchronisée.

Les documents ISMS ajoutent une ACL par ressource :

| Permission | Lecture | Modifier/versionner | ACL, propriétaire, partage, approbation |
| --- | ---: | ---: | ---: |
| `READ` | oui | non | non |
| `EDIT` | oui | oui | non |
| `MANAGE` | oui | oui | oui |
| Propriétaire | oui | oui | oui |
| Administrateur du tenant | oui | oui | oui |

Seuls les utilisateurs actifs de la même organisation peuvent devenir propriétaires ou recevoir une ACL. Une ressource inexistante et une ressource interdite répondent généralement de la même façon afin de ne pas révéler leur présence.

## Modules fonctionnels

### Risques et inventaire

Les périmètres, actifs, menaces, vulnérabilités et mesures alimentent `RiskScenario`. `RiskCalculation` calcule les scores brut, actuel et résiduel à partir d’une vraisemblance et d’un impact. Les seuils de `RiskLevel` peuvent être personnalisés par organisation.

### Plans d’action

Une action peut être liée à un risque et à une mesure de sécurité. Les changements de responsable, commentaires et échéances produisent des notifications. La commande `app:actions:notify-deadlines` détecte les échéances à notifier.

### Conformité

Un `Framework` contient des exigences. Une `ComplianceAssessment` instancie les résultats applicables sur un périmètre. Les résultats portent maturité, statut, preuve et action corrective éventuelle.

### Documents ISMS

Le document est l’agrégat principal :

```text
IsmsDocument
    |-- IsmsDocumentVersion (historique immuable et empreinte de fichier)
    |-- IsmsDocumentAcl     (READ, EDIT, MANAGE)
    `-- IsmsDocumentShare   (jeton, mot de passe, expiration, révocation)
```

Le cycle est `DRAFT` → `IN_REVIEW` → `APPROVED` → `ARCHIVED`. L’approbation mémorise le valideur et la prochaine revue. Toute modification invalide l’approbation et révoque les liens publics existants. Seule une version approuvée peut être partagée.

La navigation documentaire dérive ses catégories de `GET /api/isms-documents`, qui ne retourne que les documents lisibles par l’utilisateur courant. Les sous-menus respectent donc naturellement le tenant et les ACL. La racine du module affiche les 10 éléments accessibles les plus récents ; un sous-menu applique ensuite son filtre de catégorie.

Les fichiers `.doc` et `.docx` sont validés, limités à 10 Mo, renommés aléatoirement et conservés dans le volume privé `isms_document_files`. L’API contrôle les ACL avant tout téléchargement. Le jeton public et le mot de passe ne sont conservés que sous forme d’empreinte.

### Messagerie et notifications

`NotificationService` persiste la notification puis publie `SendNotificationEmail`. Le worker appelle `OrganizationMailer`, qui sélectionne la configuration du tenant : SMTP, Gmail API ou Microsoft Graph. `SecretCipher` chiffre les mots de passe, secrets et jetons OAuth avec une clé dérivée de `APP_SECRET`.

## Architecture frontend

`App.tsx` déclare les routes, charge les pages avec `React.lazy` et construit la navigation responsive. `AuthContext` conserve le JWT et charge le profil courant. Axios centralise l’URL API et l’en-tête d’authentification. TanStack Query gère les lectures, mutations et invalidations de cache dans les pages métier.

Le flux courant d’un écran est :

```text
Route React
  -> page lazy
  -> useQuery / useMutation
  -> client Axios
  -> API Symfony
  -> invalidation du cache
  -> rafraîchissement de l’interface
```

Les composants Material UI utilisent des valeurs responsives `xs`, `sm`, `md` et `xl`. Toute nouvelle page doit rester utilisable avec le tiroir mobile et la barre latérale repliée.

## Persistance et migrations

PostgreSQL est la source de vérité. Toute modification d’entité persistée nécessite une migration Doctrine versionnée dans `backend/migrations`. Une livraison valide doit satisfaire :

```bash
docker compose exec backend php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec backend php bin/console doctrine:schema:validate
```

Redis utilise l’AOF pour les données locales. Les pièces jointes ISMS, dépendances Composer et modules Node disposent de volumes distincts. Le code source reste monté dans les conteneurs de développement.

## Réseau et déploiement

Le compose expose uniquement :

- `8080` pour Nginx et l’application ;
- `8025` pour l’interface Mailpit de développement.

PostgreSQL, Redis, PHP-FPM et Vite restent sur le réseau privé `riskpilot`. Nginx utilise le DNS Docker dynamique pour retrouver le backend et le frontend après un rebuild. Le fichier autonome `nginx.conf.example` fournit le modèle HTTPS externe et ne fait pas partie du compose HTTP.

Le service `scheduler` exécute les maintenances périodiques, notamment l’expiration des acceptations de risques. Une expiration replace automatiquement le risque en revue et notifie son propriétaire. Le module `risk-governance` conserve les politiques par tenant, les décisions signées par leur auteur, les campagnes et les instantanés de scores ; aucun statut `ACCEPTED` ne peut être saisi sans acceptation approuvée encore valide.

## Variables et secrets structurants

| Variable | Usage |
| --- | --- |
| `APP_ENV`, `APP_DEBUG` | environnement Symfony |
| `APP_SECRET` | secret applicatif et dérivation du chiffrement |
| `APP_URL` | liens publics et callbacks OAuth |
| `DATABASE_URL` | connexion PostgreSQL |
| `REDIS_URL`, `MESSENGER_TRANSPORT_DSN` | Redis et messages asynchrones |
| `JWT_SECRET_KEY`, `JWT_PUBLIC_KEY`, `JWT_PASSPHRASE` | signature des JWT |
| `VITE_API_URL` | base API utilisée par la SPA |

Les secrets réels restent dans `.env`, un gestionnaire de secrets ou l’environnement d’exécution. Ils ne doivent jamais entrer dans Git, une réponse API ou un journal d’audit.

## Règles pour faire évoluer l’architecture

1. Filtrer toute donnée métier par organisation dès le repository.
2. Revalider dans le tenant chaque identifiant reçu du client.
3. Exécuter les autorisations côté API, même si le bouton est masqué dans React.
4. Ne jamais retourner un mot de passe, secret OAuth, jeton public ou nom physique de fichier.
5. Versionner toute modification du schéma avec Doctrine Migrations.
6. Publier les traitements lents dans Messenger lorsqu’ils n’ont pas besoin de bloquer la réponse.
7. Ajouter un test d’isolation tenant et un test de permission pour chaque nouvelle ressource sensible.
8. Conserver Docker en HTTP interne et terminer TLS sur le reverse proxy externe.

## Contrôles avant livraison

```bash
make test
make lint
docker compose up -d --build
curl http://localhost:8080/api/health
```

Vérifiez également que les migrations sont à jour, que tous les conteneurs sont sains et qu’aucune erreur critique récente n’apparaît dans les logs.
