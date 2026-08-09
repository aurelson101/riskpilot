# Audit technique de RiskPilot

Date de référence : 2026-08-09

Révision auditée : `6894739` (`main`)

Périmètre : dépôt produit RiskPilot, hors site vitrine et hors configuration privée du VPS.

## Synthèse

RiskPilot est une application GRC fonctionnelle et déjà étendue ; ce n'est pas
un prototype à reconstruire. Le dépôt compte 422 fichiers suivis, environ
18 000 lignes PHP et 17 500 lignes TypeScript/TSX. L'API expose 248 routes,
50 entités Doctrine, 44 contrôleurs et 39 migrations. Le frontend comporte 28
pages. PostgreSQL, Redis, Symfony Messenger, Nginx et deux applications web sont
orchestrés par Docker Compose.

Les socles actifs, risques, matrice 5 × 5, plans d'action, conformité, packs
NIS2/RGPD/ISO 27001/EBIOS RM, documents versionnés, audits, résilience, tiers,
RBAC, MFA, journal append-only, rapports PDF et configuration IA sont présents.
Ils doivent être conservés.

Le principal écart avec la cible est fonctionnel et structurel : EBIOS RM est
actuellement porté par `RiskScenario.methodData`, `RiskAnalysis` et des
`AnalysisArtifact` génériques. Les cinq ateliers ne disposent pas encore de
modèles métier dédiés, de transitions complètes ni d'un éditeur de chemins.
De même, une partie des preuves de conformité reste une liste de références
JSON plutôt qu'un objet probant versionné et réutilisable. Ces écarts relèvent
des phases P3/P4 et ne justifient aucune réécriture destructive.

L'état de qualité de départ est bon : 98 tests backend (509 assertions), 12
tests frontend, PHPStan, PHP CS Fixer, TypeScript, lint, audit FR/EN et build
passent. Composer et npm ne signalent aucune vulnérabilité connue. La couverture
fonctionnelle reste toutefois inégale : 34 classes de tests backend pour 44
contrôleurs et seulement 5 fichiers de tests frontend pour 28 pages.

## Architecture actuelle

### Backend

- PHP 8.4, Symfony 7.4 LTS, API Platform 4.3 ;
- Doctrine ORM 3.6 et DoctrineBundle 2.18 ;
- PostgreSQL 17, identités SQL et 39 migrations non destructives ;
- authentification JWT stateless, refresh token opaque haché et révocable ;
- mots de passe hachés avec libsodium, MFA TOTP et codes de secours ;
- validation Symfony et `JsonInputMapper` pour les DTO récents, validations
  manuelles pour plusieurs contrôleurs historiques ;
- Symfony Messenger sur Redis pour les notifications asynchrones ;
- Monolog JSON en production et journal métier `AuditLog` chaîné par empreinte ;
- Dompdf pour les rapports PDF et stockage documentaire local ou S3 compatible.

Les contrôleurs sont organisés par capacité, mais portent encore une part
importante de logique métier et de sérialisation. `ApiResponseFactory` centralise
les représentations des objets historiques ; les modules plus récents possèdent
leurs propres méthodes de réponse.

### Frontend

- React 19, TypeScript 6, Vite 8 et Material UI 7 ;
- TanStack Query pour le cache serveur, Axios pour l'API ;
- React Hook Form et Zod disponibles mais pas généralisés ;
- Recharts pour la matrice, les indicateurs, les secteurs et radars ;
- chargement différé des pages et navigation responsive dans `App.tsx` ;
- catalogue FR/EN contrôlé par `npm run i18n:audit`.

L'interface est déjà responsive, bilingue et structurée par finalité. Plusieurs
workspaces récents restent cependant trop génériques : l'analyse utilise encore
un éditeur JSON d'artefact et les statuts métier sont souvent affichés sous leur
valeur technique.

### Données et isolation

Le modèle utilise majoritairement des identifiants entiers, cohérents avec
l'existant. Les agrégats métier sont rattachés à `Organization`, avec des
repositories filtrant le tenant et des tests anti-fuite. Les relations sensibles
sont vérifiées dans les contrôleurs avant association. Une migration vers UUID
n'apporterait pas de bénéfice immédiat suffisant pour justifier son risque.

