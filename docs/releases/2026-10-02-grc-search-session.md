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

## Vérification des emails FR/EN — 2 octobre 2026

Statut : vérification du code uniquement. Les suggestions ci-dessous ne sont pas
implémentées et ne font pas partie des améliorations livrées listées plus haut.

### Constats et bugs à traiter

- Les notifications métier, la réinitialisation du mot de passe et l'email de
  test utilisent des textes français ; la langue du destinataire n'est pas
  utilisée pour leur rédaction. Le parcours anglais n'est donc pas complet.
- SMTP, Gmail et Microsoft Graph envoient du texte simple, sans mise en page HTML.
  Le champ `link` enregistré dans la file de notifications n'est pas transmis
  par `DispatchNotificationOutboxHandler` : le lien métier manque dans l'email.
- Les lignes sont marquées `DISPATCHED` avant publication dans Messenger.
  La sélection suivante ne reprend que `PENDING` et `FAILED` : une interruption
  entre ces étapes peut laisser un email bloqué, sans mécanisme de reprise repéré.
- `markFailed()` prévoit un délai de reprise mais aucun nombre maximal de
  tentatives : un échec permanent peut être retenté indéfiniment.
- Le handler ne revérifie ni l'activité du destinataire ni son appartenance
  actuelle à l'organisation avant l'envoi. Une notification en attente pourrait
  transmettre des informations après désactivation ou changement d'organisation.
- Sans configuration active, le mailer de secours utilise
  `notifications@riskpilot.local` ; son adéquation à un envoi externe reste à vérifier.
- Le renouvellement OAuth conserve bien le jeton de renouvellement précédent
  lorsque le fournisseur n'en renvoie pas de nouveau (`EmailSettings::connectOauth`).

Ces constats viennent de la lecture ciblée des services, contrôleurs, entités et
handlers concernés ; les scénarios d'incident n'ont pas été reproduits.
Aucun email réel n'a été envoyé. Les tests PHP ne sont pas exécutables dans
l'environnement local de cette passe : ni PHP ni Docker ne sont disponibles.

### Dix suggestions d'amélioration — proposées, non implémentées

1. Centraliser les sujets et contenus FR/EN par type de notification, y compris
   réinitialisation du mot de passe et test de messagerie.
2. Enregistrer la langue du destinataire dans le message asynchrone, avec un
   repli explicite en français pour une langue absente ou non prise en charge.
3. Ajouter un habillage HTML commun avec une alternative texte : titre clair,
   identité RiskPilot, contenu lisible et signature cohérente.
4. Inclure les liens métier et boutons d'action ; construire les URL depuis
   l'adresse approuvée de l'application et échapper les contenus dynamiques HTML.
5. Proposer une prévisualisation FR/EN des emails avant tout envoi de test.
6. Tester chaque type d'email dans les deux langues : accents, noms longs,
   dates localisées, liens et absence de données d'une autre organisation.
7. Prévoir une reprise des messages `DISPATCHED` bloqués et des publications
   échouées, avec délai de réservation et protection contre les doublons.
8. Borner les tentatives, isoler les échecs définitifs et avertir l'administrateur ;
   prendre en compte les limites temporaires des fournisseurs lorsque disponibles.
9. Revérifier au moment de l'envoi que le destinataire est actif et appartient
   toujours à l'organisation d'origine ; annuler les notifications devenues invalides.
10. Valider l'expéditeur de secours et réaliser des tests de réception autorisés
    via SMTP, Gmail et Graph, avec comptes FR/EN et consultation des erreurs utiles
    sans exposer les secrets.

### Suite de la vérification : premier patch email

Les constats ci-dessus décrivent l'état avant ce patch. Les modifications locales
suivantes ont été réalisées, sans déploiement ni envoi réel :

- `EmailTemplateRenderer` : reset FR/EN selon le destinataire, test FR/EN selon
  l'administrateur, repli en français et corps texte cohérents.
- Les notifications envoyées incluent leur lien interne avec un libellé FR/EN ;
  les URL externes, les caractères de contrôle et les bases URL invalides sont refusés.
- Les erreurs d'envoi du test distinguent SMTP et OAuth ; un succès indique une
  transmission au fournisseur, sans prétendre prouver la réception.
- Tests ciblés dans un conteneur temporaire sans réseau, sans volumes de la démo :
  5 tests, 30 assertions ; syntaxe des contrôleurs et du handler valide.

Restent : traduction des notifications métier, HTML, reprise des réservations
bloquées, plafond de tentatives, contrôle du destinataire au moment de l'envoi
et validations de réception SMTP/Gmail/Graph.

### Deuxième patch : sécurité et limite de reprise des emails

- Cinq tentatives maximum ; classement en `DEAD_LETTER` des échecs au plafond,
  y compris des anciens messages éligibles déjà au-delà de cette limite.
- Vérification du statut et du tenant du destinataire avant appel au mailer ;
  annulation définitive en `CANCELLED` si le destinataire n'est plus éligible.
- Enregistrement du seul code `MAIL_SEND_FAILED` pour les nouveaux échecs,
  sans copie du texte de l'exception. Les erreurs historiques ne sont pas purgées.
- États terminaux protégés contre une relance via les méthodes de l'entité.
- 9 tests ciblés, 54 assertions : transitions, plafond, délai, destinataires,
  handler et transaction/réservation SQL simulée. Syntaxe PHP des trois fichiers
  applicatifs modifiés valide. Exécution isolée sans réseau ni volumes de la démo.

Aucune migration de schéma, aucun déploiement ni envoi réel. Restent la validation
de concurrence PostgreSQL en préproduction, la récupération des réservations
bloquées, la supervision, le HTML et la traduction des notifications métier.

### Recette avant publication

5 tests d'intégration, 38 assertions supplémentaires ont passé sur un PostgreSQL
17 temporaire, dans un réseau Docker interne sans accès aux volumes de la démo :
connexion/renouvellement/révocation, récupération du mot de passe FR/EN avec mailer
simulé, reset à usage unique et réservation/plafond des messages dans la base réelle.
La base et son réseau temporaires ont été supprimés après les tests.
Cette recette ne vérifie ni la réception réelle SMTP/Graph/Gmail ni les courses
entre plusieurs workers. Les limites produit précédemment indiquées restent valables.
