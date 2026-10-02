# Lot GRC : 20 améliorations, 20 optimisations, 20 protections

Ce lot privilégie la recherche transverse, la décision et les échanges API.
Il reprend les principes de simplification et d'interconnexion décrits par
[CISO Assistant](https://github.com/intuitem/ciso-assistant-community), sans copier son code.
Ce n'est ni une déclaration de parité fonctionnelle ni une certification de sécurité.
Les écritures IA restent soumises au consentement, aux droits et à la confirmation humaine.

## 20 améliorations fonctionnelles

1. Filtrer la recherche par famille de dossier GRC.
2. Afficher les compteurs de chaque famille, même avec un filtre actif.
3. Classer les correspondances exactes et les titres en premier.
4. Proposer un tri alphabétique alternatif.
5. Choisir 20 ou 50 résultats par page.
6. Conserver recherche, filtres et pagination dans une URL partageable.
7. Effacer la recherche avec la touche Échap.
8. Ajouter un bouton d'effacement explicite.
9. Expliquer la longueur autorisée du mot-clé.
10. Afficher un état de chargement annoncé aux technologies d'assistance.
11. Réessayer une recherche en échec sans ressaisir les filtres.
12. Guider l'utilisateur avant la première recherche.
13. Proposer une démarche lorsqu'aucun résultat n'est accessible.
14. Remplacer les codes techniques de catégories par des libellés français/anglais.
15. Surligner la correspondance dans les titres et extraits.
16. Utiliser de vrais liens accessibles au clavier et ouvrables dans un nouvel onglet.
17. Adapter les cartes et les textes longs aux petits écrans.
18. Séparer les échéances futures à 30 jours des actions déjà en retard.
19. Retirer les risques clos ou archivés du classement des priorités.
20. Appliquer les seuils de l'organisation aux couleurs des priorités.

Compléments : liens du tableau de bord vers les registres, bouton de réessai,
et scores par référentiel limités aux évaluations terminées.

## 20 optimisations

1. Filtrer les correspondances en SQL plutôt qu'après chargement de tous les objets.
2. Retourner des lignes scalaires sans hydrater les entités et associations.
3. Appliquer LIMIT/OFFSET en base.
4. Calculer les totaux en base.
5. Réunir les familles en UNION ALL : une requête de compteurs et une de résultats au maximum.
6. Vérifier les ACL documentaires avec EXISTS, sans charger les utilisateurs et permissions.
7. Éviter la requête de résultats lorsqu'aucune correspondance n'existe.
8. Ne lire que la famille sélectionnée dans la requête paginée.
9. Limiter les extraits transmis à 400 caractères.
10. Stabiliser le tri par titre, catégorie et identifiant pour la pagination.
11. Réutiliser les résultats de recherche pendant 30 secondes.
12. Libérer les recherches inactives du cache après deux minutes.
13. Éviter une nouvelle recherche à chaque retour de focus sur l'onglet.
14. Annuler les requêtes de recherche devenues inutiles.
15. Remplacer les répétitions automatiques de recherche par un réessai volontaire.
16. Réutiliser le tableau de bord pendant 30 secondes.
17. Annuler le chargement du tableau de bord après navigation.
18. Précharger les relations utilisées par l'export des risques en une requête.
19. Précharger responsable, risque et mesure pour l'export des actions.
20. Mutualiser le renouvellement de session lors de réponses 401 simultanées.

Compléments : réutilisation d'un jeton déjà renouvelé, pas de réessai des erreurs
HTTP 4xx, consolidation des boucles de calcul du tableau de bord,
délai réseau borné et libération systématique des URL de téléchargement.
Ces optimisations ne constituent pas un benchmark de performance.

## 20 renforcements de sécurité

1. Limiter la recherche à 160 caractères côté serveur.
2. Valider strictement la page : entier entre 1 et 1000.
3. Valider strictement la taille de page : entier entre 1 et 50.
4. Autoriser uniquement les catégories connues.
5. Autoriser uniquement les tris connus.
6. Traiter %, _ et ! comme caractères littéraux, pas comme jokers SQL.
7. Limiter la recherche à 60 requêtes par minute, par utilisateur et organisation.
8. Interdire le stockage partagé des réponses API authentifiées avec private/no-store.
9. Refuser les destinations API absolues, interdomaines ou malformées.
10. Supprimer tout en-tête Authorization sur les routes publiques de connexion.
11. Refuser un jeton de renouvellement vide, non textuel ou excessivement long.
12. Empêcher un renouvellement en cours de rétablir une session terminée.
13. Effacer les données métier en cache lors d'un changement de compte.
14. Refuser les types MIME inattendus lors des téléchargements d'exports.
15. Refuser les fichiers d'export vides.
16. Refuser les fichiers d'export de plus de 50 Mio.
17. Retirer les chemins des noms de fichiers téléchargés.
18. Retirer les contrôles et marqueurs bidirectionnels trompeurs des noms de fichiers.
19. Exiger une extension cohérente avec le format d'export demandé.
20. Valider les identifiants de requête avant leur utilisation dans les journaux.

Les filtres SQL utilisent des paramètres liés ; l'isolation par organisation et
les ACL documentaires existantes sont conservées. Les tests couvrent les documents
restreints et partagés, les caractères spéciaux, les pages invalides, les réponses
401 simultanées, la déconnexion pendant un renouvellement et les téléchargements.
Les petits écrans et le parcours de recherche doivent être contrôlés en navigateur
avant d'annoncer la livraison terminée.
