# Smart Publisher — API Backend

Laravel 13 API backend for Smart Publisher, a social-media publishing platform. Consumed by a Flutter client (`smart_publisher`, sibling repo — no shared git history, but the two are developed together). This is not the stock Laravel starter README — see below for what's actually built here.

**Status (2026-08-01):** Phase 1 MVP backend surface is complete and live-tested (including a real publish through a connected Telegram bot). Full current gap list: [`docs/audit/KNOWN_ISSUES.md`](../smart_publisher/docs/audit/KNOWN_ISSUES.md) (lives in the Flutter repo's docs tree since that's where the project's documentation was originally centralized).

## What's actually here

- **Auth**: Sanctum bearer tokens, access+refresh pair with rotation, rate-limited login/refresh.
- **Multi-tenancy**: real organizations (`organizations`/`organization_memberships` tables), not single-tenant. Every tenant-scoped model resolves through `TenantContext`, set per-request by `ResolveTenantContext` middleware from a client-supplied `X-Organization-Id` header — verified against the caller's actual membership, never trusted blindly. A user can belong to multiple organizations and switch between them.
- **RBAC**: two layers. Spatie `laravel-permission` (`admin`/`manager`/`editor`, ~35 granular permissions) still gates a handful of global/admin-only actions (system settings, legacy user management). Per-organization authorization is a separate `OrganizationRole` enum (`owner`/`admin`/`manager`/`editor`/`viewer`) with its own fixed permission template (`OrganizationPermission`), checked via `User::hasOrganizationPermission()` — this is what most tenant-scoped resources (posts, social accounts, members) actually authorize against.
- **Billing scaffolding (not a live feature yet)**: `organization_subscriptions`/`billing_webhook_events` tables and `OrganizationEntitlements::hasCapacityFor()` exist, but no organization has a subscription row by default — every org effectively has unlimited capacity until a real plan is assigned. Treat this as infrastructure for a future plans/limits feature, not something currently enforced.
- **Posts**: draft → scheduled → publishing → published/failed lifecycle, real per-page targeting (`post_targets` pivot), per-platform caption overrides (`meta.platform_content`), idempotent delivery (`post_publication_attempts.idempotency_key`, DB-unique).
- **Social accounts**: real OAuth (Facebook/Instagram/WhatsApp/LinkedIn/X/YouTube — via `SocialOAuthManager`; most non-Facebook providers currently route to a safe mock so integration testing never touches a real external API), Telegram bot-token connect, page/channel discovery (auto for FB/IG/WhatsApp, manual verify-by-identifier for Telegram), token refresh jobs, connection health checks that never conflate "can't verify" with "confirmed broken."
- **Media**: upload with type-aware size limits (20MB image / 500MB video / 50MB document), real MIME validation, sha256-based duplicate detection (informational, never blocking), image compression, thumbnail generation (ffmpeg for video if available, graceful `null` degradation if not).
- **Scheduling**: `ProcessScheduledPostsJob` runs every minute, transitions posts to an intermediate `publishing` status immediately (prevents the duplicate-dispatch storm documented in the Production Readiness Audit), dispatches `PublishPostJob` per target page with retry/backoff and a dead-letter sink.
- **Analytics**: real per-page metrics (`post_metrics`) where the provider actually supplies them; `is_available` is an honest flag, never a fabricated zero. Best-platform/best-publish-hour are `null` below a minimum sample size rather than a false-confidence guess.
- **System settings**: per-provider OAuth app configuration (client id/secret/endpoints) editable at runtime, with an audit log that records which *fields* changed, never the values.
- **Backup/restore**: `app:backup-database` (SQLite via `VACUUM INTO`, MySQL via `mysqldump --single-transaction`) and `app:restore-database`, scheduled daily. Both refuse `:memory:` databases and remind you that `APP_KEY` must travel with any backup (encrypted columns become permanently undecryptable otherwise).

## What's genuinely NOT here (verified absent, not just undocumented)

- **No webhook receiver** — nothing listens for platform delivery/engagement webhooks. An old doc describing one was aspirational fiction; see `docs/api/integrations.md` in the Flutter repo.
- **No enforced plans/subscriptions/billing** — the tables and entitlement-check code exist (see Multi-tenancy/Billing above), but nothing currently assigns a real plan to an organization, so no capacity limit is actually enforced yet.
- **`tiktok`/`snapchat`/`pinterest`/`other` providers** are in the whitelist and route safely to the mock provider, but have no `config/social.php` entry — their real token-refresh path would throw if ever exercised.
- **`AccountController`/`/accounts/*` routes** are legacy and mostly broken (`index()` is the only implemented method; `connect`/`show`/`update`/`destroy` have no controller methods at all and 500 if called). The real, complete implementation is `SocialAccountController` at `/users/{user}/social-accounts/*`. Kept only for backward compatibility — do not build against it.
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

Run the scheduler with `php artisan schedule:work` in development, or a real cron entry (`* * * * * php artisan schedule:run`) in production.

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

`.github/workflows/ci.yml` (new this pass) — `composer install` → `php artisan migrate` (sqlite) → `php artisan test`. Mirrors the shape of the Flutter repo's existing CI.

## Docker

`docker/Dockerfile` + `docker/docker-compose.yml` — PHP-FPM + Nginx + MySQL + Redis; see the compose file for the intended production topology (this replaces the SQLite + built-in dev server setup used throughout local development and testing). The `queue-worker` service runs `queue:work --queue=publishing,default` — both names matter: `PublishPostJob`/`RetryDeadLetteredAttemptJob` dispatch onto `publishing` (`config/publishing.php`), everything else uses Laravel's `default` queue. A worker listening to only one of the two silently stops processing the other.