Le modèle existant couvre notamment : utilisateurs, organisations, périmètres,
actifs, menaces, vulnérabilités, risques, mesures, exigences, évaluations,
résultats, actions, documents/version/ACL, audits/CAPA, tiers, incidents/BIA,
analyses versionnées, artefacts, décisions, opérations et rapports annuels.

### Authentification et autorisations

Rôles disponibles : Super Admin, Admin, Risk Manager, Action Owner, Auditor et
Viewer. Les rôles RSSI et Contributeur demandés correspondent fonctionnellement
à Risk Manager et Action Owner mais leurs noms et leurs permissions ne sont pas
configurables. La hiérarchie est centralisée dans `security.yaml`; les écritures
sensibles utilisent aussi `IsGranted` ou des contrôles tenant dans le code.

OIDC/SAML, Entra, Google, SCIM, clés de service et webhooks disposent d'un modèle
de configuration gouverné. Ce socle n'est pas encore un flux complet de connexion
SSO. LDAP/LDAPS, import de CA et mapping de groupes AD ne sont pas implémentés.

### Docker et exploitation

- `compose.yaml` fournit le développement reproductible ;
- `compose.prod.yaml` sélectionne les stages de production, les volumes persistants
  et `APP_ENV=prod` ;
- PostgreSQL et Redis ont des healthchecks ; backend attend les deux services ;
- les migrations sont une étape explicite et ne sont pas lancées concurremment ;
- la démo utilise cinq overlays et un démarrage systemd séquentiel adapté à son
  VPS de 1,8 Gio sans swap ;
- les images de démo doivent être construites localement puis transférées ;
- le port applicatif public de la démo reste lié à `127.0.0.1:18081`.

Le simple `docker compose up -d` convient au développement, mais pas au profil
de production : celui-ci exige explicitement les overlays de production et les
secrets réels. Charger automatiquement les fixtures ou les migrations dans tous
les conteneurs serait dangereux et n'est pas recommandé.

## Bugs détectés et corrections

| Criticité | Fichier | Cause et impact | Correction |
| --- | --- | --- | --- |
| Élevée | `deploy/demo/compose.vps-1gb.yaml` | Le worker quittait après 900 secondes et `restart: no` le laissait arrêté jusqu'au reset suivant. Emails et tâches différées pouvaient s'accumuler. | Corrigé avant cet audit par `6894739` : boucle de recyclage et healthcheck sur le vrai consommateur ; arrêt forcé et reprise validés. |
| Moyenne | `backend/config/packages/doctrine.yaml` | Les objets lazy natifs n'étaient pas activés ; Doctrine chargeait `Proxy\\Autoloader`, déprécié, à chaque commande et worker. | Corrigé pendant l'audit : `enable_native_lazy_objects: true`; ancien réglage de ghost objects retiré. |
| Faible | `backend/config/packages/doctrine.yaml` | `use_savepoints` sous DBAL 4 et `report_fields_where_declared` sous ORM 3 sont devenus sans effet et dépréciés. | Corrigé pendant l'audit : options supprimées, comportement natif conservé. |
| Faible | `backend/src/Security/ActiveUserChecker.php` | La future interface Symfony ajoute le jeton au contrôle post-authentification. | Corrigé pendant l'audit par un argument optionnel `TokenInterface`, compatible Symfony 7.4. |
| Faible | `compose.yaml` | Le healthcheck appelait `doctrine:query:sql`, commande dépréciée, générant des logs toutes les dix secondes. | Corrigé pendant l'audit avec `dbal:run-sql`. |

Aucun HTTP 500 reproductible, divergence de schéma, migration en attente,
vulnérabilité de dépendance ou échec de compilation n'a été détecté dans la
baseline. Les incidents historiques de 502, proxy Nginx obsolète et chaîne
d'audit v1 sont documentés séparément ; leurs corrections actuelles ne doivent
pas être annulées.

## Dette technique et risques

### Élevé

1. **EBIOS RM non modélisé atelier par atelier dans la baseline auditée.** `RiskMethodValidator` exige
   cinq chaînes (`businessValue`, `fearedEvent`, `threatSource`,
   `strategicScenario`, `operationalScenario`) mais ne modélise ni cardinalités,
   ni propriétaires, ni chemins, ni étapes techniques. Une API/UI dédiée doit
   être ajoutée progressivement autour de `RiskAnalysis`, avec migrations de
   reprise depuis `methodData`.
   **État après audit :** première tranche livrée par cinq ateliers persistés,
   versionnés, validés dans l'ordre et exposés dans une interface spécialisée.
