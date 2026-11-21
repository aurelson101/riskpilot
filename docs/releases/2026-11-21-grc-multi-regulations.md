# Version 2026.11.21 — GRC multi-réglementaire, identité et IA

## Conformité et réglementation
- Packs AI Act, DORA et CRA, correspondances multinormes suggérées.
- Registre des notifications DORA, CRA et AI Act avec échéances et rappels quotidiens ; inventaire des produits CRA ; registre des accords TIC DORA (droit applicable, préavis, dernier audit) ; lien entre vulnérabilités et signalements CRA.

## Identité
- SSO SAML 2.0 (signature vérifiée en mode strict, SHA-1 refusé, anti-rejeu), déconnexion unique, assertions chiffrées.
- Rôles issus des groupes du fournisseur (OIDC et SAML), déconnexion fournisseur OIDC.
- SCIM 2.0 utilisateurs et groupes, `externalId` et rattachement à la connexion SSO, écritures dans l'audit scellé.
- Recettes réelles avec Keycloak 26 : `scripts/recette-saml/`.

## IA
- Fournisseur auto-hébergé (Ollama, vLLM, LiteLLM…) limité aux adresses déclarées par l'exploitant (`AI_SELF_HOSTED_ENDPOINTS`).
- Contexte réglementaire pour le copilote, jeu d'évaluation `app:ai:evaluate` à 18 questions.

## Exports
- Exporteur CSV commun, Excel avec vraies dates et feuille « Informations », classeur multi-feuilles, PDF avec synthèse, preuve d'audit CSV/Excel/PDF.
- Chaque téléchargement est journalisé dans l'audit scellé et limité en débit.

## Qualité
- PHPStan sans erreur (niveau 6), style PHP propre, 492 tests backend, 246 tests frontend.
- Migrations : `Version20261121071846` (rattachement SCIM), `Version20261121081147`, `Version20261121090149` (DORA et CRA).

## Limites connues
- Recette OIDC réelle et recette Authentik non réalisées (émetteur HTTPS public requis).
- Dates et articles réglementaires à confirmer auprès des textes officiels.
