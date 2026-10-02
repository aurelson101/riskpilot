# 50 améliorations ciblées — 2 octobre 2026

Changements backend, sans nouvelle dépendance ni migration. Chaque numéro
correspond à un comportement modifié, pas à une suggestion ou à un test ajouté.
Les limites de validation s'appliquent aux nouvelles écritures ; aucune donnée
existante n'est supprimée ou réécrite.

## Agenda privé — 1 à 10

1. Nom du calendrier traduit selon la langue FR/EN de l'utilisateur.
2. Description traduite : priorité, statut, risque et absence de risque lié.
3. Statut `VEVENT` corrigé : `CONFIRMED`, avec achèvement dans la description.
4. Date de création `CREATED` exportée en UTC.
5. Date de modification `LAST-MODIFIED` exportée en UTC.
6. Identifiants d'événements distincts entre instances RiskPilot.
7. Suppression des caractères de contrôle interdits dans les textes iCalendar.
8. Filtrage propriétaire, organisation et annulation directement en SQL.
9. Chargement joint des risques pour éviter les requêtes par événement.
10. Réponses de lien inconnu ou révoqué explicitement non stockables en cache.

## Exports Excel — 11 à 20

11. Ordre XML corrigé pour dimensions, filtres et cellules fusionnées.
12. Absence de fusion invalide dans un export à une seule colonne.
13. Noms de feuilles nettoyés, bornés et remplacés si vides.
14. XML sécurisé : contrôles retirés, UTF-8 invalide rejeté, en-têtes échappés.
15. Refus des nombres non finis et types de cellules non pris en charge.
16. Refus des lignes non rectangulaires ou indexées comme des objets.
17. Contrôle des dimensions maximales, en comptant les lignes de présentation.
18. Refus explicite des textes dépassant la capacité d'une cellule, sans troncature.
19. Répétition des trois lignes de présentation et d'en-tête à l'impression.
20. Horodatage des métadonnées réellement UTC, indépendamment du fuseau serveur.

## Messagerie OAuth — 21 à 30

21. Fournisseurs non OAuth refusés, sans repli implicite vers Microsoft.
22. Délais d'inactivité et durée totale bornés sur toutes les requêtes fournisseur.
23. Redirections HTTP désactivées sur ces requêtes portant des identifiants.
24. Destinataire validé avant récupération de jeton ou appel réseau.
25. Objet obligatoire, borné et sans caractères de contrôle.
26. Réponses OAuth avec jeton d'accès vide refusées.
27. Durées de jeton strictement positives, entières et bornées.
28. Jetons de renouvellement présents mais vides ou mal typés refusés.
29. Microsoft : repli sur `userPrincipalName` lorsque `mail` est vide.
30. Google : identité explicitement non vérifiée refusée.

## Configurations d'intégration — 31 à 40

31. Nom borné à 120 caractères et sans caractères de contrôle, création et édition.
32. Configuration JSON sérialisable et limitée à 64 Kio.
33. Champs de secrets connus refusés dans la configuration, y compris imbriqués.
34. Portées API exigées sous forme de liste de chaînes, sans conversion implicite.
35. Expiration API absolue et valide, sans dates relatives ou normalisées silencieusement.
36. Port LDAPS strictement entier, sans conversion de chaîne ou de booléen.
37. Champs textuels d'intégration validés avant conversion ou diagnostic.
38. Propriété des champs des connecteurs exigée comme association de noms et sources.
39. Fragments interdits dans les URL techniques HTTPS.
40. Émetteurs OIDC préparés exigés sous forme d'URL HTTPS valide.

## Plans d'action — 41 à 50

41. Coûts estimés et réels bornés aux capacités des colonnes et valeurs finies.
42. Charge estimée bornée aux capacités de sa colonne et valeurs finies.
43. Liste des preuves bornée à 100 éléments.
44. URL de preuve bornée à 2 048 caractères.
45. Identifiants des référentiels : liste bornée d'entiers positifs au niveau DTO.
46. Identifiants des exigences : même contrat de liste bornée d'entiers positifs.
47. Non-conformités : type connu et identifiant entier positif obligatoires.
48. Nombre de liens de non-conformité borné à 100.
49. Champs personnalisés : clés, taille, nombre et valeurs scalaires contrôlés.
50. Date de réalisation ne pouvant pas précéder la date de début.

## Vérification et limites

77 cas de test ciblés PHPUnit réussis (DTO, intégrations, OAuth, Excel et routes API) ; base
PostgreSQL de test séparée, sans accès aux volumes de production. Compilation
du conteneur de services Symfony vérifiée. Les tests OAuth utilisent des réponses
simulées : réception réelle Gmail/Microsoft, LDAPS externe et rendu dans les
différents clients de calendrier restent à vérifier avec des comptes dédiés.
Pas de promesse de compatibilité universelle ni de traduction complète des
notifications métier. Le diagnostic OIDC n'active pas le SSO.

Références : [iCalendar RFC 5545](https://www.rfc-editor.org/rfc/rfc5545),
[structure SpreadsheetML](https://learn.microsoft.com/en-us/office/open-xml/spreadsheet/working-with-tables),
[limites Excel](https://support.microsoft.com/en-us/excel/excel-specifications-and-limits).
