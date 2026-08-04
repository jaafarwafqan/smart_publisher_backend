#!/usr/bin/env sh

set -eu

# Docker injects environment variables before this entrypoint runs. Caching
# here keeps production configuration out of immutable image layers.
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

exec "$@"
