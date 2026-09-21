# Backend Symfony: nginx + PHP-FPM en un solo contenedor, con APCu para las métricas.
FROM php:8.4-fpm-alpine

RUN apk add --no-cache nginx supervisor \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers \
    && pecl install apcu \
    && docker-php-ext-enable apcu \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction

COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
    && APP_ENV=prod APP_DEBUG=0 php bin/console cache:warmup \
    && chown -R www-data:www-data var

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php-fpm-pool.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf

ENV APP_ENV=prod \
    APP_DEBUG=0

EXPOSE 80

CMD ["supervisord", "-c", "/etc/supervisord.conf"]
