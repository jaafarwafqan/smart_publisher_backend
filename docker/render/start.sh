#!/usr/bin/env sh
set -eu

# Same production-cache-on-boot contract as docker/entrypoint.sh (the
# compose-stack image): config must never ship baked into the image layer,
# so it's cached here from env vars injected by Render at container start.
if [ "${APP_ENV:-production}" = "production" ]; then
    if [ -z "${APP_KEY:-}" ]; then
        echo "APP_KEY must be injected at runtime for a production container." >&2
        exit 1
    fi

    php artisan package:discover --ansi --no-interaction
    php artisan config:clear --no-interaction
    php artisan route:clear --no-interaction
    php artisan config:cache --no-interaction
    php artisan route:cache --no-interaction
fi

# php-fpm speaks FastCGI, not HTTP, so it runs detached behind nginx here —
# nginx is what Render's port scan and traffic actually reach.
php-fpm -D

# Render assigns $PORT at runtime (not necessarily 10000); nginx can't read
# env vars directly, so render the template with envsubst. Restricting the
# substitution list to PORT keeps nginx variables like $uri untouched.
export PORT="${PORT:-10000}"
envsubst '${PORT}' < /etc/nginx/conf.d/default.conf.template > /etc/nginx/conf.d/default.conf

exec nginx -g "daemon off;"
