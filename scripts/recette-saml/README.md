# Recette SAML + SCIM avec Keycloak

Rejoue de bout en bout, sur une machine Docker **isolée de toute production**, le provisioning SCIM, la connexion SAML,
les rôles issus des groupes, le rejeu, la falsification de signature et le refus d'un compte non provisionné.

- `realm.json` : realm Keycloak `riskpilot` (utilisateurs `alice` dans le groupe `riskpilot-auditors`, `bob` sans groupe, client SAML).
- `up.sh` : démarre Keycloak 26 et une instance RiskPilot (serveur PHP intégré, base `app_recette`). Il suppose le répertoire
  `~/rp-dev` (copie du dépôt, `backend/vendor` installé, `test.env`, conteneurs `rpdev-pg` et `rpdev-redis` sur le réseau `rpdev-net`)
  et une image `riskpilot-backend`; adapter les chemins et le tag.
- `driver.py` : pilote la recette (bibliothèque standard seulement) ; variables `KC_IP` et `APP_IP` (adresses des conteneurs).

Les noms d'hôte `keycloak.recette.test` et `rp.recette.test` sont fictifs : le pilote les transmet dans l'en-tête `Host` et
`X-Forwarded-*`, ce qui évite tout accès public. Résultat du 2026-11-21 : 16 vérifications sur 16.
