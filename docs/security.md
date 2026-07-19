# Sécurité

Le socle prévoit JWT, Symfony Security, le rate limiting, une validation serveur et des en-têtes HTTP défensifs. Les secrets sont injectés par environnement et `.env` n’est jamais versionné.

L’isolation multi-tenant et le RBAC sont appliqués à tous les modules. Les tableaux de bord et exports sont calculés uniquement depuis les données de l’organisation authentifiée. Les valeurs CSV commençant par un marqueur de formule sont neutralisées avant téléchargement.

Les mutations réussies sont inscrites dans un journal tenant-aware avec l’auteur, la ressource, la date, l’adresse IP et les données d’entrée. Les champs contenant un mot de passe ou un jeton sont remplacés par `[REDACTED]`. Les suppressions d’utilisateurs, organisations, risques et actions sont logiques afin de préserver les responsabilités et l’historique.

## Contrôles implémentés à l’étape 2

- mots de passe Argon2id via Sodium ;
- JWT signé d’une durée de 15 minutes ;
- MFA TOTP facultatif par utilisateur avec codes de secours à usage unique ;
- hiérarchie RBAC centralisée ;
- statut actif exigé par le chargeur d’utilisateurs ;
- requêtes utilisateurs et organisations contraintes par le tenant ;
- réponse 404 pour une ressource appartenant à un autre tenant ;
- tests fonctionnels couvrant la liste, l’accès direct et le refus RBAC.

Les clés JWT locales sont ignorées par Git. En production, elles doivent être fournies par le gestionnaire de secrets. Les secrets TOTP, mots de passe SMTP, secrets clients OAuth et jetons Google/Microsoft sont chiffrés avec libsodium à partir de `APP_SECRET` : ce secret doit rester stable, unique et conservé dans le gestionnaire de secrets. Les connexions OAuth utilisent un état aléatoire à usage unique expirant après dix minutes et ne demandent que les droits d’envoi et d’identification du compte. Lorsqu’un JWT local expire ou devient invalide, l’interface efface la session et renvoie vers la connexion au lieu de conserver un écran de chargement. Le renouvellement sécurisé et la révocation centralisée des sessions restent nécessaires avant la mise en production.

Les documents ISMS sont filtrés par organisation puis par propriétaire/ACL lorsqu’ils sont restreints. Les liens externes reposent sur 256 bits aléatoires et seule leur empreinte SHA-256 est conservée. Les mots de passe de partage utilisent Argon2id, les dates d’expiration et révocations sont contrôlées à chaque accès, et les tentatives publiques sont limitées par adresse IP et jeton.
