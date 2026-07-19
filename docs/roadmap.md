# Roadmap d’exécution RiskPilot

Cette roadmap remplace les versions précédentes. Elle ordonne les travaux nécessaires pour faire évoluer RiskPilot d’un socle GRC opérationnel vers une plateforme Cyber GRC industrialisée. Le MFA TOTP individuel reste disponible, mais **l’obligation MFA par rôle ou organisation est volontairement exclue du périmètre**.

La cible a été confrontée aux capacités publiques d’EGERIE : modélisation bottom-up/top-down, bibliothèques d’objets liés, analyses multi-méthodes, formulaires collaboratifs, Vision 360°, traitements priorisés par coût/charge/réduction, quantification financière et reporting direction. RiskPilot conserve sa propre architecture, son modèle de sécurité multi-tenant et son identité fonctionnelle.

## Socle déjà livré

- organisations, utilisateurs, rôles, JWT et MFA TOTP facultatif ;
- périmètres, actifs, menaces, vulnérabilités et mesures ;
- risques brut, actuel et résiduel, matrice et plans d’action ;
- tableaux, Kanban, calendrier et abonnement iCalendar privé ;
- référentiels, exigences, évaluations et scores de conformité ;
- documents ISMS Markdown/Word, versions, approbation, ACL et partages ;
- tableaux de bord, exports CSV, rapport exécutif et journal d’audit ;
- SMTP et envoi OAuth 2.0 Google Workspace/Microsoft 365.

## Règle de livraison

Chaque étape doit inclure : migrations réversibles, isolation par organisation, RBAC, audit, notifications utiles, validations serveur, interface responsive, tests automatisés, analyse statique, contrôle du code mort, rebuild Docker, smoke tests et publication sur `main`.

## Étape 1 — identité, sessions et récupération

**État : réalisé.** Les codes de secours MFA à usage unique assurent la récupération du second facteur sans politique MFA obligatoire.

- refresh tokens rotatifs stockés sous forme d’empreinte ;
- registre des sessions/appareils, révocation unitaire et déconnexion globale ;
- déconnexion serveur et invalidation lors d’une désactivation de compte ;
- mot de passe oublié avec jeton à usage unique, expiration et email ;
- verrouillage progressif et audit des événements d’authentification ;
- récupération MFA encadrée, sans rendre le MFA obligatoire.

## Étape 2 — sécurité opérationnelle et preuves

**État : réalisé.** Sauvegardes réellement vérifiées, stockage local ou S3/MinIO, antivirus facultatif sans imposer le MFA, versions binaires, observabilité et audit probant sont opérationnels.

- sauvegarde automatisée PostgreSQL et objets, rétention, chiffrement et tests de restauration RPO/RTO ;
- stockage S3/MinIO, antivirus, quotas, contrôle MIME/signature et versions binaires ;
- observabilité : logs JSON corrélés, métriques, erreurs, alertes et workers ;
- audit probant : diff avant/après, export signé, chaînage d’empreintes et stockage append-only/WORM ;
- gestion et rotation des secrets, durcissement CSP et scans des images.

## Étape 3 — gouvernance et analyse des risques

**État : réalisé.** Les politiques d’appétence/tolérance/capacité, méthodes ISO 27005/EBIOS RM, acceptations formelles expirables, campagnes déléguées, consolidation du portefeuille et priorisation coût/charge/réduction sont opérationnelles.

- appétence, capacité et tolérance par organisation, domaine et famille ;
- acceptation formelle : décideur, justification, autorité, preuve et expiration ;
- campagnes de revue, délégation, relances et comparaison historique ;
- familles de risques stratégiques et consolidation bottom-up/top-down ;
- méthodes configurables ISO 27005, EBIOS RM et analyse simplifiée ;
- recommandations de traitement selon score, coût, charge et réduction attendue.

## Étape 4 — SoA, contrôles et conformité continue

