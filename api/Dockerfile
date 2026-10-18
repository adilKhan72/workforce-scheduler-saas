FROM php:8.5-cli-alpine

RUN apk add --no-cache postgresql-libs \
    && apk add --no-cache --virtual .build-deps postgresql-dev $PHPIZE_DEPS \
    && docker-php-ext-install pdo_pgsql pcntl \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && php artisan package:discover --ansi

EXPOSE 8000

# Dev-grade server; production would run php-fpm or FrankenPHP behind a proxy.
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
