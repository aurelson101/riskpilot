# Modèle de données

Les agrégats prévus sont Organization, User, Scope, Asset, Threat, Vulnerability, RiskScenario, SecurityControl, ActionPlan, Framework, Requirement, ComplianceAssessment, ComplianceResult et AuditLog.

Toutes les ressources tenant-aware porteront une organisation explicite. Les associations et index seront ajoutés avec leurs migrations au fil des modules afin de conserver des changements révisables.

## Étape 3

`Scope` forme une arborescence auto-référencée et possède un responsable optionnel. `Asset` appartient obligatoirement à un périmètre et porte les niveaux de criticité, confidentialité, intégrité et disponibilité de 1 à 5. `Threat` constitue le catalogue de menaces propre à l’organisation. `Vulnerability` référence plusieurs actifs affectés via une table de jointure.

Ces quatre agrégats portent une organisation obligatoire. Les repositories ajoutent cette organisation à chaque lecture et les contrôleurs refusent toute relation pointant vers un autre tenant.
