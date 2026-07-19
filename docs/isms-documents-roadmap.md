# Roadmap — Gestion documentaire ISMS

## Objectif

Centraliser les politiques, procédures, preuves et modèles du SMSI avec une traçabilité exploitable en audit, sans exposer les documents d'une organisation à une autre.

## Lot 1 — Socle livré

- registre documentaire par organisation avec recherche, catégorie, statut et classification ;
- création, consultation, modification, archivage et suppression ;
- contenu Markdown éditable depuis l'interface ;
- fichier Word `.doc`/`.docx` facultatif, contrôlé, limité à 10 Mo et stocké hors exposition publique ;
- historique de versions immuables avec auteur et commentaire de version ;
- empreinte SHA-256 des pièces jointes dans l’historique de versions ;
- circuit brouillon, revue et approbation nominative avec prochaine échéance ;
- propriétaire et ACL nominatives `READ`, `EDIT` et `MANAGE` ;
- visibilité interne à l'organisation ou restreinte aux personnes autorisées ;
- liens de partage externes révocables et expirables, avec protection renforcée selon la classification ;
- journalisation automatique des opérations sensibles par le système d'audit existant ;
- interface responsive intégrée au menu principal.

## Règles de sécurité

- toutes les requêtes authentifiées sont filtrées par organisation ;
- un administrateur de l'organisation peut administrer tous ses documents ;
- le propriétaire et une ACL `MANAGE` administrent les permissions et partages ;
- une ACL `EDIT` permet la mise à jour et crée une nouvelle version ;
- les jetons de partage et mots de passe ne sont conservés que sous forme d'empreinte ;
- un partage expiré ou révoqué répond comme une ressource inexistante.
- une modification de contenu, métadonnées ou pièce jointe invalide l’approbation en cours ;
- cette invalidation révoque définitivement les partages publics existants ;
- seuls les comptes actifs de la même organisation peuvent être propriétaires ou recevoir une ACL ;
- un document confidentiel ou restreint impose un mot de passe de partage ; un document restreint impose une expiration de 30 jours maximum.

## Lots suivants

1. Migration des fichiers Word locaux vers un stockage chiffré S3/MinIO avec antivirus.
2. Signature électronique et rappels automatiques des revues périodiques.
3. Modèles ISO 27001, NIS2 et DORA avec génération guidée.
4. Liens entre documents, exigences, contrôles, risques et preuves d'audit.
5. Export PDF signé, filigrane, conservation légale et corbeille restaurable.
6. Recherche plein texte PostgreSQL et indexation du contenu des pièces jointes.
