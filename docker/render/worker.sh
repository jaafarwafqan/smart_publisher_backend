#!/usr/bin/env sh
set -eu

# Same TLS CA materialization as start.sh (docker/render/start.sh) — this
# process never goes through start.sh at all (Render's "Docker Command"
# override replaces CMD entirely, bypassing it), so it's repeated here.
if [ -n "${MYSQL_ATTR_SSL_CA_CONTENT:-}" ]; then
    printf '%s\n' "$MYSQL_ATTR_SSL_CA_CONTENT" > /tmp/mysql-ca.pem
    export MYSQL_ATTR_SSL_CA=/tmp/mysql-ca.pem
fi

# Matches docker/docker-compose.yml's queue-worker service.  The explicit
# Redis connection makes the production topology explicit instead of relying
# on an inherited default. PublishPostJob and
# RetryDeadLetteredAttemptJob use "publishing"; scheduler and token-refresh
# work uses "default". Listing only one queue leaves the other stuck in the
# jobs table forever.
#
# retry_after is 120 seconds in the deployment environment, deliberately
# above --timeout=60. The worker sleeps briefly while idle, retries ordinary
# worker failures at 10/30/60 seconds, and is recycled hourly so Render can
# apply fresh environment/config on its normal restart cycle.
exec php artisan queue:work redis --queue=publishing,default --tries=3 --backoff=10,30,60 --sleep=3 --timeout=60 --max-time=3600
