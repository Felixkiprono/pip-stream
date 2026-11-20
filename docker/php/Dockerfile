# Laravel (php-fpm) image for PipStream. Used by the php, events, queue and scheduler
# services. The code is bind-mounted from the repo (NanoDeploi runs `composer install` and
# `php artisan ...` inside the running php container), so this image holds only PHP itself.
FROM php:8.3-fpm-alpine

RUN apk add --no-cache icu-libs libpq libzip \
 && apk add --no-cache --virtual .build $PHPIZE_DEPS icu-dev postgresql-dev libzip-dev linux-headers \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql pcntl intl zip opcache bcmath \
 && pecl install redis-6.1.0 && docker-php-ext-enable redis \
 && apk del .build \
 && php -m | grep -qi sodium

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-pipstream.ini

WORKDIR /var/www/html
EXPOSE 9000
CMD ["php-fpm"]
