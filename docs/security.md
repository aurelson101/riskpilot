# Sécurité

Le socle prévoit JWT, Symfony Security, le rate limiting, une validation serveur et des en-têtes HTTP défensifs. Les secrets sont injectés par environnement et `.env` n’est jamais versionné.

L’isolation multi-tenant et le RBAC sont appliqués dès l’étape 2. Les étapes suivantes compléteront la révocation des sessions, la journalisation des actions sensibles et les politiques propres aux nouveaux domaines métier.

## Contrôles implémentés à l’étape 2

- mots de passe Argon2id via Sodium ;
- JWT signé d’une durée de 15 minutes ;
- hiérarchie RBAC centralisée ;
- statut actif exigé par le chargeur d’utilisateurs ;
- requêtes utilisateurs et organisations contraintes par le tenant ;
- réponse 404 pour une ressource appartenant à un autre tenant ;
- tests fonctionnels couvrant la liste, l’accès direct et le refus RBAC.

Les clés JWT locales sont ignorées par Git. En production, elles doivent être fournies par le gestionnaire de secrets. Le renouvellement sécurisé et la révocation des sessions seront ajoutés avant la mise en production.
