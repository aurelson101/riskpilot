# API

L’API REST est servie sous `/api` et sa documentation OpenAPI sous `/docs`. Les réponses d’erreur métier utiliseront un code stable, un message lisible et un objet `errors` pour les violations de validation.

Le point `GET /api/health` permet de vérifier le service sans authentification. Les ressources métier sont protégées par JWT et isolées par organisation.

## Authentification

- `POST /api/auth/login` : échange email/mot de passe contre un JWT de 15 minutes ; répond `202` avec `mfaRequired` lorsqu’un second facteur est requis.
- `GET /api/me` : profil de l’utilisateur courant.
- `PUT /api/me` : modification du prénom, du nom et de l’adresse email du profil courant.
- `PUT /api/me/password` : changement du mot de passe courant.
- `POST /api/me/mfa/setup`, `/enable`, `/disable` : enrôlement et retrait du MFA après confirmation du mot de passe.

## Administration

- `GET|POST /api/users` et `GET|PUT /api/users/{id}`.
- `GET|POST /api/organizations` et `GET|PUT /api/organizations/{id}`.
- `GET /api/audit-logs` : 500 dernières mutations visibles par l’administrateur.
- `GET|PUT /api/settings/email` : configuration SMTP isolée par organisation.
- `POST /api/settings/email/test` : test immédiat vers le destinataire choisi.
- `POST /api/settings/email/oauth/{provider}/authorize` : génère l’URL de consentement Google ou Microsoft avec état anti-CSRF.
- `GET /api/settings/email/oauth/{provider}/callback` : échange le code OAuth, chiffre les jetons et reconnecte l’interface.
- `POST /api/settings/email/oauth/disconnect` : retire les jetons OAuth de l’organisation.

Les endpoints utilisateurs appliquent le tenant de l’utilisateur authentifié au niveau du repository. Une ressource d’une autre organisation est renvoyée comme inexistante.

## Inventaire et contexte de risque

- `GET|POST /api/scopes` et `GET|PUT /api/scopes/{id}` ;
- `GET|POST /api/assets` et `GET|PUT /api/assets/{id}` ;

`GET /api/assets?family=HARDWARE|SOFTWARE|INFORMATION` filtre le registre commun sans dupliquer les actifs. Les écritures portent une propriété `family` explicite.
- `GET|POST /api/threats` et `GET|PUT /api/threats/{id}` ;
- `GET|POST /api/vulnerabilities` et `GET|PUT /api/vulnerabilities/{id}`.

La lecture est ouverte aux utilisateurs authentifiés. Les mutations exigent le rôle Risk Manager ou un rôle supérieur. Les relations vers un parent, un responsable, un périmètre ou un actif sont résolues exclusivement dans l’organisation courante.

## Plans d’action et notifications

- `GET|POST /api/actions` et `GET|PUT /api/actions/{id}` ;
- `GET|POST /api/actions/{id}/comments` ;
- `GET /api/notifications` et `PUT /api/notifications/{id}/read`.

Les actions et toutes leurs relations sont limitées au tenant courant. Les notifications ne sont visibles que par leur destinataire.

## Pilotage opérationnel P1

- `GET /api/operations/my-tasks` : tâches personnelles consolidées depuis les tâches opérationnelles, plans d’action et évaluations de conformité ;
- `GET|POST /api/operations/records` : liste ou création d’un objet opérationnel, filtrable par `type` ; les types gérés par le système ne peuvent pas être créés par cette route générique ;
- `PUT /api/operations/records/{id}` : modification tenant-scoped d’un objet, sauf les instantanés et autres objets système immuables ;
- `GET /api/operations/compliance-trajectory` : trajectoires calculées vers la date cible.

Les types disponibles sont `TASK`, `RESPONSIBILITY_RULE`, `COMPLIANCE_PROGRAM`, `QUESTIONNAIRE_TEMPLATE`, `QUESTIONNAIRE_CAMPAIGN` et `REFERENCE_PACK`. Les écritures exigent au minimum `ROLE_RISK_MANAGER`. Les règles, modèles, campagnes et packs conservent leur configuration versionnée dans `details`; un pack doit référencer sa source, sa licence et sa version sans reproduire le texte protégé d’une norme.

## Rapports annuels

