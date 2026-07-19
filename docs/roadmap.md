# Roadmap RiskPilot

Cette roadmap compare l’état exécutable du produit aux capacités attendues d’une plateforme GRC/ISMS exploitable. Les priorités suivent le risque métier : **P0** bloque une production critique, **P1** complète la gouvernance GRC, **P2** prépare l’industrialisation et **P3** étend le produit.

Les états utilisés sont `Réalisé`, `En cours`, `À faire` et `À étudier`. Chaque capacité livrée doit inclure tests automatisés, contrôle RBAC et multi-tenant, documentation, migration éventuelle, rebuild Docker et smoke test.

## Capacités livrées

- authentification JWT, contrôle des comptes actifs, RBAC et isolation multi-tenant ;
- MFA TOTP individuel avec QR code et codes de secours ;
- administration des organisations, utilisateurs, profils et rôles ;
- périmètres, actifs, menaces, vulnérabilités et mesures de sécurité ;
- registre, matrice et calcul des risques brut, actuel et résiduel ;
- plans d’action avec tableaux, Kanban, calendrier, commentaires et notifications ;
- référentiels, exigences et évaluations de conformité ;
- tableau de bord, exports CSV sécurisés et rapport exécutif imprimable ;
- journal d’audit des opérations API ;
- SMTP par organisation et envoi OAuth 2.0 Google Workspace/Microsoft 365 ;
- documents ISMS avec versions, approbation, ACL, Word, catégories dynamiques et partages protégés.

La suite documentaire détaillée est suivie dans la [roadmap ISMS](isms-documents-roadmap.md).

## P0 — prérequis de production critique

| Domaine | Écart restant | Résultat attendu | État |
| --- | --- | --- | --- |
| Sessions | JWT court sans refresh token ni registre de sessions | Rotation des jetons, révocation immédiate, déconnexion serveur, historique des appareils et fermeture globale | À faire |
| Récupération de compte | Aucun flux « mot de passe oublié » | Jeton à usage unique, expiration courte, notification et audit de la récupération | À faire |
| Politique d’authentification | MFA facultatif et politique de mot de passe minimale | MFA obligatoire configurable par rôle/tenant, délai d’enrôlement, verrouillage progressif et contrôle des mots de passe compromis | À faire |
| Preuves et pièces jointes | Plusieurs preuves restent représentées par des URL | Stockage privé chiffré, antivirus, quotas, contrôle MIME/signature et journal de téléchargement | À faire |
| Sauvegarde et reprise | Procédure manuelle uniquement | Sauvegardes PostgreSQL/fichiers/Redis automatisées, chiffrement, rétention, RPO/RTO et test de restauration périodique | À faire |
| Observabilité | Santé technique sans métriques ni alertes | Logs JSON corrélés, métriques, traces, alertes, suivi d’erreurs et tableaux de bord d’exploitation | À faire |
| Audit probant | Journal modifiable par l’administrateur de base et sans scellement | Rétention configurable, export signé, chaînage d’empreintes et stockage append-only/WORM | À faire |
| Secrets | Chiffrement applicatif présent mais rotation manuelle | Gestionnaire de secrets, rotation de `APP_SECRET`, JWT et clés OAuth sans perte de données, procédure documentée | À faire |
| Durcissement production | Reverse proxy fourni mais validation opérationnelle manuelle | CSP stricte, cookies/en-têtes revus, limites de requêtes globales, scan d’images et guide de durcissement vérifié | À faire |

## P1 — gouvernance GRC/ISMS complète

| Module | Écart restant | Résultat attendu | État |
| --- | --- | --- | --- |
| Acceptation des risques | Statuts présents sans décision formelle | Workflow de traitement/acceptation, approbateur, justification, date d’expiration et preuve de décision | À faire |
| Cycle de revue des risques | Révision et historique limités | Campagnes de revue, rappels, comparaison avant/après et traçabilité des changements de score | À faire |
| Déclaration d’applicabilité | Évaluations disponibles sans SoA ISO 27001 dédiée | Applicabilité, justification, état d’implémentation, preuve, responsable et export de la SoA | À faire |
| Cartographie des contrôles | Liens partiels entre risques, actions et contrôles | Relations exigences ↔ contrôles ↔ risques ↔ actions ↔ preuves, avec couverture et détection des écarts | À faire |
| Référentiels | CRUD API plus riche que l’interface | Gestion graphique des exigences, versions, arborescences et import contrôlé de contenus sous licence | À faire |
| Gestion des tiers | Aucun registre fournisseur dédié | Tiers, services, criticité, contrats, évaluations, risques, attestations et échéances | À faire |
| Incidents | Aucun cycle de gestion d’incident | Déclaration, qualification, chronologie, impacts, notifications, causes, actions et retour d’expérience | À faire |
| Continuité | Aucun BIA/PCA/PRA métier | Processus critiques, BIA, dépendances, RTO/RPO, plans, exercices et résultats | À faire |
| Audits et contrôles | Conformité sans programme d’audit | Plans d’audit, missions, constats, non-conformités, preuves et suivi des remédiations | À faire |
| Propriétaires d’actifs | Inventaire fonctionnel mais gouvernance limitée | Cycle de vie, propriétaire métier/technique, classification CIA, revue et date de sortie | À faire |
| Notifications | Couverture partielle des événements | Matrice d’événements configurable : risques, conformité, documents, audits, tiers et escalades | À faire |
| Journal d’audit | Pas de comparaison structurée avant/après | Diff lisible, filtres avancés, export, corrélation de requête et recherche par ressource | À faire |

