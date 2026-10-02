# RiskPilot : comparaison et écarts GRC

Vérification documentaire du 2 octobre 2026. Sources publiques consultées et
lecture ciblée du code ; aucun accès aux consoles privées des produits comparés.
« Présent » signifie repéré dans le code, pas testé en production dans cette passe.
Les fonctionnalités commerciales peuvent dépendre de l'édition et du contrat.

## Parcours de référence

EGERIE présente des bibliothèques, des questionnaires collaboratifs, des vues
risques/mesures/traitement, la quantification financière et des rapports.
Le parcours à adapter est : choisir le périmètre, collecter, analyser, traiter,
puis rendre compte. Ces éléments sont annoncés par l'éditeur, non testés ici.
Source : [plateforme EGERIE](https://www.egerie.com/plateforme).

CISO Assistant relie risques, contrôles, exigences et remédiations, avec une
approche API et des bibliothèques personnalisables. Sa documentation distingue
les objets de référence des objets d'évaluation ; les identifiants des bibliothèques
restent stables lors des mises à jour. Sources :
[présentation du projet](https://github.com/intuitem/ciso-assistant-community),
[modèle de données](https://github.com/intuitem/ciso-assistant-community/blob/main/documentation/architecture/data-model.md).

## Écarts par rapport au code RiskPilot

| Domaine | Présent dans RiskPilot | Complément ou validation nécessaire |
| --- | --- | --- |
| Analyse et EBIOS RM | `RiskScenario`, `AnalysisWorkspaceController`, cinq ateliers et validation indépendante | Tester un parcours complet et réduire les doubles saisies ; ne pas recréer un moteur déjà présent. |
| Bibliothèques | `StarterFrameworkCatalog`, packs adoptés/hachés, bibliothèque gouvernée et imports d'analyse | Adaptateur aux bibliothèques externes Excel/YAML non établi ; ajouter prévisualisation, versions, traductions et rapport d'erreurs. |
| Contrôles multinormes | `RequirementMapping`, preuves et SoA versionnées | Le mapping porte couverture/héritage ; compléter direction, type et provenance avant toute équivalence automatique. |
| Collecte et tiers | Questionnaires, campagnes, TPRM et relances dans le module opérationnel | Vérifier l'expérience répondant, expiration des accès, réception des relances et validation des preuves. |
| Traitement et décision | Actions, Kanban/calendrier, portefeuille et rapports décisionnels | Unifier le parcours guidé et tester les vues 360° plutôt que multiplier les menus. |
| Quantification | `DecisionWorkspaceController::quantify`, pertes annuelles et percentiles | Méthode locale à expliquer/valider ; ne pas annoncer une équivalence avec un moteur éditeur ou une estimation financière garantie. |
| Identité | JWT/MFA/session, clés de service, bind/recherche LDAPS et découverte OIDC | SSO OIDC/SAML et SCIM non raccordés ; Keycloak/Authentik non validés par le diagnostic actuel Google/Entra. |
| Messagerie | SMTP, Gmail et Graph délégués, OAuth/PKCE, reset/test FR/EN, HTML, liens internes, tentatives bornées et contrôle du destinataire | Traduction des notifications et rendu dans les clients mail à valider ; réservations bloquées et supervision à traiter. |
| Agenda | Abonnement privé `.ics`, rotation/révocation, actions affectées | Pas de CalDAV ni d'API calendrier Graph/Google ; tests Thunderbird/Outlook à réaliser. |
| IA | Copilote FR/EN, contexte consenti, brouillons gouvernés et RBAC | Évaluer couverture, qualité et réponses hors contexte ; aucun accès fournisseur direct et illimité aux API. |

Ce tableau est une analyse d'écarts ciblée, pas un inventaire exhaustif de tous
les produits. L'ordre d'exécution et les critères sont dans la [roadmap](roadmap.md).

## Modèles à adapter

La documentation CISO Assistant décrit un format de bibliothèque avec métadonnées,
hiérarchie, exigences évaluables, traductions et mappings directionnels typés.
Excel est un support d'édition documenté, avec conversion YAML. Il faut traduire
ce format vers les entités RiskPilot, pas transmettre son YAML à une API qui ne
l'accepte pas. Source :
[bibliothèques personnalisées](https://github.com/intuitem/ciso-assistant-community/blob/main/product-docs/configuration/libraries/custom-libraries.md).

Les [modèles et configurations RiskPilot](grc-models-configuration.md) proposent
un contrat original et des paramètres correspondant au code existant. Les
versions publiées des références externes devront être figées avant un import
reproductible ; les liens `main` sont des références de consultation mobiles.

## Réutilisation et limites

Cette passe n'importe aucun code ni catalogue tiers. Pour CISO Assistant,
la licence publiée distingue le code communautaire et le répertoire commercial
`enterprise` ; toute reprise effective nécessite de conserver les notices et
de vérifier les obligations applicables au fichier et à son contenu.
Source : [licences du dépôt](https://github.com/intuitem/ciso-assistant-community/blob/main/LICENSE.md).

Pour EGERIE, seuls les parcours publics sont utilisés comme inspiration ; aucune
configuration privée ou bibliothèque propriétaire n'est disponible ici. Les
textes de normes, notamment ISO, ne doivent pas être redistribués sans droits.