- `GET /api/annual-reports/years` liste les années connues et les instantanés conservés ;
- `GET /api/annual-reports/{year}` produit la vue annuelle courante depuis le journal d’audit du tenant ;
- `GET|PUT /api/annual-reports/{year}/maturity` consulte ou met à jour l'évaluation annuelle de maturité cyber sur dix services, de 0 à 5 par pas de 0,5 ;
- `POST /api/annual-reports/{year}/generate` crée une nouvelle version immuable (`ROLE_RISK_MANAGER` minimum) ;
- `GET /api/annual-reports/saved/{id}/export?format=json|html` exporte un instantané reproductible, dont la version HTML est imprimable en PDF.

La classification agrège les changements par mois, domaine, action et contributeur. Le journal complet des activités mutantes de la période est inclus, sans exposer les anciennes/nouvelles valeurs ni les données techniques du client.

Le radar de maturité est indépendant du volume d'activité. Un domaine est explicitement évalué ou non évalué ; toute note évaluée, y compris 0, exige une justification. Les scores inférieurs ou égaux à 2 sont signalés comme faiblesses prioritaires et les domaines non évalués sont exclus de la moyenne. La mise à jour exige `ROLE_RISK_MANAGER`, tandis que la lecture suit les droits du rapport annuel. L'instantané annuel incorpore l'évaluation en vigueur afin de conserver la situation observée au moment de la génération. Les instantanés `ANNUAL_REPORT` ne sont ni créables ni modifiables par les endpoints opérationnels génériques.

Le scheduler exécute `app:operations:remind`. Une tâche active attribuée et arrivée dans sa fenêtre de rappel produit au plus une relance par jour via la file transactionnelle de notifications.

## Décision et différenciation P2

Les objets `SECURITY_PROJECT`, `SAVED_VIEW`, `REPORT_TEMPLATE`, `REPORT_RUN`,
`CONNECTOR_SYNC` et `TPRM_PROGRAM` utilisent les endpoints tenant-scoped du
pilotage opérationnel. Les traitements spécialisés sont exposés sous
`/api/decision` :

- `POST /api/decision/projects/{id}/transition` : workflow Security by Design avec transitions contrôlées, validation tenant des actifs, risques et actions, puis portes d'avis et de mise en production ;
- `POST /api/decision/financial-scenarios/{id}/simulate` : simulation déterministe de 1 000 observations, percentiles, intervalle à 90 % et sensibilité, uniquement après approbation finance ;
- `GET /api/decision/views/{id}/snapshot` : exécution d'une vue enregistrée personnelle ou partagée sans copie des données sources ;
- `GET /api/decision/platform-vision` : consolidation multi-organisations réservée au super-administrateur ;
- `POST /api/decision/reports/{id}/run` et `GET /api/decision/reports/{runId}/export?format=json|html` : instantané reproductible d'un modèle versionné et export JSON ou HTML imprimable en PDF ;
- `POST /api/decision/connectors/{id}/reconcile` : rapprochement Jira ou ServiceNow idempotent, journalisé et exécutable en simulation ;
- `GET /api/decision/tprm/portfolio` : segmentation légère, standard ou approfondie, cyberscores, réévaluations, échéances contractuelles, dépendances et plans de sortie.

Les connecteurs se configurent avec `type=CONNECTOR` dans `/api/v1/integrations`.
Ils exigent une URL HTTPS, un sens de synchronisation, une stratégie de conflit
et la propriété explicite des champs. Le secret généré n'est retourné qu'à la
création ; seules son empreinte et son préfixe sont conservés.

## Expérimentations contrôlées P3

- `GET|PUT /api/experiments/settings` : activation complète par tenant et liste
  blanche des usages de l'assistant ; l'écriture est réservée aux administrateurs ;
- `GET|POST /api/experiments/assistant/proposals` : historique et génération
  déterministe de propositions sourcées ; la liste accepte `page` et `limit`
  (maximum 100) et retourne `total` et `pages` ;
- `POST /api/experiments/assistant/proposals/{id}/validate` : approbation ou
  rejet humain avec commentaire obligatoire ;
- `GET /api/experiments/assistant/evaluation` : couverture des sources, taux de
  rejet humain et compteur de décisions automatiques, toujours égal à zéro ;
