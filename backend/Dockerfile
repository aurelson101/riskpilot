FROM php:8.4-fpm-alpine AS base

RUN apk add --no-cache fcgi git icu-libs libpq libzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libpq-dev libzip-dev \
    && docker-php-ext-install -j$(nproc) intl opcache pdo_pgsql zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && wget -qO /usr/local/bin/php-fpm-healthcheck https://raw.githubusercontent.com/renatomefi/php-fpm-healthcheck/master/php-fpm-healthcheck \
    && chmod +x /usr/local/bin/php-fpm-healthcheck

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock symfony.lock* ./

FROM base AS development
ENV APP_ENV=dev
COPY . .
RUN composer install --prefer-dist --no-interaction --no-progress \
    && mkdir -p var/isms-documents \
    && chown -R www-data:www-data var
CMD ["php-fpm", "-F"]

FROM base AS production
ENV APP_ENV=prod APP_DEBUG=0
COPY . .
RUN composer install --no-dev --classmap-authoritative --no-interaction --no-progress \
    && mkdir -p var/cache var/log var/isms-documents \
    && chown -R www-data:www-data var
USER www-data
CMD ["php-fpm", "-F"]
