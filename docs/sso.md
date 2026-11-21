# Connexion SSO OIDC

Dans **Paramètres → Intégrations → Connexion SSO**, renseigner le fournisseur, le nom affiché sur l’écran de connexion, l’émetteur, l’identifiant client et le secret client. Le secret est chiffré avec la clé dérivée d’APP_SECRET et n’est jamais renvoyé par l’API. En modification, laisser le secret vide pour le conserver.

Une modification d’émetteur ou d’identifiant client exige un nouveau secret, y compris pour une configuration inactive. Le changement de fournisseur dans une nouvelle fiche efface les identifiants et associations saisis. La sélection propose les comptes actifs de l’organisation ; les anciens comptes désactivés associés restent visibles pour être retirés. Les utilisateurs et identifiants fournisseur doivent être uniques dans une configuration.

Enregistrer exactement l’URL `https://<domaine-riskpilot>/api/auth/sso/callback` chez le fournisseur. `APP_URL` doit correspondre au domaine public HTTPS. Les clients confidentiels utilisant `client_secret_post` ou `client_secret_basic` et la signature RS256 sont pris en charge.

| Fournisseur | Configuration |
| --- | --- |
| Microsoft Entra ID | Application Web mono-tenant ; émetteur `https://login.microsoftonline.com/<tenant-UUID>/v2.0`. Associer l’identifiant d’objet `oid` de chaque utilisateur dans ce tenant. |
| Google Workspace | Client OAuth de type application Web ; émetteur `https://accounts.google.com`. Associer le `sub` Google. Le domaine Workspace facultatif est contrôlé sur la propriété signée `hd`. |
| Keycloak | Client confidentiel, Authorization Code, PKCE S256 et RS256 ; émetteur HTTPS du realm, par exemple `https://sso.example.com/realms/riskpilot`. Associer le `sub`. |
| Authentik | Fournisseur OAuth2/OIDC, client confidentiel, RS256 et mode émetteur par application ; émetteur `https://sso.example.com/application/o/riskpilot/`. Associer le `sub`. Le mode émetteur global n’est pas pris en charge. |
| Autre fournisseur OIDC | Émetteur HTTPS explicitement autorisé dans `OIDC_DIAGNOSTIC_ISSUERS`, endpoints sur le même hôte et port, client confidentiel, Authorization Code avec PKCE S256, signatures RS256. Associer le `sub`. |

Autoriser les émetteurs Keycloak et Authentik dans la variable serveur `OIDC_DIAGNOSTIC_ISSUERS`, sous forme de liste JSON d’URL HTTPS. Les endpoints doivent rester sur le même hôte et port ; les réseaux privés sont bloqués. Microsoft et Google utilisent leurs hôtes officiels. Une configuration inactive peut être préparée avant activation ; l’activation vérifie réellement la découverte et les signatures annoncées.

Associer les comptes déjà créés dans l’organisation à leur identifiant stable fournisseur. Une adresse email ne suffit pas et aucun rapprochement automatique par email n’est réalisé. Les permissions restent celles de RiskPilot. Il faut au moins un compte associé et un secret pour activer le SSO. Les comptes désactivés ou temporairement verrouillés sont refusés. Conserver un administrateur pouvant se connecter par mot de passe pour gérer une panne du fournisseur.

Le parcours vérifie state lié au navigateur, nonce, PKCE, signature JWKS, émetteur exact, audience, partie autorisée et dates de validité. Les états et tickets de transfert expirent après cinq minutes et sont consommés atomiquement dans Redis. Le MFA RiskPilot, y compris les codes de secours à usage unique, reste requis s’il est activé. Aucun jeton applicatif n’est placé dans une URL. Désactiver une intégration ou modifier ses comptes invalide les parcours en cours ; les sessions déjà ouvertes se révoquent dans la gestion des sessions.

La déconnexion volontaire termine la session RiskPilot puis, si le fournisseur annonce un `end_session_endpoint` HTTPS sur son propre hôte, redirige vers celui-ci (OIDC RP-Initiated Logout avec `id_token_hint`) ; enregistrer `https://<domaine-riskpilot>/login` comme URL de redirection après déconnexion chez le fournisseur. L’expiration d’une session ne déclenche pas cette redirection. SAML et les tenants Microsoft `common`/`organizations` ne sont pas pris en charge. Le provisioning SCIM 2.0, l’attribution de rôles par groupes et la déconnexion côté fournisseur sont décrits ci-dessous. Les réglages SSO sont distincts de Gmail/Microsoft Graph pour les emails.

