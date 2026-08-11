#!/usr/bin/env sh
set -eu

# Same TLS CA materialization as start.sh (docker/render/start.sh) — this
# process never goes through start.sh at all (Render's "Docker Command"
# override replaces CMD entirely, bypassing it), so it's repeated here.
if [ -n "${MYSQL_ATTR_SSL_CA_CONTENT:-}" ]; then
    printf '%s\n' "$MYSQL_ATTR_SSL_CA_CONTENT" > /tmp/mysql-ca.pem
    export MYSQL_ATTR_SSL_CA=/tmp/mysql-ca.pem
fi

# Matches docker/docker-compose.yml's scheduler service — Laravel's
# scheduler has no long-running daemon of its own; schedule:run is a no-op
# unless a minute boundary has actually passed, so this loop is what
# actually invokes it once a minute.
while true; do
    php artisan schedule:run
    sleep 60
done
