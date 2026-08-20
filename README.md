# Smart Publisher — API Backend

Laravel 13 API backend for Smart Publisher, a social-media publishing platform. Consumed by a Flutter client (`smart_publisher`, sibling repo — no shared git history, but the two are developed together). This is not the stock Laravel starter README — see below for what's actually built here.

**Status (2026-08-16):** Phase 1 MVP backend surface is complete and live-tested (including a real publish through a connected Telegram bot and a real Facebook Page). Full current gap list, and the single source of truth for test counts: [`docs/audit/KNOWN_ISSUES.md`](../smart_publisher/docs/audit/KNOWN_ISSUES.md) and [`docs/testing/STATUS.md`](../smart_publisher/docs/testing/STATUS.md) (both live in the Flutter repo's docs tree since that's where the project's documentation was originally centralized).

## What's actually here

- **Auth**: Sanctum access+refresh rotation, rate-limited login/refresh. Mobile and desktop use bearer tokens; Flutter Web opts into Secure, httpOnly cookies so JavaScript never receives or persists raw session tokens.
- **Multi-tenancy**: real organizations (`organizations`/`organization_memberships` tables), not single-tenant. Every tenant-scoped model resolves through `TenantContext`, set per-request by `ResolveTenantContext` middleware from a client-supplied `X-Organization-Id` header — verified against the caller's actual membership, never trusted blindly. A user can belong to multiple organizations and switch between them.
- **RBAC**: two layers. Spatie `laravel-permission` (`admin`/`manager`/`editor`, ~35 granular permissions) still gates a handful of global/admin-only actions (system settings, legacy user management). Per-organization authorization is a separate `OrganizationRole` enum (`owner`/`admin`/`manager`/`editor`/`viewer`) with its own fixed permission template (`OrganizationPermission`), checked via `User::hasOrganizationPermission()` — this is what most tenant-scoped resources (posts, social accounts, members) actually authorize against.
- **Organization billing**: every new and migrated organization has a Free subscription; entitlement checks fail closed when subscription data is absent or invalid. Owner-only Stripe Checkout, customer portal, signed webhooks, and idempotent webhook storage are implemented. A paid plan still requires a business-approved local price and a real `stripe_price_id` before checkout is exposed.
- **Posts**: draft → scheduled → publishing → published/failed lifecycle, real per-page targeting (`post_targets` pivot), per-platform caption overrides (`meta.platform_content`), idempotent delivery (`post_publication_attempts.idempotency_key`, DB-unique).
- **Social accounts**: the closed beta deploys only Facebook Pages and Telegram channels. Facebook uses OAuth; Telegram connects a per-account bot token. Other provider code/catalog entries are not closed-beta deployment integrations and require no deployment secrets.
- **Media**: upload with type-aware size limits (20MB image / 500MB video / 50MB document), real MIME validation, sha256-based duplicate detection (informational, never blocking), image compression, thumbnail generation (ffmpeg for video if available, graceful `null` degradation if not).
- **Scheduling**: `ProcessScheduledPostsJob` runs every minute, transitions posts to an intermediate `publishing` status immediately (prevents the duplicate-dispatch storm documented in the Production Readiness Audit), dispatches `PublishPostJob` per target page with retry/backoff and a dead-letter sink.
- **Analytics**: real per-page metrics (`post_metrics`) where the provider actually supplies them; `is_available` is an honest flag, never a fabricated zero. Best-platform/best-publish-hour are `null` below a minimum sample size rather than a false-confidence guess.
- **System settings**: per-provider OAuth app configuration (client id/secret/endpoints) editable at runtime, with an audit log that records which *fields* changed, never the values.
- **Backup/restore**: `app:backup-database` (SQLite via `VACUUM INTO`, MySQL via `mysqldump --single-transaction`) and `app:restore-database`, scheduled daily. Both refuse `:memory:` databases and remind you that `APP_KEY` must travel with any backup (encrypted columns become permanently undecryptable otherwise).

## What's genuinely NOT here (verified absent, not just undocumented)

- **No webhook receiver** — nothing listens for platform delivery/engagement webhooks. An old doc describing one was aspirational fiction; see `docs/api/integrations.md` in the Flutter repo.
- **No invented paid pricing** — the checkout flow is operational, but the business must supply approved plan prices and matching Stripe Price IDs. A plan without `stripe_price_id` is intentionally not purchasable.
- **`tiktok`/`snapchat`/`pinterest`/`other` providers** are in the whitelist and route safely to the mock provider, but have no `config/social.php` entry — their real token-refresh path would throw if ever exercised.
- **`AccountController`/`/accounts/*` routes have been removed entirely** (Sprint 2, API Hardening) — `index()` was the only implemented method; `connect`/`show`/`update`/`destroy` had no controller methods at all and 500'd on every call. They are not merely deprecated: they don't exist in `routes/api.php` anymore (see the comment there). The real, complete implementation is `SocialAccountController` at `/users/{user}/social-accounts/*` — build against that.
- **`/api/v1/users`** lists/manages users — this is a *global* Spatie-permission-gated resource (`users.view`/`users.update`/etc.), scoped to the caller's current organization membership (fixed as part of a 2026-08-01 security pass); it is not a per-organization "team members" listing on its own — use `/organizations`/membership endpoints for that.

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Requires MySQL (`DB_CONNECTION=mysql` in `.env`) for anything beyond a single queue worker — **SQLite must never run with more than one concurrent queue worker**; `PDO`'s `busy_timeout` does not reliably gate multi-process contention on Windows even when explicitly configured (verified live during the Production Readiness Audit). Tests use SQLite in-memory (`phpunit.xml`) and are unaffected by this.

```bash
php artisan migrate
php artisan db:seed   # AdminUserSeeder + RolesAndPermissionsSeeder + BranchSeeder
php artisan serve
```

Set `ADMIN_EMAIL`/`ADMIN_PASSWORD` in `.env` before seeding — `AdminUserSeeder` refuses known-weak/default passwords (`Admin@123456`, `CHANGE_ME_BEFORE_DEPLOY`, `password`, etc.) when `APP_ENV=production`.

Every tenant-scoped endpoint requires an authenticated request to resolve an active organization: send `X-Organization-Id: <id>` (verified server-side against real membership — a wrong/omitted value never grants access, it just falls back to the user's last-used org, then their first active membership). `User::booted()` auto-provisions a personal organization for any user created outside an existing tenant context, so a freshly-seeded admin always has one to work with.

For concurrent local testing (Flutter driving several requests at once), the built-in dev server benefits from:

```bash
PHP_CLI_SERVER_WORKERS=4 php artisan serve
```

### Console commands

| Command | Schedule | Purpose |
|---|---|---|
| `app:backup-database` | daily | Consistent DB backup (VACUUM INTO / mysqldump) |
| `app:restore-database {file}` | manual | Restore from a backup file (`--force` to skip confirmation) |
| `oauth-providers:health-check` | daily 03:00 | Re-verify each provider's app-level OAuth credentials |
| `social-pages:sync` | hourly | Re-discover/re-verify every connected account's pages/channels |
| `post-metrics:sync` | hourly | Fetch real engagement metrics for recently published posts |
| *(job, not a command)* `ProcessScheduledPostsJob` | every minute | Dispatch due scheduled posts |

Run the scheduler with `php artisan schedule:work` in development, or a real cron entry (`* * * * * php artisan schedule:run`) in production. It remains separate from the queue worker: the scheduler enqueues the minutely publishing sweeps, while the worker consumes them.

### Tests

```bash
php artisan test
```

Current status: see [`docs/testing/STATUS.md`](../smart_publisher/docs/testing/STATUS.md) in the Flutter repo.

## Documentation map

Documentation for this backend is centralized in the Flutter repo's `docs/` tree (a historical choice from early scaffolding, kept for continuity):

| Area | File |
|---|---|
| API reference (OpenAPI 3.0) | `../smart_publisher/docs/api/openapi_v1.yaml` |
| Postman collection | `../smart_publisher/docs/api/postman_collection.json` |
| Database ERD | `../smart_publisher/docs/database/erd.md` |
| Roles/permissions (+ honest no-plans/subscriptions note) | `../smart_publisher/docs/architecture/permissions_and_roles.md` |
| OAuth/integrations | `../smart_publisher/docs/api/integrations.md` |
| Known issues / incomplete features | `../smart_publisher/docs/audit/KNOWN_ISSUES.md` |
| Architectural decisions | `../smart_publisher/docs/architecture/decisions/` |

## CI/CD

`.github/workflows/ci.yml` runs two jobs: a `quality-gate` job (gitleaks secret
scan, `composer validate`, Pint formatting, PHPStan/Larastan static analysis,
`php artisan test --coverage` against a real coverage floor — see the
workflow file's own comments for the current number and ratchet plan — all
on SQLite in-memory), and a separate `mysql-publishing-reliability` job that
runs the MySQL/InnoDB concurrency, isolation, and dead-letter-retry tests
against a real MySQL 8.4 service container. Neither job's presence in source
is proof of a passing run on the latest commit — check the Actions tab.

## Deployment topology

Staging uses `APP_ENV=staging`; reserve `APP_ENV=production` for the future
public production environment. MySQL/InnoDB remains the durable system of
record; Redis handles short-lived coordination and high-contention work:

- `CACHE_STORE=redis` (including distributed locks)
- `SESSION_DRIVER=redis`
- `QUEUE_CONNECTION=redis` (`failed_jobs` and `job_batches` remain durable MySQL records)
- a private `redis:8.2-alpine` service with AOF persistence, accessed through `predis/predis`

The migrations for all six required tables are first-party migrations in this
repository. `job_batches` is provisioned for Laravel compatibility, although
the application does not currently dispatch `Bus::batch()` workloads.

### Queue worker and scheduler

The only application queue names are `publishing` and `default`.
`PublishPostJob` and `RetryDeadLetteredAttemptJob` use `publishing`; scheduled
sweeps, retry sweeps, stale-claim recovery, and token refresh work use
`default`. Both must be drained by one worker command:

```sh
php artisan queue:work redis --queue=publishing,default --tries=3 \
  --backoff=10,30,60 --sleep=3 --timeout=60 --max-time=3600
```

Set `REDIS_QUEUE_RETRY_AFTER=120`: it must exceed `--timeout=60`, otherwise a
live job could be released and processed a second time. Publishing's durable
idempotency key and atomic attempt state machine remain the final protection
against duplicate provider delivery; retries, dead-letter handling, and
multi-target completion do not rely on Redis.

Laravel Scheduler is a distinct service and must run every minute. For Render,
use separate Background Workers with Docker Commands
`/usr/local/bin/worker-render` and `/usr/local/bin/scheduler-render`; the
latter continuously runs `php artisan schedule:run`, then sleeps 60 seconds.
Do not replace it with the queue worker or disable it: it enqueues due posts,
retry sweeps, and stale-claim recovery independently of HTTP traffic.

### Monitoring and limits with Redis

The Redis topology is Horizon-compatible, although Horizon itself is not added
to this deployment. Monitor the queue through Redis metrics, Render logs,
`failed_jobs`, and domain-specific `dead_letter_jobs`; use `php artisan
queue:failed` to inspect framework failures and the audited dead-letter retry
flow for publishing failures. `app:ops-snapshot`, scheduled every five minutes,
logs pending or processing publication attempts, publish-failure rate, and
retry-storm signals.

Redis removes queue polling and cache-lock contention from the MySQL primary,
but it does not make publishing itself stateless: transactionally coordinated
post state and idempotency records remain in MySQL. Start with one worker,
measure Redis queue lag plus database contention, and scale only after a real
staging load test. SQLite remains unsupported for concurrent workers.

`docker/Dockerfile` + `docker/docker-compose.yml` provide the equivalent
self-hosted PHP-FPM + Nginx + MySQL stack. Start from
`.env.staging.example` and inject completed values through the deployment
secret store; never commit a populated environment file.

### Render staging prerequisites

Render must run three separate services from `docker/render/Dockerfile`: Web
(`/usr/local/bin/start-render`), Worker (`/usr/local/bin/worker-render`), and
Scheduler (`/usr/local/bin/scheduler-render`). Set
`SP_SEPARATE_QUEUE_WORKER=true` in all three. In staging or production that
flag refuses startup unless `MEDIA_UPLOAD_DISK` names an S3-compatible disk;
`local` storage is never safe across a Web Service and Worker. Use the R2/S3
placeholder variables in `.env.staging.example` and give Web and Worker the
same bucket credentials. Do not set real credentials in the repository.

The Web entrypoint runs only forward `php artisan migrate --force` and exits
non-zero if it fails; it never runs rollback or destructive recovery commands.
It does **not** seed on normal boots. For a deliberately fresh staging
database, set `SP_INITIALIZE_DATABASE=true` for a one-time bootstrap, confirm
success, then return it to `false`. The bootstrap seeder creates an admin only
when its email is absent; it never resets an existing administrator's password
or profile.

The closed beta requires only `SOCIAL_FACEBOOK_CLIENT_ID` and
`SOCIAL_FACEBOOK_CLIENT_SECRET` at deployment level. Telegram uses each
connected account's bot token and does not require a global Telegram OAuth
secret. Instagram Business publishing (2026-08) reuses these same Facebook
credentials — an Instagram Business Account is discovered as a child of a
connected Facebook Page, not through its own OAuth app, so no separate
`SOCIAL_INSTAGRAM_*` credential is required. `SOCIAL_X_CLIENT_ID`/
`SOCIAL_X_CLIENT_SECRET` are only needed once X is production-enabled (it is
not yet — see `docs/api/integrations.md`); do not require or provision
LinkedIn, YouTube, or WhatsApp credentials for this deployment.
