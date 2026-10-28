# syntax=docker/dockerfile:1
FROM docker.io/library/composer:2 AS vendor
WORKDIR /app

ENV COMPOSER_CACHE_DIR=/tmp/composer-cache

COPY composer.json composer.lock* ./
RUN --mount=type=cache,target=/tmp/composer-cache \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs

COPY . .
RUN composer dump-autoload --optimize --no-dev

FROM docker.io/library/php:8.5-fpm-alpine
WORKDIR /var/www/html

# opcache ya no se instala: PHP 8.5 lo compila estático en el binario (zend_extension innecesario,
# docker-php-ext-install opcache falla porque configure ya no expone --enable-opcache).
RUN apk upgrade --no-cache \
    && apk add --no-cache icu-dev libzip-dev oniguruma-dev libpng-dev libjpeg-turbo-dev mysql-client mariadb-connector-c rsvg-convert \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install pdo_mysql mbstring bcmath intl zip pcntl posix gd \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl channel-update pecl.php.net \
    && pecl install redis-6.3.0 \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY docker/php/opcache-custom.ini /usr/local/etc/php/conf.d/zz-opcache-custom.ini
COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY docker/php-fpm/www.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

COPY --from=vendor /app ./

RUN mkdir -p /var/exportaciones /var/importaciones /var/conciliacion /var/respaldos storage/app/private storage/app/public \
    && chown -R www-data:www-data storage bootstrap/cache /var/exportaciones /var/importaciones /var/conciliacion /var/respaldos

EXPOSE 9000
ENTRYPOINT ["/entrypoint.sh"]
CMD ["php-fpm"]
