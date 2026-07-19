# Sécurité

Le socle prévoit JWT, Symfony Security, le rate limiting, une validation serveur et des en-têtes HTTP défensifs. Les secrets sont injectés par environnement et `.env` n’est jamais versionné.

Les prochaines étapes appliqueront l’isolation multi-tenant au niveau des repositories et politiques d’autorisation, Argon2id pour les mots de passe, des jetons courts avec renouvellement sécurisé, la journalisation des actions sensibles et des tests empêchant tout accès inter-organisation.
