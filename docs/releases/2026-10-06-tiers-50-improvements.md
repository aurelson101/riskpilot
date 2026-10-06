# Tiers : 50 améliorations ciblées — 6 octobre 2026

Lot centré sur le registre des tiers et les évaluations fournisseurs, sans nouveau menu ni migration. Les questionnaires sont des points de départ modifiables, pas des audits certifiants. Les scores restent issus d'une revue humaine. Aucun envoi d'email automatique ni écriture automatique par l'IA n'est ajouté.

## Registre

1. Total des tiers correspondant aux filtres.
2. Compteur des tiers actifs dans cette sélection.
3. Compteur des tiers de criticité haute ou critique.
4. Compteur des tiers nécessitant un suivi selon les critères existants.
5. Compteur des tiers ayant une évaluation validée.
6. Tri alphabétique localisé, avec identifiant pour départager les homonymes.
7. Tri par criticité décroissante.
8. Tri par prochaine évaluation, dates absentes en dernier.
9. Pagination du registre, 12 tiers par défaut.
10. Choix de 12, 24 ou 48 tiers par page.
11. Réinitialisation des filtres et retour à la première page.
12. Recherche étendue aux contacts, contrats, SLA, catégories et certifications déclarées.
13. Filtre par responsable, issu du registre visible sans charger l'annuaire.
14. Filtre selon la présence d'une évaluation validée.
15. Export CSV de toute la sélection filtrée : 19 colonnes, UTF-8 BOM, séparateur point-virgule, échappement et neutralisation des formules. Un tiers non évalué conserve un score vide ; un vrai score zéro est conservé. Aucun lien confidentiel n'est exporté.
16. Signalement des contrats arrivant à échéance dans les 30 jours.
17. Signalement des évaluations prévues dans les 30 jours.
18. Signalement du contact absent pour les tiers non terminés.
19. Signalement de la réversibilité non documentée des tiers prioritaires.
20. Signalement de l'évaluation non planifiée des tiers prioritaires.

## Préparation des campagnes

21. Duplication d'une question avec un nouvel identifiant unique.
22. Déplacement des questions vers le haut ou le bas sans changer leurs identifiants.
23. Compteur de caractères par question, limite de 2 000.
24. Compteur de caractères du titre, limite de 200.
25. Sélection explicite du responsable du tiers comme évaluateur ; contrôle de visibilité toujours effectué côté serveur.
26. Modèle modifiable pour prestataire cloud : localisation, chiffrement, restauration et sortie.
27. Modèle modifiable pour traitement de données personnelles : données, transferts, droits et incidents.
28. Modèle modifiable pour service critique : reprise, notification, dépendances et réversibilité.
29. Confirmation avant abandon d'un brouillon modifié et avertissement de fermeture/rechargement, sans stockage local.
30. Messages distincts pour accès refusé, saisie invalide et service indisponible ; saisies conservées.

## Réponse du fournisseur

31. Progression du nombre de réponses complétées, sans score de conformité.
32. Effacement explicite d'une réponse individuelle.
33. Compteur de caractères par réponse, limite de 4 000.
34. Accès direct au premier champ de réponse manquant.
35. Signalement des réponses vides ou composées d'espaces après interaction.
36. Normalisation des sauts de ligne CR, LF et CRLF dans les références.
37. Déduplication exacte des références après suppression des espaces périphériques.
38. Compteur des références distinctes, limite de dix.
39. Expiration du formulaire lorsque sa date limite est atteinte, même si la page reste ouverte.
40. Après un rejet 422, relecture du statut pour reconnaître une soumission concurrente ; aucune réémission automatique.
41. Avertissement avant fermeture/rechargement en présence de réponses non envoyées, conservées uniquement en mémoire.

## Validation et protection des données

42. Identifiant du responsable strictement entier positif et compatible avec la base, avant recherche tenant-scoped.
43. Contact email textuel, normalisé, valide et limité aux 180 caractères du schéma.
44. Criticité et statut strictement textuels et issus des valeurs autorisées.
45. Nom strictement textuel, non vide et limité à 200 caractères.
46. Catégories de données : liste textuelle bornée, normalisée et dédupliquée.
47. Certifications déclarées : liste textuelle bornée, normalisée et dédupliquée ; aucune vérification implicite.
48. Champs de profil typés et bornés avant mutation : contrat/SLA à 200 caractères, textes à 10 000.
49. Validation du format des liens publics avant interrogation de la base, canonicalisation de la casse, réponses du contrôleur non stockables et sans transmission du referrer, y compris les rejets de JSON malformé.
50. Commentaire de revue limité à 10 000 caractères côté API et interface, avec compteur ; un rejet ne valide pas l'évaluation.

## Vérification et livraison

Validation : 111 tests ciblés de l'interface, 31 tests API sur PostgreSQL isolé (481 assertions), compilation TypeScript et build de production. Les essais navigateur de la démo ont validé le téléchargement CSV réel (19 colonnes, filtrage et neutralisation des formules), le registre à 375/768/1440 pixels CSS, la campagne à 375 pixels, le questionnaire anglais à 375/768/1440 pixels et français à 375 pixels, puis la soumission anonyme et la revue humaine. Les données fictives ont été supprimées et le registre préexistant est resté identique.

Le contrôle final de livraison vérifie l'alignement du commit GitHub/démo et la santé des services. Le score individuel d'une évaluation non validée est également omis du CSV, même si l'API contient un zéro par défaut.

Les avertissements de fermeture dépendent du comportement du navigateur et ne remplacent pas une sauvegarde. Les références du fournisseur restent des déclarations textuelles non vérifiées. Le contrôle des sauvegardes vérifie les empreintes et les archives ; il ne constitue pas un exercice complet de restauration.
