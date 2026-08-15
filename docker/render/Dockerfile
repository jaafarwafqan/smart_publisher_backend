# Smart Publisher backend — single-container image for Render Web Service.
#
# Render runs one container per service and health-checks it by scanning for
# an open HTTP port; it does not run docker-compose. docker/Dockerfile (the
# compose-stack image) only runs php-fpm speaking FastCGI on :9000, which is
# why Render's port scan times out ("No open HTTP ports detected"). This
# image bundles nginx + php-fpm together and serves plain HTTP on $PORT,
# which Render injects at runtime (TLS is terminated at Render's edge).
#
# Point Render's "Dockerfile Path" at docker/render/Dockerfile and keep the
# Docker build context at the repo root.
#
# Also shared by the queue-worker and scheduler Background Worker services
# (same image, same env vars) via Render's "Docker Command" override:
# /usr/local/bin/worker-render and /usr/local/bin/scheduler-render replace
# CMD entirely for those two, bypassing start-render (and its nginx/PORT
# setup) — each is a standalone script, not a start-render subcommand.

FROM php:8.3-fpm

RUN apt-get update && apt-get install -y \
        git \
        unzip \
        libzip-dev \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        libonig-dev \
        nginx \
        gettext-base \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mbstring \
        zip \
        gd \
        bcmath \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

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
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

COPY docker/render/nginx.conf.template /etc/nginx/conf.d/default.conf.template
COPY docker/render/start.sh /usr/local/bin/start-render
COPY docker/render/worker.sh /usr/local/bin/worker-render
COPY docker/render/scheduler.sh /usr/local/bin/scheduler-render
RUN chmod +x /usr/local/bin/start-render /usr/local/bin/worker-render /usr/local/bin/scheduler-render \
    && rm -f /etc/nginx/sites-enabled/default

# Documents the default; Render overrides via the $PORT env var at runtime —
# nginx.conf.template is rendered against the real value in start.sh.
EXPOSE 10000

CMD ["/usr/local/bin/start-render"]
