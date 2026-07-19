# Modèle de données

Les agrégats prévus sont Organization, User, Scope, Asset, Threat, Vulnerability, RiskScenario, SecurityControl, ActionPlan, Framework, Requirement, ComplianceAssessment, ComplianceResult et AuditLog.

Toutes les ressources tenant-aware porteront une organisation explicite. Les associations et index seront ajoutés avec leurs migrations au fil des modules afin de conserver des changements révisables.