2. **Preuves hétérogènes.** Les documents ISMS sont versionnés et hachés, mais
   plusieurs domaines stockent encore des tableaux de chaînes `evidence`.
   Introduire un registre `Evidence` polymorphe et tenant-scoped, puis migrer les
   références sans casser les snapshots historiques.
3. **SSO annoncé plus large que l'exécution vérifiée.** Les objets OIDC/SAML/SCIM
   existent, mais LDAP/LDAPS, CA, mapping AD et un login SSO de bout en bout ne
   sont pas livrés. Ne pas les présenter comme opérationnels avant tests avec un
   annuaire de préproduction.
4. **RBAC figé dans le code dans la baseline auditée.** Les rôles couvrent les usages principaux, mais il
   n'existe pas encore de permissions administrables (`risk.read`,
   `ebios.validate`, etc.). Ajouter des permissions versionnées sans supprimer
   la compatibilité des rôles actuels.
   **État après audit :** matrice configurable par organisation et appliquée aux
   nouvelles API EBIOS ; la migration des contrôleurs historiques reste progressive.

### Moyen

1. **Pagination incomplète.** Les analyses, artefacts et bibliothèques paginent,
   mais plusieurs listes historiques chargent toutes les lignes ou appliquent
   une limite fixe. Généraliser un contrat `items/page/limit/total/pages`, tri et
   recherche après ajout de tests de compatibilité frontend.
2. **Validation non uniforme.** Les DTO validés coexistent avec des accès directs
   à `Request::toArray()` et des validations manuelles. Migrer contrôleur par
   contrôleur vers des DTO sans changer simultanément tous les contrats API.
3. **Couverture frontend faible.** Les cinq fichiers Vitest ne protègent pas les
   principaux formulaires risques, conformité, documents, EBIOS et rapports.
   Les crawls Playwright existants couvrent surtout navigation, i18n et
   accessibilité, pas toutes les écritures métier.
4. **Contrôleurs volumineux et sérialisation dispersée.** Extraire progressivement
   les cas d'usage et présentateurs, en commençant par EBIOS et conformité.
5. **API versionnée partiellement.** Les intégrations et indicateurs utilisent
   `/api/v1`, tandis que les ressources historiques restent sous `/api`. Une
   migration de routes doit conserver les alias existants et annoncer une durée
   de dépréciation.
6. **Journal historique v1.** Les événements v1 non revérifiables sont conservés
   honnêtement ; leur constat réglementaire doit encore être exporté et scellé.

### Faible

1. Le bundle principal frontend atteint environ 451 Ko non compressé et le
   module de graphiques polaires environ 300 Ko. Le découpage par page existe ;
   analyser les imports Recharts avant optimisation supplémentaire.
2. Les valeurs d'état techniques apparaissent encore dans certains écrans et
   doivent rejoindre le catalogue i18n typé.
3. La documentation est complète sur l'architecture actuelle mais n'est pas
   encore découpée selon tous les noms demandés (`LDAP.md`, `EBIOS.md`, etc.).
   Créer ces guides au moment où les flux correspondants deviennent réels.

## Sécurité

### Contrôles confirmés

- JWT stateless, refresh token `HttpOnly`, `Secure` selon HTTPS, rotation et
  révocation de sessions ;
- MFA TOTP, verrouillage temporaire et double rate limiting login Nginx/Symfony ;
- hash de mot de passe libsodium et secrets applicatifs chiffrés ;
- contrôle tenant et tests dédiés contre les IDOR inter-organisations ;
- uploads limités, noms générés, antivirus optionnel et stockage hors répertoire
  public ;
- CSP, HSTS, COOP, anti-framing, `nosniff`, politique de référent et limitation
  de taille au proxy de production ;
- endpoints IA personnalisés non contactés automatiquement, afin d'éviter le SSRF ;
- aucun secret ou certificat privé suivi par Git.

### Contrôles restant à qualifier

- tests dynamiques systématiques XSS/SSRF/path traversal sur chaque nouveau flux ;
- rotation réelle de tous les secrets et exercice de révocation ;
- SSO/LDAP sur un environnement d'identité représentatif ;
- stockage externe chiffré des sauvegardes et restauration trimestrielle ;
- supervision centralisée des 5xx, latences, files Messenger et intégrité d'audit ;
- revue réglementaire des packs et du traitement de la chaîne d'audit v1.

