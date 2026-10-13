# Connexion SSO OIDC

Dans **Paramètres → Intégrations → Connexion SSO**, renseigner le fournisseur, le nom affiché sur l’écran de connexion, l’émetteur, l’identifiant client et le secret client. Le secret est chiffré avec la clé dérivée d’APP_SECRET et n’est jamais renvoyé par l’API. En modification, laisser le secret vide pour le conserver.

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

La déconnexion termine la session RiskPilot ; elle ne ferme pas la session chez le fournisseur d’identité. SAML, SCIM, les tenants Microsoft `common`/`organizations` et l’attribution automatique de rôles ne sont pas pris en charge. Les réglages SSO sont distincts de Gmail/Microsoft Graph pour les emails.

Chaque ticket SSO autorise au maximum cinq codes MFA incorrects, indépendamment de l’adresse IP. Au-delà, le ticket est invalidé et il faut recommencer la connexion chez le fournisseur. Les erreurs MFA alimentent aussi le verrouillage temporaire du compte RiskPilot pour empêcher de contourner le plafond en ouvrant de nouveaux parcours. La limitation par IP reste appliquée et renvoie HTTP 429 avec `Retry-After` ; une origine incorrecte est refusée avec HTTP 403. L’écran empêche les doubles soumissions et distingue une annulation, un code incorrect et une session expirée.

Les tests couvrent un fournisseur simulé avec des jetons signés, les refus de signature/claims, le MFA, le rejeu et l’isolation des comptes. Une recette réelle nécessite une application enregistrée et un compte de test chez chaque fournisseur ; une découverte réussie ne prouve pas à elle seule une connexion réelle.

Références : [Microsoft — claims ID token](https://learn.microsoft.com/en-us/entra/identity-platform/id-token-claims-reference), [Google — OpenID Connect](https://developers.google.com/identity/openid-connect/openid-connect), [Authentik — OAuth2/OIDC](https://docs.goauthentik.io/add-secure-apps/providers/oauth2/).
