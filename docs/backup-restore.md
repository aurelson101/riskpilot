# Sauvegarde et restauration

## Objectifs

- RPO cible : 24 heures avec une exécution quotidienne ; réduire l’intervalle pour les environnements critiques.
- RTO cible : 4 heures, à confirmer par un exercice trimestriel.
- Rétention par défaut : 30 jours, réglable avec `BACKUP_RETENTION_DAYS`.
- Périmètre : PostgreSQL, Redis et fichiers des Documents ISMS.

## Sauvegarder

Exécuter `scripts/backup.sh /chemin/dédié/riskpilot`. Le script crée un répertoire horodaté, génère les trois sauvegardes et un manifeste SHA-256, puis purge uniquement les répertoires horodatés expirés. Le répertoire cible doit être chiffré au repos (volume LUKS/KMS, coffre objet avec chiffrement serveur) et répliqué hors site.

Vérifier chaque sauvegarde avec `scripts/restore-verify.sh /chemin/dédié/riskpilot/<horodatage>`.

## Restaurer lors d’un exercice planifié

1. Isoler une plateforme de recette et vérifier le manifeste avec `restore-verify.sh`.
2. Arrêter `backend` et `worker`, puis recréer une base vide.
3. Restaurer PostgreSQL avec `gzip -dc postgresql.sql.gz | docker compose exec -T postgres psql -U <utilisateur> <base>`.
4. Extraire `isms-documents.tar.gz` dans le volume `/app/var/isms-documents` d’un conteneur backend arrêté.
5. Arrêter Redis, remplacer son fichier de données par `redis.rdb`, puis le redémarrer.
6. Redémarrer les services, exécuter les migrations, contrôler `/api/health`, un téléchargement documentaire et l’intégrité du journal d’audit.
7. Consigner la durée, les écarts au RPO/RTO et les actions correctives dans une preuve d’audit.

Une restauration est destructive : elle n’est volontairement pas automatisée par le script de vérification. Tester trimestriellement sur un environnement isolé avant toute restauration de production.
