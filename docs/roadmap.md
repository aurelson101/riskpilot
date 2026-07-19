# Roadmap RiskPilot

Cette roadmap compare le cahier des charges initial à l’état exécutable du produit. Les priorités tiennent compte du risque métier, de la sécurité et de la valeur utilisateur.

## État actuel

Le MVP couvre l’authentification JWT, le RBAC et l’isolation multi-tenant, les inventaires, risques, actions, notifications, référentiels, évaluations de conformité, tableaux de bord, exports CSV, administration, fixtures et journal d’audit. Les opérations métier principales sont disponibles depuis l’interface.

## P0 — indispensable avant une production critique

| Sujet | Écart constaté | Résultat attendu | État |
|---|---|---|---|
| Sessions | JWT court sans refresh token ni révocation centralisée | Rotation, révocation, déconnexion serveur et liste des sessions | À faire |
| Récupération de compte | Pas de flux « mot de passe oublié » | Jeton à usage unique, email et expiration | À faire |
| Pièces jointes | Les preuves sont actuellement des URL | Téléversement privé, antivirus, quotas et contrôle MIME | À faire |
| Sauvegarde et reprise | Procédure seulement documentée | Sauvegardes automatisées et exercice de restauration | À faire |
| Observabilité | Pas de métriques, traces ni alertes | Logs structurés, métriques, alertes et suivi d’erreurs | À faire |

## P1 — finalisation fonctionnelle

| Sujet | Écart constaté | Résultat attendu | État |
|---|---|---|---|
| Rapports | CSV basiques, pas de rapport visuel/PDF | CSV enrichis et rapport exécutif imprimable avec graphiques | Réalisé |
| Registres volumineux | Listes chargées intégralement | Pagination, recherche, filtres et tri côté API | À faire |
| Risque détaillé | Registre éditable mais fiche dédiée limitée | Vue détaillée, duplication, historique et actions liées | À faire |
| Référentiels | CRUD API plus complet que l’interface | Gestion graphique des exigences et arborescences | À faire |
| Audit | Valeurs nouvelles disponibles, pas de diff avant/après | Diff structuré, filtres et export du journal | À faire |
| Notifications | Couverture partielle des événements demandés | Révision des risques et clôture conformité complètes | À faire |

## P2 — qualité et industrialisation

| Sujet | Écart constaté | Résultat attendu | État |
|---|---|---|---|
| Tests navigateur | Vitest présent, Playwright absent | Parcours E2E connexion et CRUD critiques | À faire |
| Performance frontend | Bundle principal supérieur à 1 Mo | Découpage par route et chargement différé | Réalisé |
| API publique | Documentation partielle des contrôleurs personnalisés | Schéma OpenAPI complet, exemples et versionnement | À faire |
| Accessibilité | Composants MUI accessibles mais audit incomplet | Audit WCAG 2.2 AA et corrections clavier/contrastes | À faire |
| Internationalisation | Interface française en dur | Catalogue de traductions et formats régionaux | À faire |

## Ordre d’exécution recommandé

1. Terminer les rapports et exports, car ils apportent une valeur immédiate sans modifier le modèle métier.
2. Sécuriser les sessions et la récupération de compte avant toute exposition internet.
3. Ajouter le stockage sécurisé des preuves et la sauvegarde automatisée.
4. Introduire pagination, recherche et filtres avant une montée en volume.
5. Couvrir les parcours critiques avec Playwright, puis optimiser le bundle frontend.

Chaque élément doit inclure tests automatisés, contrôle RBAC et multi-tenant, documentation, migration si nécessaire, rebuild Docker et smoke test.
