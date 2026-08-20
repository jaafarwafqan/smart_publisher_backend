#!/usr/bin/env sh
set -eu

# Managed MySQL providers (Aiven, PlanetScale, ...) require TLS and hand out
# a CA certificate, but config/database.php's MYSQL_ATTR_SSL_CA option wants
# a file path, not the PEM text itself. MYSQL_ATTR_SSL_CA_CONTENT carries the
# actual certificate as an env var (Render has no writable-file secret type);
# materialize it before Laravel reads its database configuration.
if [ -n "${MYSQL_ATTR_SSL_CA_CONTENT:-}" ]; then
    printf '%s\n' "$MYSQL_ATTR_SSL_CA_CONTENT" > /tmp/mysql-ca.pem
    export MYSQL_ATTR_SSL_CA=/tmp/mysql-ca.pem
fi

# Production configuration is cached only after runtime environment variables
# have been injected; nothing sensitive is baked into the image layer.
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

# Render's Pre-Deploy Command is not reliable for this Docker runtime, so
# outstanding forward migrations run here. `set -e` makes a failure terminate
# startup; this script never invokes migrate:fresh, rollback, or destructive
# recovery commands automatically.
if [ -n "${DB_CONNECTION:-}" ] && [ "${DB_CONNECTION}" != "sqlite" ]; then
    # Read-only guard before a billing migration assigns legacy organizations
    # to Free or legacy-grandfathered. It exits non-zero for an invalid active
    # plan, making configuration drift visible before any rows are changed.
    php artisan billing:preflight-free-tier --no-interaction
    php artisan migrate --force --no-interaction

    # Normal Web Service starts must not alter application data. Bootstrap a
    # deliberately fresh database only with this one-shot flag, then return it
    # to false. Seed failures are fatal for the same reason as migrations.
    case "${SP_INITIALIZE_DATABASE:-false}" in
        true)
            php artisan db:seed --force --no-interaction

            # Demo fixtures are staging-only and explicit, never an every-boot
            # side effect.
            if [ "${APP_ENV:-}" = "staging" ]; then
                php artisan db:seed --class=DemoDataSeeder --force --no-interaction
            fi
            ;;
        false|'')
            ;;
        *)
            echo "SP_INITIALIZE_DATABASE must be true or false." >&2
            exit 1
            ;;
    esac
fi

# php-fpm speaks FastCGI, not HTTP, so it runs detached behind nginx here.
php-fpm -D

# Render assigns $PORT at runtime (not necessarily 10000); nginx cannot read
# env vars directly, so render the template with envsubst. Restricting the
# substitution list to PORT keeps nginx variables such as $uri untouched.
export PORT="${PORT:-10000}"
envsubst '${PORT}' < /etc/nginx/conf.d/default.conf.template > /etc/nginx/conf.d/default.conf

exec nginx -g "daemon off;"
