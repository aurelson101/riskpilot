# Architecture

Le monorepo sépare l’API Symfony de la SPA React. Nginx est l’unique point d’entrée : il transmet `/api` et `/docs` à PHP-FPM, et le reste au frontend Vite.

Le backend suit quatre couches : `Domain` pour les règles métier, `Application` pour les cas d’usage, `Infrastructure` pour Doctrine et les services externes, et `Api` pour les DTO et adaptateurs HTTP. Les modules fonctionnels sont découpés verticalement dans ces couches. Le tableau de bord agrège en lecture les repositories tenant-aware ; les exports réutilisent ces mêmes frontières d’organisation.

PostgreSQL conserve les données métier, Redis porte le cache et les messages asynchrones, le worker Symfony Messenger traite ces messages, et Mailpit capture les emails en développement.
