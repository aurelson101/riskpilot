# Deuxième lot de 50 améliorations — 2 octobre 2026

Ce lot ne recompte aucun point du lot précédent. Modifications backend, sans
nouvelle dépendance ni migration ; les notifications historiques restent intactes.

## Notifications métier FR/EN — 1 à 13

Les nouveaux messages suivent la langue du destinataire dans l'application et
dans l'outbox email. Les dates sont localisées. La déduplication utilise le
contenu métier plutôt qu'un titre générique, et reste stable lors d'un changement
de langue : deux actions différentes ne se masquent plus par leur seul type.

1. Affectation d'une action.
2. Réaffectation d'une action.
3. Action en retard.
4. Action bientôt à échéance.
5. Expiration d'une acceptation de risque.
6. Rappel d'une revue de risque.
7. Rappel d'une échéance opérationnelle.
8. Création d'un risque critique.
9. Demande de validation d'un risque.
10. Fin d'une évaluation de conformité, avec score conservé.
11. Demande de décision d'acceptation de risque.
12. Décision d'acceptation, y compris traduction approuvée/refusée.
13. Affectation d'une campagne de revue.

## Réponses structurées de l'IA — 14 à 25

14. Réponse textuelle du pilote obligatoirement une chaîne non vide.
15. Actions proposées obligatoirement une liste JSON.
16. Champs d'action textuels contrôlés, sans conversion d'objet ou caractère de contrôle.
17. Types d'action limités aux capacités accordées par le serveur.
18. Navigation limitée aux chemins accordés par le serveur.
19. Actions dédupliquées avant la limite de trois propositions ; chemins superflus retirés.
20. Champs textuels des brouillons de risque contrôlés avant normalisation.
21. Identifiants et scores de risque strictement entiers positifs.
22. Relations de risque exigées dans le catalogue transmis à l'IA.
23. Champs textuels des brouillons d'action de conformité contrôlés.
24. Identifiant de résultat et délai strictement entiers positifs.
25. Résultat de conformité exigé dans le catalogue transmis à l'IA.

L'IA ne persiste toujours aucun objet automatiquement. Les contrôleurs conservent
leurs contrôles de tenant, de rôle, de consentement et de confirmation humaine.

## Paramètres email — 26 à 35

26. Types des champs textuels contrôlés avant toute conversion.
27. Activation strictement booléenne : une chaîne `false` ne devient plus `true`.
28. Port SMTP strictement entier.
29. Mot de passe SMTP borné à 4 096 octets et sans NUL.
30. Secret OAuth borné à 4 096 octets et sans NUL.
31. Adresses expéditeur et réponse bornées à la capacité des colonnes.
32. Identifiant SMTP et nom d'expéditeur sans caractères de contrôle.
33. Identifiant client OAuth sans caractères de contrôle.
34. Autorisation OAuth limitée aux fournisseurs email pris en charge.
35. Callback OAuth : paramètres bornés, état hexadécimal attendu, redirections
    non stockables et sans référent, durée de jeton respectée sans minimum artificiel.

## Collections GRC — 36 à 45

Les relations suivantes sont des listes DTO bornées à 500 entiers positifs :

36. Relations entre actifs.
37. Actifs affectés par une vulnérabilité.
38. Vulnérabilités d'un risque.
39. Contrôles actuels d'un risque.
40. Exigences liées à une preuve.
41. Contrôles liés à une preuve.
42. Résultats de conformité liés à une preuve.
43. Actions liées à une preuve.
44. Preuves URL d'un résultat de conformité : liste de 100 éléments maximum,
    URL de 2 048 caractères maximum.
45. Données méthodologiques du risque : 100 champs textuels bornés, sans valeurs imbriquées.

## Publication des notifications — 46 à 50

46. Destinataires inactifs ou déplacés de tenant écartés avant publication.
47. Ordre déterministe par date puis identifiant pour les réservations.
48. Message dont la publication échoue remis en attente différée avec erreur générique.
49. Reste du lot non publié également libéré ; réservations non consommées restituées.
50. Commande en échec avec nombre réellement publié, sans exposer l'exception du transport.

Le délai de reprise est de deux minutes. Le système reste à livraison au moins
une fois : un transport ayant accepté un message avant de signaler une erreur
peut entraîner une répétition. Ce lot ne récupère pas automatiquement les anciens
messages `DISPATCHED` bloqués avant son déploiement.

## Vérification

88 cas de test réussis, 389 assertions : traductions, réponses IA simulées, collections DTO,
des paramètres email, de l'outbox et des workflows GRC. PostgreSQL isolé, sans
volumes de production. Les essais d'envoi email réel et de génération IA réelle
nécessitent des comptes de test dédiés et ne sont pas annoncés comme validés.