- `GET|POST /api/experiments/library` : recherche paginée et création d'une
  ressource interne ;
- `POST /api/experiments/library/{id}/revisions` : nouvelle version liée sans
  modifier la version précédente ;
- `POST /api/experiments/library/{id}/submit|approve|retire` : workflow avec
  approbateur différent du propriétaire ;
- `GET /api/experiments/library/export` : export JSON des versions approuvées.
- `POST /api/experiments/library/import` : validation puis import atomique d'un
  lot JSON versionné de 100 éléments maximum. Sans `commit: true`, l'endpoint
  effectue uniquement une prévisualisation ligne par ligne. Toute ligne invalide
  bloque le lot ; une clé existante ou une dépendance non approuvée est refusée.

Les propositions possibles sont les mappings exigence-contrôle, résumés
d'écarts, brouillons de rapports et propositions de questions. Chaque réponse
contient ses sources visibles et `appliedAutomatically=false`. Elle ne modifie
jamais un risque, un contrôle, une évaluation ou une décision. La bibliothèque
accepte scénarios, actifs, menaces, vulnérabilités, contrôles, questionnaires et
modèles de rapports, avec clé stable, version, propriétaire, source, licence,
dépendances et chaîne de remplacement.

Les écritures P3 sont journalisées par le subscriber d'audit commun. Les
imports, exports, listes et transitions appliquent tous l'organisation de
l'utilisateur authentifié ; aucune organisation ne peut être fournie dans le
payload.

## Analyses et capitalisation

Le préfixe `/api/analysis-workspace` expose les améliorations structurelles 17 à
31 : analyses versionnées (`GET|POST /analyses`), révisions, contrôle qualité,
approbation indépendante, comparaison de baselines, artefacts gouvernés,
prévisualisation d'import et métriques agrégées. Les artefacts typés représentent
les étapes de méthode, preuves, efficacité des contrôles, simulations avant/après,
options de roadmap, ACL, activité métier, imports, mises à jour de bibliothèque,
niveaux fournisseurs et métriques produit. Toutes les recherches sont paginées
et tenant-scoped ; aucun payload ne choisit l'organisation.

## Conformité

- `GET|POST /api/frameworks` et `GET|PUT /api/frameworks/{id}` ;
- `GET|POST /api/frameworks/{id}/requirements` et `PUT /api/requirements/{id}` ;

