# Messagerie et IA : protection des identifiants

- Un changement de fournisseur, serveur ou compte SMTP exige de saisir le mot de passe ; les modifications d’expéditeur seules conservent le secret existant.
- Un nouvel identifiant client OAuth exige son propre secret ; les changements de nom d’expéditeur ne demandent pas de ressaisie.
- Les clés IA sont limitées à 4 096 caractères et les espaces ou caractères de contrôle internes sont refusés.
- Une erreur de déconnexion OAuth reste visible, sans annoncer une déconnexion réussie. Les opérations concurrentes sont bloquées et les modifications saisies pendant la requête sont conservées.
- Les messages de validation IA sont affichés dans le formulaire.
- Les nouveaux événements d’audit masquent les clés API, y compris les variantes `api_key` et `X-API-Key`, dans la requête et les valeurs avant/après. Le masquage intervient avant le scellement de l’événement.

## Historique

Les journaux déjà scellés et les sauvegardes ne sont pas réécrits. Si une installation a enregistré de vraies clés avec une version antérieure, contrôler les accès aux historiques et renouveler les clés exposées. Ne pas supprimer ni modifier directement des événements scellés pour les nettoyer.

## Vérification

Tests ciblés de validation SMTP/IA, masquage et intégrité de l’audit, déconnexion OAuth et conservation des brouillons. Aucun email réel ni appel fournisseur IA n’est nécessaire à ces tests.
