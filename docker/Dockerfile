# Smart Publisher backend — PHP-FPM image.
# New as of 2026-07-27; this project had no Docker setup before this pass.
# Paired with docker-compose.yml (Nginx + MySQL + Redis) in this same directory.

FROM php:8.3-fpm

RUN apt-get update && apt-get install -y \
        git \
        unzip \
        libzip-dev \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        libonig-dev \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mbstring \
        zip \
        gd \
        bcmath \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Redis extension (used for cache/queue in production — see docker-compose.yml)
RUN pecl install redis && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY --chown=www-data:www-data . .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction --no-scripts \
    && mkdir -p \
        storage/app/public \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && install -m 0555 docker/entrypoint.sh /usr/local/bin/entrypoint

EXPOSE 9000
USER www-data
ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["php-fpm"]