Chaque ticket SSO autorise au maximum cinq codes MFA incorrects, indépendamment de l’adresse IP. Au-delà, le ticket est invalidé et il faut recommencer la connexion chez le fournisseur. Les erreurs MFA alimentent aussi le verrouillage temporaire du compte RiskPilot pour empêcher de contourner le plafond en ouvrant de nouveaux parcours. La limitation par IP reste appliquée et renvoie HTTP 429 avec `Retry-After` ; une origine incorrecte est refusée avec HTTP 403. L’écran empêche les doubles soumissions et distingue une annulation, un code incorrect et une session expirée.

Les tests couvrent un fournisseur simulé avec des jetons signés, les refus de signature/claims, le MFA, le rejeu et l’isolation des comptes. Une recette réelle nécessite une application enregistrée et un compte de test chez chaque fournisseur ; une découverte réussie ne prouve pas à elle seule une connexion réelle.

Références : [Microsoft — claims ID token](https://learn.microsoft.com/en-us/entra/identity-platform/id-token-claims-reference), [Google — OpenID Connect](https://developers.google.com/identity/openid-connect/openid-connect), [Authentik — OAuth2/OIDC](https://docs.goauthentik.io/add-secure-apps/providers/oauth2/).

## Provisioning SCIM 2.0

Créer dans **Paramètres → Intégrations** une clé de service avec la portée `scim:write`, puis la configurer chez le fournisseur (Keycloak avec un plugin SCIM, Authentik, Entra ID) avec l'URL `https://<domaine-riskpilot>/api/scim/v2` et le jeton Bearer. Le sous-ensemble `Users` est pris en charge : création, lecture, filtre `userName eq`, mise à jour des noms et de `active`, désactivation.

Garde-fous : les comptes créés n'ont que le rôle lecteur (les rôles se gèrent dans RiskPilot ou par les groupes SCIM décrits plus bas) ; l'adresse email n'est jamais modifiée ; la désactivation, par `active=false` ou `DELETE`, ne supprime rien et révoque les sessions ; le dernier administrateur actif ne peut pas être désactivé ; un compte verrouillé par RiskPilot reste verrouillé ; les accès sont limités à l'organisation de la clé et au débit des clés de service. Un compte créé par SCIM doit encore être associé à son identifiant fournisseur dans la configuration SSO pour pouvoir se connecter. Le `PATCH` avancé (filtres de chemin hors `members`) et les opérations en masse ne sont pas pris en charge. Les actions SCIM ne sont pas reprises dans le journal d'audit utilisateur, faute d'acteur authentifié : surveiller les journaux du serveur.

## Rôles issus des groupes du fournisseur

Dans la configuration SSO, renseigner la revendication des groupes (`groups` pour Authentik, `realm_access.roles` pour Keycloak, `roles` pour Entra) et les correspondances `groupe=ROLE_…`. À chaque connexion réussie, après le MFA, les rôles de risque, action, audit et lecture de l'utilisateur sont remplacés par ceux des groupes présents dans le jeton. Si la revendication est absente ou mal formée, les rôles ne changent pas. Les rôles d'administration (`ROLE_ADMIN`, `ROLE_SUPER_ADMIN`) ne peuvent pas figurer dans une correspondance et ne sont jamais retirés par le fournisseur.

## Groupes SCIM

Avec une clé portant les portées `scim:write` **et** `scim:groups`, l'endpoint `/api/scim/v2/Groups` expose trois groupes fixes (`ROLE_RISK_MANAGER`, `ROLE_ACTION_OWNER`, `ROLE_AUDITOR`) : l'ajout ou le retrait d'un membre (`PATCH`, `PUT`) attribue ou retire le rôle correspondant, dans l'organisation de la clé uniquement. Les groupes ne peuvent être ni créés ni supprimés, et les rôles d'administration ne sont pas exposés. La portée `scim:groups` est volontairement séparée : une clé limitée à `scim:write` ne peut jamais modifier un rôle. Choisir une seule source de vérité pour les rôles d'un même compte (groupes OIDC à la connexion ou groupes SCIM), sans quoi la dernière connexion l'emporte.

## SAML

SAML n'est pas pris en charge : la vérification des signatures XML est une surface d'attaque sensible qui ne doit pas être écrite sans recette réelle avec un fournisseur. Keycloak et Authentik peuvent représenter un fournisseur SAML amont sous forme OIDC pour RiskPilot (identity brokering).
