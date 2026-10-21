# PHP et images Docker — 21 octobre 2026

La démo utilisait PHP-FPM 8.4.26. PHP indique que les installations web,
y compris FPM, sont concernées par CVE-2026-109618 et CVE-2026-109619.
La version corrigée de la branche utilisée est PHP 8.4.27.

Sources : [avis PHP](https://www.php.net/archive/2026.php#2026-10-21-5),
[version corrigée](https://www.php.net/releases/8_4_27.php),
[empreinte des sources](https://www.php.net/releases/index.php?json&version=8.4.27).

Le Dockerfile exige désormais PHP 8.4.27, sans changement de branche.
Nginx passe de 1.27 à la version stable 1.30.6, pour le proxy et le frontend.
Les images PostgreSQL 17 et Redis 7.4 restent dans leurs branches actuelles.

## Image PHP officielle pas encore disponible

Le 21 octobre, les tags `php:8.4.27-fpm` et `php:8.4.27-fpm-alpine`
étaient absents du registre lors de la préparation. Le tag flottant 8.4
fournissait encore 8.4.26 : un simple pull ne corrigeait donc pas la faille.

`sh deploy/demo/build-php-security-base.sh` construit une base locale nommée
`riskpilot-php:8.4.27-fpm-alpine`, distincte de l'image officielle.
La recette Docker Library est figée au commit indiqué dans le script ; seules
la version, son SHA256 et la parallélisation changent. La vérification GPG
amont reste obligatoire. Le build backend accepte
`--build-arg PHP_IMAGE=riskpilot-php:8.4.27-fpm-alpine`.
Construire hors VPS, puis transférer les images applicatives. Une fois publiée,
l'image officielle corrigée pourra être utilisée sans cet argument.

Conserver sauvegarde des données et anciennes images avant activation.
Ne pas supprimer les volumes ni recharger les fixtures. Vérifier la version
effective dans backend, worker, scheduler et demo-reset-scheduler, ainsi que
la santé API, la connexion et les services PostgreSQL/Redis après livraison.
