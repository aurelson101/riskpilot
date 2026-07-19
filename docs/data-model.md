# Modèle de données

Les agrégats prévus sont Organization, User, Scope, Asset, Threat, Vulnerability, RiskScenario, SecurityControl, ActionPlan, Framework, Requirement, ComplianceAssessment, ComplianceResult et AuditLog.

Toutes les ressources tenant-aware porteront une organisation explicite. Les associations et index seront ajoutés avec leurs migrations au fil des modules afin de conserver des changements révisables.

## Étape 3

`Scope` forme une arborescence auto-référencée et possède un responsable optionnel. `Asset` appartient obligatoirement à un périmètre et porte les niveaux de criticité, confidentialité, intégrité et disponibilité de 1 à 5. `Threat` constitue le catalogue de menaces propre à l’organisation. `Vulnerability` référence plusieurs actifs affectés via une table de jointure.

Ces quatre agrégats portent une organisation obligatoire. Les repositories ajoutent cette organisation à chaque lecture et les contrôleurs refusent toute relation pointant vers un autre tenant.

## Étape 5

`ActionPlan` relie un traitement à un `RiskScenario`, une mesure facultative et un responsable. `ActionComment` conserve les échanges horodatés. `Notification` appartient à un destinataire et à son organisation ; l’email correspondant est distribué de façon asynchrone par Messenger.

## Étape 6

`Framework` contient une arborescence de `Requirement`. `ComplianceAssessment` appartient à une organisation, un périmètre et un évaluateur. Ses `ComplianceResult` portent la maturité, le statut, les preuves et une action corrective facultative. Une contrainte garantit un seul résultat par couple évaluation/exigence.

## Étape 7

Le tableau de bord et les exports ne créent pas de nouvel agrégat : ils constituent des projections des risques, actions et résultats de conformité existants. Les fixtures assemblent un graphe cohérent de ces entités pour le développement et les démonstrations.
