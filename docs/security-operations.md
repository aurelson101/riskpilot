# Exploitation de sécurité

- Conserver `APP_SECRET`, les clés JWT, mots de passe et secrets OAuth dans un coffre de secrets, jamais dans Git ni dans une image.
- Faire tourner les secrets au moins annuellement, après tout départ privilégié et après suspicion d’exposition. La rotation JWT déconnecte les sessions ; la rotation `APP_SECRET` exige de reconnecter les intégrations chiffrées.
- Activer ClamAV avec `docker compose --profile antivirus up -d clamav`, puis définir `CLAMAV_HOST=clamav` et redémarrer backend/worker. En mode activé, l’indisponibilité de ClamAV bloque les nouveaux fichiers.
- Pour MinIO, définir `DOCUMENT_S3_ENDPOINT=http://minio:9000`, le bucket, la région et des identifiants robustes, puis lancer `docker compose --profile object-storage up -d minio minio-init`. Chiffrer le volume MinIO ou configurer son KMS. Sur AWS, laisser l’endpoint vide : l’application demande SSE-S3 AES-256 ; activer aussi versioning, Object Lock et une politique de rétention sur le bucket.
- Exporter régulièrement le journal signé, contrôler `/api/audit-logs/integrity` et placer l’export dans un stockage objet verrouillé (Object Lock/WORM) géré hors de l’application.
- Superviser `/api/health`, l’état Docker du worker, les erreurs HTTP et la profondeur Messenger. Déclencher une alerte si PostgreSQL/Redis est indisponible, si le worker devient unhealthy ou si la chaîne d’audit est rompue.
- Le workflow `Security` bloque les vulnérabilités, secrets ou mauvaises configurations de sévérité haute/critique détectés dans le dépôt et ses dépendances.
