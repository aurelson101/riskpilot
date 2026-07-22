# Roadmap RiskPilot

Cette roadmap unique remplace toutes les roadmaps précédentes. Elle décrit uniquement les évolutions restant à réaliser. L’ordre tient compte des dépendances de données : la taxonomie des actifs est stabilisée avant d’enrichir les traitements, puis les non-conformités sont reliées au plan d’action avant d’ouvrir l’alimentation automatisée des indicateurs.

## Règles communes de livraison

Chaque étape comprend : migration réversible et reprise des données existantes, isolation stricte par organisation, RBAC, journal d’audit, validations côté serveur, interface responsive, exports, tests automatisés backend/frontend, documentation API, analyse statique, contrôle du code mort, rebuild Docker et smoke tests.

## Étape 1 — séparer les familles d’actifs

Créer trois entrées distinctes dans le menu **Actifs** :

- **Actifs Matériel** : serveurs, postes de travail, équipements réseau, équipements mobiles, supports et autres matériels ;
- **Actifs Logiciel** : applications, systèmes, services cloud, composants, licences et autres logiciels ;
- **Actifs Informationnel** : données, documents, bases, flux d’information, archives et autres informations.

Le modèle conserve un registre d’actifs commun afin de préserver les relations existantes avec risques, incidents, continuité et dépendances. Une propriété de famille explicite (`HARDWARE`, `SOFTWARE`, `INFORMATION`) remplace toute déduction fragile faite depuis le libellé. Les types deviennent configurables par organisation au sein de chaque famille.

Livrables et critères d’acceptation :

1. migrer et classer les actifs existants sans perte, avec rapport des types ambigus à corriger ;
2. afficher les trois sous-menus, compteurs, listes, filtres et formulaires spécialisés ;
3. permettre création, modification, import/export et archivage dans chaque famille selon les droits ;
4. conserver une recherche transversale et les relations entre actifs de familles différentes ;
5. adapter tableaux de bord, API, fixtures et documentation sans dupliquer les actifs.

## Étape 2 — personnaliser et enrichir le plan d’action

Étendre le plan d’action avec des colonnes configurables et des champs GRC structurés :

- numéro du ticket de suivi et URL du ticket ;
- origine de l’action : audit, analyse de risques, non-conformité, incident, contrôle, revue de direction, demande réglementaire ou autre ;
- type de l’action : technique, organisationnelle, humaine, physique, contractuelle ou autre ;
- liens vers un ou plusieurs référentiels et, si nécessaire, leurs exigences ;
- colonnes personnalisées définies par l’administrateur : texte, nombre, date, booléen, liste ou URL, avec ordre, visibilité et caractère obligatoire ;
- affichage et filtrage de ces champs dans la liste, le Kanban, le calendrier et les exports.

Le risque lié, actuellement obligatoire, devient facultatif. Une action doit toutefois posséder au moins une source métier valide : risque, non-conformité, audit, incident, exigence, contrôle ou origine documentée.

Livrables et critères d’acceptation :

1. valider les URL de ticket et empêcher les références vers une autre organisation ;
2. proposer des valeurs d’origine et de type administrables sans casser les valeurs historiques ;
3. gérer les relations multinormes sans dupliquer les référentiels ni les exigences ;
4. permettre à chaque utilisateur de choisir les colonnes visibles, tout en réservant leur définition aux administrateurs ;
5. inclure les nouveaux champs dans l’API, les recherches, les exports CSV et le rapport d’avancement.

## Étape 3 — relier les actions aux non-conformités

Unifier le suivi correctif en reliant explicitement un plan d’action à une ou plusieurs non-conformités issues :

- des constats d’audit internes ou externes ;
- des résultats d’évaluation de conformité ;
- des contrôles ou tests d’efficacité en échec ;
- des écarts réglementaires enregistrés.

La relation est bidirectionnelle : une non-conformité présente ses actions, responsables, échéances, preuves et avancement ; une action présente toutes les non-conformités qu’elle traite. La CAPA reste le workflow de la non-conformité, tandis que le plan d’action porte l’exécution opérationnelle.

Livrables et critères d’acceptation :

1. créer une action depuis une non-conformité en préremplissant origine, responsable, échéance et contexte ;
2. autoriser plusieurs actions par non-conformité et une action mutualisée entre plusieurs écarts ;
3. interdire la clôture d’une non-conformité tant que ses actions obligatoires ne sont pas terminées et que l’efficacité n’est pas validée indépendamment ;
4. synchroniser l’avancement sans clôture automatique irréversible et conserver l’historique des décisions ;
5. afficher les liens dans conformité, audits, plans d’action, tableaux de bord et exports.

## Étape 4 — API de saisie des valeurs d’indicateurs

Le type `INDICATOR` existe déjà dans la gouvernance exécutive, avec une valeur courante, une cible, des seuils et une période. Il manque un modèle de séries temporelles et une API dédiée pour enregistrer des mesures successives sans écraser l’historique.

Créer une API versionnée pour :

- définir un indicateur KPI ou KRI, son unité, sa fréquence, sa formule, sa source, sa cible et ses seuils ;
- enregistrer une valeur horodatée avec période, commentaire, preuve, source et clé d’idempotence ;
- saisir une valeur manuellement ou via une clé de service limitée à des indicateurs autorisés ;
- importer des valeurs par lot et retourner les erreurs ligne par ligne ;
- consulter l’historique, la tendance, le respect des seuils et les valeurs agrégées ;
- corriger une mesure par version/audit, sans effacement silencieux de l’historique ;
- déclencher les alertes et notifications lors d’un franchissement de seuil.

Livrables et critères d’acceptation :

1. endpoints sous `/api/v1/indicators` et `/api/v1/indicators/{id}/values`, documentés avec exemples ;
2. idempotence, pagination, filtres temporels, fuseaux horaires, unités et précision décimale maîtrisés ;
3. portées de clés de service séparant lecture, saisie et administration ;
4. tableaux et graphiques accessibles avec export CSV des séries ;
5. tests de concurrence, doublons, valeurs tardives, franchissements de seuil et isolation tenant.

## Ordre d’exécution

1. Actifs Matériel, Logiciel et Informationnel.
2. Champs GRC et colonnes personnalisées du plan d’action.
3. Plans d’action liés aux non-conformités.
4. API et historique des valeurs d’indicateurs.
