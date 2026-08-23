# Évaluation des formats de rapports

## Périmètre et décision

Les PDF RiskPilot restent le format canonique de diffusion et le JSON signé par son empreinte SHA-256 le format de preuve machine. L’export PDF fournit aujourd’hui un titre, une langue, des métadonnées, un identifiant stable, un sommaire lisible, des en-têtes de tableaux répétés et des règles de coupure de page. Ces mesures améliorent l’usage et l’auditabilité, mais ne constituent pas une certification PDF/UA ou PDF/A.

## PDF/A et accessibilité

Dompdf ne garantit pas à lui seul un profil PDF/A ni un balisage PDF/UA complet. Une trajectoire fiable nécessite :

- une chaîne de rendu capable de produire XMP, profil ICC, polices incorporées et structure balisée ;
- une validation automatique avec veraPDF pour PDF/A et PAC/équivalent pour PDF/UA ;
- des tests visuels FR/EN et des tests au lecteur d’écran sur les modèles réels ;
- l’archivage du rapport, de son profil de conformité et du résultat du validateur.

Décision : ne pas afficher de mention PDF/A ou PDF/UA tant que cette chaîne et ses preuves ne sont pas disponibles.

## Signature électronique

Une simple image de signature ne fournit aucune garantie cryptographique. Une signature exploitable doit être PAdES, horodatée et produite avec un certificat géré dans un HSM ou un service de signature qualifié. Le choix du prestataire, le niveau eIDAS attendu, les rôles autorisés, la conservation des preuves et la révocation doivent être validés avant intégration.

Décision : conserver pour l’instant l’empreinte SHA-256, l’identifiant documentaire et l’approbation du modèle ; ne pas simuler une signature électronique.

## Export PPTX

Le PPTX apporte de la valeur uniquement pour les comités qui retravaillent une présentation selon une charte graphique. Il introduit toutefois une seconde mise en page à maintenir, facilite la modification hors gouvernance et ne préserve pas naturellement la preuve du contenu approuvé.

Décision : ne pas ajouter immédiatement de dépendance PPTX. Réévaluer après validation d’un modèle de diapositives d’entreprise et d’un besoin récurrent. Si retenu, le PPTX sera un dérivé clairement marqué, lié à l’identifiant et à l’empreinte du PDF canonique.

## Traitement asynchrone

La génération asynchrone est activée à partir de 100 objets métier (risques, actions et évaluations) ou lorsque le client envoie `Prefer: respond-async`. Le contrat crée un `REPORT_RUN` en état `IN_PROGRESS`, le finalise via Symfony Messenger, puis le passe à `COMPLETED`. L’export reste indisponible tant que le traitement n’est pas terminé.

L’instantané tenant-scoped est figé avant mise en file afin d’éviter qu’un traitement différé ne change la période ou les données approuvées. La prochaine évolution pourra déplacer également la constitution de l’instantané dans le worker si les mesures de production montrent que cette étape domine la latence.