Les packs RGPD, NIS2, ISO/IEC 27001:2022 et EBIOS Risk Manager peuvent être
installés avec `php bin/console app:compliance:install-starter-packs`. La
commande est idempotente sur le couple nom/version. Sources de référence :
[RGPD 2016/679](https://eur-lex.europa.eu/eli/reg/2016/679/oj),
[directive NIS2 2022/2555](https://eur-lex.europa.eu/eli/dir/2022/2555/oj),
[guide EBIOS Risk Manager](https://www.ssi.gouv.fr/guide/la-methode-ebios-risk-manager-le-guide/)
et [ISO/IEC 27001:2022](https://www.iso.org/standard/27001). Le pack ISO ne
reproduit pas le texte protégé et ne remplace pas une licence.
- `GET|POST /api/compliance-assessments` et `GET|PUT /api/compliance-assessments/{id}` ;
- `GET /api/compliance-assessments/{id}/results` et `PUT /api/compliance-results/{id}`.

Les référentiels sont partagés, tandis que les évaluations, résultats et actions correctives sont systématiquement résolus dans l’organisation courante.

## Tableau de bord et exports

- `GET /api/dashboard` : indicateurs consolidés, niveaux de risque, actions à échéance, principaux risques et conformité par référentiel ;

## Indicateurs versionnés

- `GET|POST /api/v1/indicators` : liste et création des définitions KPI/KRI de l’organisation ;
- `GET /api/v1/indicators/{id}/values?limit=100` : historique antéchronologique ;
- `POST /api/v1/indicators/{id}/values` : enregistrement d’une mesure.
- `POST /api/v1/indicators/{id}/values/batch` : import de 1 000 mesures maximum avec résultat ligne par ligne ;
- `GET /api/v1/indicators/{id}/values/export` : export CSV chronologique.

Les administrateurs définissent les colonnes personnalisées du plan d’action avec `GET|POST /api/action-custom-fields`. Les types acceptés sont `TEXT`, `NUMBER`, `DATE`, `BOOLEAN`, `SELECT` et `URL`.

Une mesure exige `value`, `measuredAt` au format ISO 8601 et `idempotencyKey`. Une nouvelle soumission de la même clé pour le même indicateur retourne la mesure existante sans doublon. Les champs optionnels sont `period`, `comment`, `evidence` (URL) et `source`.

```json
{
  "value": 98.75,
  "measuredAt": "2026-07-26T10:00:00+02:00",
  "period": "2026-07",
  "source": "SIEM",
  "idempotencyKey": "siem-availability-2026-07"
}
```
- `GET /api/exports/risks.csv` : registre des risques en CSV ;
- `GET /api/exports/actions.csv` : plans d’action en CSV ;
- `GET /api/exports/compliance/{id}.csv` : résultats d’une évaluation en CSV.

Les exports sont encodés en UTF-8 avec séparateur point-virgule. Ils neutralisent les cellules susceptibles d’être interprétées comme des formules par un tableur et appliquent les mêmes contrôles JWT et tenant que les écrans.

Les noms de fichiers incluent l’organisation et la date d’extraction. Les exports contiennent les libellés français et les codes métier bruts afin de rester à la fois lisibles et exploitables. Les graphiques et styles ne faisant pas partie du format CSV, `/reports/executive` fournit le rapport visuel imprimable ou enregistrable en PDF depuis le navigateur.

## Résilience et continuité

- `GET|POST /api/resilience/incidents` et `PUT|DELETE /api/resilience/incidents/{id}` : cycle complet des incidents ;
- `POST /api/resilience/incidents/{id}/timeline` : événement horodaté et nominatif ;
- `GET|POST /api/resilience/continuity-processes` et `PUT|DELETE /api/resilience/continuity-processes/{id}` : BIA, objectifs et PCA/PRA ;
- `POST /api/resilience/continuity-processes/{id}/exercises` : exercice, participants, résultat, écarts et améliorations.

Les relations reçues lors de la qualification d’un incident sont toutes résolues dans le tenant courant. Une clôture exige une date de notification lorsqu’une notification réglementaire est requise, et un BIA refuse un RTO supérieur au MTPD.

## Vie privée et obligations

- `GET|POST /api/regulatory-records` et `PUT|DELETE /api/regulatory-records/{id}` ;
- `POST /api/regulatory-records/{id}/approve` : approbation indépendante d’une dérogation par un administrateur.

Les schémas obligatoires varient selon le type : traitement RGPD, AIPD, violation de données, obligation ou dérogation. Les dérogations doivent expirer et ne peuvent pas être approuvées par leur responsable.

## Documents ISMS

- `GET|POST /api/isms-documents` : registre visible et création ;
- `GET /api/isms-documents/collaborators` : utilisateurs actifs sélectionnables comme propriétaire ou ACL ;
- `GET|PUT|DELETE /api/isms-documents/{id}` : détail, nouvelle version et suppression ;
- `POST|GET|DELETE /api/isms-documents/{id}/file` : ajout, téléchargement et retrait d’un fichier Word ;
- `POST /api/isms-documents/{id}/approve` : approbation nominative avec date obligatoire de prochaine revue ;
- `POST /api/isms-documents/{id}/versions/{versionId}/restore` : restauration sous forme d’une nouvelle version ;
- `POST /api/isms-documents/{id}/acl` et `DELETE /api/isms-documents/{id}/acl/{aclId}` : ACL nominatives ;
- `POST /api/isms-documents/{id}/shares` et `DELETE /api/isms-documents/{id}/shares/{shareId}` : création et révocation d’un partage ;
- `GET|POST /api/public/documents/{token}` : ouverture publique, avec mot de passe en `POST` lorsque requis.
- `GET|POST /api/public/documents/{token}/file` : téléchargement du fichier Word partagé, avec mot de passe en `POST` lorsque requis.

La liste et les accès directs sont filtrés par organisation. Une visibilité `RESTRICTED` limite la lecture au propriétaire, aux administrateurs et aux ACL. `EDIT` permet de produire une version et de soumettre à revue ; `MANAGE` permet d’approuver, d’archiver et de gérer ACL, propriétaire et partages. Le jeton public n’est retourné qu’une fois.
