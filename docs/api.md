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

Les noms de fichiers incluent l’organisation et la date d’extraction. Les exports contiennent les libellés français et les codes métier bruts afin de rester à la fois lisibles et exploitables. Les graphiques et styles ne faisant pas partie du format CSV, `/reports/executive` fournit le rapport visuel imprimable ou enregistrable en PDF depuis le navigateur.

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