## Couverture fonctionnelle par rapport à la cible

| Domaine | État réel | Prochaine évolution sûre |
| --- | --- | --- |
| Dashboard RSSI | KPI, alertes, matrice et vues de pilotage existants | Aligner les KPI EBIOS/NIS2 et ajouter drill-down testé. |
| Actifs et risques | CRUD, relations, scores brut/actuel/résiduel, matrice 5 × 5 | Enrichir les biens supports et dépendances EBIOS. |
| EBIOS RM | Méthode sélectionnable, données minimales, analyses versionnées et artefacts | Modèle Atelier 1→5 dédié, éditeur de chemins et transitions. |
| NIS2 | Pack gouverné, exigences, évaluations, résultats, mappings et actions | Vue NIS2 spécialisée et cycle de mise à jour officiel. |
| Plans d'action | Table/Kanban/calendrier, responsables, progression, coûts, preuves | Pagination, délégation et liens vers preuve normalisée. |
| Documentation | Documents, versions, hash, ACL, partage public | Registre de preuves transversal et taxonomie métier demandée. |
| Audits | Programmes, missions, constats, CAPA et indépendance | Exports et tableaux de bord dédiés supplémentaires. |
| Reporting | CSV, rapports annuels/décision PDF enrichis | PDF EBIOS et NIS2 spécialisés, accessibilité/PDF-A à qualifier. |
| Identité | Local, MFA, modèles OIDC/SAML/SCIM | Login SSO complet, LDAP/LDAPS, CA et groupes AD. |
| Administration IA | Configuration Mistral/OpenAI/Gemini chiffrée | Premier workflow sourcé, consentement et validation humaine. |

## Ordre de réalisation recommandé

### P0 — stabilisation

- conserver les corrections Doctrine/Symfony/healthcheck de cet audit ;
- valider `docker compose up -d`, santé DB/Redis/backend/frontend et migrations ;
- conserver la reprise du worker Messenger et la restauration réelle ;
- ne pas démarrer P3 avant une baseline entièrement verte.

### P1/P2 — socle d'autorisation et données communes

- introduire une matrice de permissions compatible avec les six rôles existants ;
- généraliser DTO, pagination et erreurs API ;
- créer le registre probant commun avant de multiplier les pièces jointes ;
- enrichir actifs, risques et actions sans dupliquer leurs tables.

### P3 — EBIOS RM

Créer par migrations additives : analyse EBIOS, valeurs métier, événements
redoutés, biens supports, sources de risque/objectifs visés, écosystème,
scénarios stratégiques, scénarios opérationnels et étapes, mesures et décisions.
Chaque atelier doit avoir brouillon, contrôle de complétude, validation, audit,
ACL, version et tests E2E. Les anciennes données `methodData` restent lisibles
et une commande de migration contrôlée propose leur conversion.

### P4 à P7

Spécialiser NIS2 sur le registre de conformité existant, enrichir dashboard et
rapports, livrer ensuite les connecteurs d'identité réellement testés, puis
terminer l'accessibilité, la charge métier, le PRA et la documentation opérateur.

## Validation exécutée pour l'audit

- PHPUnit : 98 tests, 509 assertions ;
- PHPStan : aucune erreur avec limite 512 Mio ;
- PHP CS Fixer : 260 fichiers conformes ;
- Vitest : 5 fichiers, 12 tests ;
- Oxlint et Prettier : aucune erreur ni avertissement ;
- catalogue FR/EN : 1 808 valeurs localisées ;
- TypeScript et build Vite : succès ;
- `composer audit` et `npm audit` : aucune vulnérabilité connue ;
- Doctrine : mapping valide, schéma synchronisé et migrations à jour lors de la
  baseline précédente immédiatement antérieure ;
- test de restauration : import complet de 65 tables dans PostgreSQL isolé ;
- smoke test public : 100 requêtes concurrentes par lots, aucune erreur et p95
  inférieur au budget de 750 ms.

Ces contrôles prouvent la stabilité de la baseline, pas une certification
EBIOS RM, NIS2, ISO 27001 ou une conformité réglementaire automatique.
