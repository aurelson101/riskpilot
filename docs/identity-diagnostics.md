# Diagnostics d'identité et formulaires accessibles

La messagerie (SMTP, Gmail API, Microsoft Graph) reste distincte de l'identité
(OIDC, SAML, LDAPS). Un diagnostic réussi n'active pas le SSO, le provisioning
SCIM ni la connexion des utilisateurs à l'annuaire.

## OIDC

Google utilise `https://accounts.google.com`. Microsoft Entra utilise
`https://login.microsoftonline.com/<UUID-tenant>/v2.0` : pas `common` ni un
tenant inventé. La découverte contrôle l'issuer, Authorization Code, les
algorithmes de signature et indique si PKCE S256 est annoncé.

Keycloak et Authentik nécessitent une autorisation serveur explicite, en plus
du rôle administrateur dans RiskPilot. Dans le fichier d'environnement du
backend, par exemple :

```dotenv
OIDC_DIAGNOSTIC_ISSUERS=["https://sso.example.com/realms/riskpilot","https://auth.example.com/application/o/riskpilot/"]
```

Utiliser les URL réelles de vos serveurs, avec un certificat HTTPS vérifiable.
La liste vide par défaut bloque toute découverte personnalisée. Les redirections
HTTP sont désactivées, les appels sont bornés à dix secondes et les métadonnées
à 256 Kio. Aucun secret ni jeton d'accès n'est demandé pour ce diagnostic.
Ne pas désactiver TLS pour un serveur interne ; installer sa CA dans le trust
store du conteneur. Le mode issuer global d'Authentik n'est pas pris en charge
par ce diagnostic : utiliser le mode par application.

Références : [Microsoft OIDC](https://learn.microsoft.com/en-us/entra/identity-platform/v2-protocols-oidc),
[Keycloak](https://www.keycloak.org/securing-apps/oidc-layers),
[Authentik](https://docs.goauthentik.io/add-secure-apps/providers/oauth2/).

## LDAPS

Les préréglages proposent Active Directory (`sAMAccountName`) ou OpenLDAP
(`inetOrgPerson` / `uid`). Adapter les DN et le filtre au schéma réel. Le port
reste 636 et la vérification du certificat est obligatoire. Le filtre substitue
un nom d'utilisateur échappé ; les referrals sont désactivés. Le compte de bind
doit avoir uniquement les droits de lecture nécessaires. La configuration est
enregistrée inactive et le mot de passe chiffré, sans activation de connexion.
Le diagnostic de bind/recherche ne prouve pas l'application des mappings de
groupes et ne remplace pas une recette sur l'annuaire réel. La recherche doit
trouver exactement un utilisateur : zéro résultat ou plusieurs correspondances
ne donnent plus un diagnostic positif.

## Accessibilité

Les listes MUI non natives utilisent un élément `div` avec rôle `combobox` et
un nom accessible via `aria-labelledby`. Le thème commun retire leur attribut
HTML `for` invalide. Les champs texte et les listes HTML natives conservent leur
association `label for` / `id`. Aucun identifiant de champ n'est réécrit.
