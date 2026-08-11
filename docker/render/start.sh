#!/usr/bin/env sh
set -eu

# Managed MySQL providers (Aiven, PlanetScale, ...) require TLS and hand out
# a CA certificate, but config/database.php's MYSQL_ATTR_SSL_CA option wants
# a file path, not the PEM text itself. MYSQL_ATTR_SSL_CA_CONTENT carries the
# actual certificate as an env var (Render has no writable-file secret type);
# materialize it to disk before anything (notably config:cache below) reads
# MYSQL_ATTR_SSL_CA.
if [ -n "${MYSQL_ATTR_SSL_CA_CONTENT:-}" ]; then
    printf '%s\n' "$MYSQL_ATTR_SSL_CA_CONTENT" > /tmp/mysql-ca.pem
    export MYSQL_ATTR_SSL_CA=/tmp/mysql-ca.pem
fi

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

# Render's "Pre-Deploy Command" dashboard field is a no-op for Docker-runtime
# services (confirmed via API — PATCHing it does not persist), so migrations
# run here instead. Idempotent and safe to run on every boot; skipped when no
# real database is configured (DB_CONNECTION unset defaults to sqlite, which
# nothing here provisions).
if [ -n "${DB_CONNECTION:-}" ] && [ "${DB_CONNECTION}" != "sqlite" ]; then
    # Non-fatal: a migration failure (e.g. the database is still waking up)
    # must not crash-loop the whole container — nginx/php-fpm still need to
    # start so the deploy goes live and the real error is visible over HTTP
    # instead of only in a failed-deploy build log.
    php artisan migrate --force --no-interaction \
        || echo "WARNING: php artisan migrate failed; starting web server anyway" >&2
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
