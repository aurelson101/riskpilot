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
- catégories libres générant automatiquement des sous-menus après filtrage tenant/ACL ;
- vue d’ensemble présentant les 10 documents accessibles les plus récents avec résumé.

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

## P0 — stockage et conservation

1. Migrer les fichiers locaux vers un stockage objet chiffré S3/MinIO avec antivirus, quotas et journal de téléchargement.
2. Ajouter une corbeille restaurable, une politique de rétention, le gel légal et une purge contrôlée à double validation.
3. Préserver le fichier binaire de chaque version, et pas uniquement ses métadonnées et son empreinte.
4. Sauvegarder et restaurer conjointement métadonnées, ACL, partages et objets documentaires.

## P1 — gouvernance documentaire

1. Ajouter signature électronique, commentaires de revue, délégation et rappels automatiques des échéances.
2. Gérer les catégories comme ressources administrables : description, icône, ordre, responsable, archivage et déplacement contrôlé des documents.
3. Relier documents, exigences, contrôles, risques, actions, audits et preuves avec indicateurs de couverture.
4. Fournir des modèles ISO 27001, NIS2 et DORA avec génération guidée et licence clairement identifiée.
5. Ajouter accusé de lecture, campagnes d’attestation et statistiques de diffusion par population.

## P2 — recherche et diffusion

1. Ajouter recherche plein texte PostgreSQL, filtres serveur, pagination et indexation du contenu des pièces jointes.
2. Produire des exports PDF signés avec filigrane, numéro de version, classification et empreinte vérifiable.
3. Proposer prévisualisation Markdown/Word sécurisée, comparaison visuelle entre versions et restauration des métadonnées.
4. Ajouter des liens publics à usage unique, limitation du nombre de consultations et liste d’adresses autorisées.
5. Tester automatiquement la matrice ACL complète, les expirations, les accès concurrents et les volumes élevés.