**État : réalisé.** La SoA ISO 27001 dispose désormais d’un cycle versionné et approuvé, les contrôles ont des tests probants et planifiés, et les correspondances multinormes réutilisent les preuves avec leur taux de couverture et leur provenance.

- déclaration d’applicabilité ISO 27001 versionnée et exportable ;
- liens exigences ↔ mesures ↔ risques ↔ actions ↔ preuves ;
- tests de conception et d’efficacité opérationnelle des contrôles ;
- propriétaire, fréquence, échantillon, résultat, preuve et prochaine revue ;
- bibliothèques réutilisables d’exigences, mesures, menaces et actifs ;
- conformité multinorme avec héritage et réutilisation des preuves.

Les bibliothèques existantes de référentiels/exigences, mesures, menaces et actifs sont réutilisables dans les périmètres autorisés. Les relations et l’héritage n’effectuent jamais de copie opaque : la source, l’évaluation et le pourcentage de couverture restent exposés.

## Étape 5 — audits, non-conformités et CAPA

- programme annuel, missions, périmètre, équipe et indépendance ;
- constats, observations et non-conformités majeures/mineures ;
- analyse de cause, correction, action corrective/préventive et validation d’efficacité ;
- dossiers de preuve, rapport final, suivi des échéances et escalades ;
- plan d’audit et tableaux de bord de couverture.

## Étape 6 — tiers et collaboration

- registre des tiers, services, données, criticité, dépendances, contrats, SLA et plans de sortie ;
- questionnaires/formulaires versionnés, campagnes, relances et pièces justificatives ;
- préqualification, cyberscore, attestations, certifications et réévaluations ;
- risques tiers, mesures compensatoires et traitements ;
- portail externe à accès limité et consolidation multi-fournisseurs.

## Étape 7 — incidents et continuité

- incidents : qualification, chronologie, impacts, preuves, notifications et retour d’expérience ;
- processus métier, dépendances et analyse d’impact BIA ;
- MTPD, RTO, RPO, PCA, PRA et procédures de crise ;
- scénarios d’exercice, participants, résultats, écarts et amélioration ;
- liens incidents ↔ actifs ↔ tiers ↔ risques ↔ actions.

## Étape 8 — vie privée, obligations et dérogations

- registre des traitements, finalités, données, bases légales, durées et destinataires ;
- DPIA/AIPD, DPA, transferts, violations et notifications RGPD ;
- registre des obligations légales, réglementaires et contractuelles ;
- veille, responsables, échéances, preuves et conformité NIS2/DORA/RGPD ;
- dérogations/exceptions : justification, risque, compensation, approbation et expiration.

## Étape 9 — pilotage direction et quantification

- objectifs SMSI, indicateurs KPI/KRI, seuils, tendances et alertes ;
- revues de direction, décisions, participants, entrées, sorties et suivi ;
- quantification financière : fréquence, pertes, fourchettes et simulations ;
- coût/charge/ROI des traitements et scénarios d’investissement ;
- Vision 360° filtrable risques, contrôles, conformité, tiers et plans ;
- rapports direction configurables et consolidation multi-entités.

## Étape 10 — écosystème et industrialisation

- SSO OIDC/SAML Google/Microsoft et fédération d’identité ;
- SCIM, groupes et rôles automatiques ;
- API versionnée, clés de service limitées, webhooks signés et intégrations SIEM/ticketing ;
- pagination, recherche serveur, indexation et objectifs de performance ;
- tests E2E, IDOR, fuzz, ZAP, charge, accessibilité WCAG 2.2 AA et i18n ;
- CI/CD, migrations à blanc, SBOM, signature d’images et déploiement progressif.

## Critères de sortie

Une version destinée à une production critique exige : aucune vulnérabilité critique/haute connue, restauration testée, migrations validées avec retour arrière, matrice RBAC/tenant automatisée, preuves et secrets protégés, audit probant, supervision active et documentation d’exploitation approuvée.