## P2 — qualité, volume et exploitation

| Sujet | Écart restant | Résultat attendu | État |
| --- | --- | --- | --- |
| Registres volumineux | Listes souvent chargées intégralement | Pagination, recherche, filtres et tri côté API avec index PostgreSQL adaptés | À faire |
| Tests navigateur | Vitest présent, Playwright configuré mais sans parcours métier | E2E connexion/MFA et CRUD critiques, matrice ACL, partage ISMS et tests responsive | À faire |
| Tests de sécurité | Tests RBAC/tenant présents mais pas de campagne automatisée | Tests IDOR, fuzz des entrées, dépendances, ZAP et non-régression des permissions | À faire |
| API | Contrôleurs personnalisés partiellement documentés | OpenAPI complet, exemples, pagination standard, erreurs normalisées et stratégie de versionnement | À faire |
| Performance | Lazy loading livré, mesures de charge absentes | Budgets frontend, cache HTTP, profilage SQL, tests de charge et objectifs de latence | À faire |
| Accessibilité | Base MUI accessible sans audit formel | Audit WCAG 2.2 AA, navigation clavier, lecteur d’écran, contrastes et tests automatisés | À faire |
| Internationalisation | Libellés français dans le code | Catalogues FR/EN, formats régionaux et préférence par utilisateur | À faire |
| Exploitation asynchrone | Worker opérationnel sans console métier | File d’échec administrable, reprise contrôlée, métriques et idempotence des messages | À faire |
| Cycle de livraison | Build/test locaux sans chaîne complète décrite | CI avec tests, scans, migrations à blanc, SBOM, signature des images et déploiement progressif | À faire |

## P3 — extensions stratégiques

| Capacité | Objectif | État |
| --- | --- | --- |
| SSO d’entreprise | OpenID Connect/SAML Google et Microsoft, liaison de comptes et politiques conditionnelles | À étudier |
| Provisioning | SCIM, groupes, rôles automatiques et désactivation centralisée | À étudier |
| API et webhooks | Clés de service limitées, webhooks signés et intégrations SIEM/ticketing | À étudier |
| Portail direction | Tendances, appétence au risque, objectifs, attestations et vues multi-entités | À étudier |
| Registre réglementaire | Obligations, autorités, échéances et notifications NIS2/DORA/RGPD | À étudier |
| Bibliothèque de contenus | Modèles guidés ISO 27001, NIS2, DORA et packs sectoriels sous licence compatible | À étudier |

## MFA et messagerie

| Priorité | Capacité | État |
| --- | --- | --- |
| P0 | MFA TOTP individuel, QR code et codes de secours | Réalisé |
| P0 | Secrets TOTP/SMTP/OAuth chiffrés et masqués dans l’API/audit | Réalisé |
| P0 | SMTP2GO et SMTP personnalisé par organisation | Réalisé |
| P0 | OAuth 2.0 Gmail API et Microsoft Graph avec renouvellement | Réalisé |
| P0 | Politique MFA obligatoire par rôle/organisation | À faire |
| P1 | Récupération MFA encadrée par double contrôle administrateur | À faire |
| P1 | Modèles HTML, SPF/DKIM/DMARC et suivi des rebonds | À faire |
| P2 | Console de file d’échec, reprise et diagnostic de délivrabilité | À faire |
| P3 | SSO OIDC/SAML et provisioning SCIM | À étudier |

## Ordre d’exécution recommandé

1. Sécuriser les sessions, la récupération de compte et la politique MFA.
2. Automatiser sauvegarde/restauration, observabilité, gestion des secrets et audit probant.
3. Sécuriser toutes les preuves et pièces jointes avec antivirus et stockage chiffré.
4. Livrer l’acceptation des risques, la SoA et la cartographie contrôles/risques/preuves.
5. Ajouter tiers, incidents, continuité et programme d’audit.
6. Introduire pagination API, E2E, tests de sécurité et CI de production.

## Critères de sortie d’une version de production

- aucune vulnérabilité critique/haute connue dans les dépendances ou images ;
- migrations testées sur une copie de production et retour arrière documenté ;
- restauration complète exécutée dans les objectifs RPO/RTO ;
- tests RBAC, multi-tenant, MFA, ACL et parcours critiques réussis ;
- journaux, métriques, alertes et file asynchrone supervisés ;
- secrets injectés hors Git et procédure de rotation validée ;
- documentation d’exploitation, sécurité et réponse à incident approuvée.
