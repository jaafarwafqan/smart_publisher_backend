#!/usr/bin/env sh
set -eu

# Same TLS CA materialization as start.sh (docker/render/start.sh) — this
# process never goes through start.sh at all (Render's "Docker Command"
# override replaces CMD entirely, bypassing it), so it's repeated here.
if [ -n "${MYSQL_ATTR_SSL_CA_CONTENT:-}" ]; then
    printf '%s\n' "$MYSQL_ATTR_SSL_CA_CONTENT" > /tmp/mysql-ca.pem
    export MYSQL_ATTR_SSL_CA=/tmp/mysql-ca.pem
fi

# Matches docker/docker-compose.yml's queue-worker service: PublishPostJob/
# RetryDeadLetteredAttemptJob dispatch onto "publishing" explicitly
# (config/publishing.php); everything else (scheduled posts, token
# refresh, ...) uses "default". Listing only one queue leaves the other
# stuck in Redis forever.
exec php artisan queue:work --queue=publishing,default --tries=3 --backoff=10,30,60
