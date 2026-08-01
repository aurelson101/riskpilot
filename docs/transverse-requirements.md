# Exigences transverses

Les incréments P1, P2 et P3 partagent les mêmes portes de livraison.

## Contrôles obligatoires

- migrations Doctrine montantes et descendantes, puis validation du schéma ;
- organisation déduite du jeton, jamais acceptée depuis le payload ;
- RBAC serveur sur chaque écriture et journal d'audit avec identifiant de requête ;
- listes paginées côté serveur avec `limit <= 100` pour les espaces consolidés ;
- imports prévisualisés, atomiques et bornés à 100 lignes ;
- exports authentifiés et reproductibles ;
- catalogue FR/EN, interface responsive et audit WCAG automatisé ;
- tests de non-contournement inter-tenant et de séparation demandeur/valideur.

## Budgets initiaux

Ces budgets constituent des seuils bloquants à réévaluer avec des mesures de
production anonymisées :

| Surface | Budget |
| --- | ---: |
| Taille maximale d'une page API consolidée | 100 éléments |
| Lot d'import contrôlé | 100 lignes |
| Sources visibles par proposition | 20 objets |
| Questions proposées par génération | 10 questions |
| Échantillons d'une simulation financière | 1 000 |
| Temps cible API p95 hors import/export | 500 ms |
| Temps cible d'une page interactive p75 | 2,5 s |

La CI vérifie migrations aller-retour, schéma, PHPUnit, PHPStan, lint, build,
catalogue bilingue et Playwright desktop/mobile. Les tests de rôle couvrent au
minimum administrateur, risk manager et action owner. Les suites PHPUnit ne
doivent jamais être parallélisées sur la même base PostgreSQL de test.
