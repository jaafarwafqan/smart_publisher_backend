<?php

use App\Jobs\ProcessScheduledPostsJob;
use App\Jobs\ReclaimStalePublishAttemptsJob;
use App\Jobs\RetryDuePublishAttemptsJob;
use App\Models\Plan;
use App\Services\ContextLogger;
use App\Support\Billing\DefaultPlans;
use App\Support\Billing\FreeTierGrandfathering;
use App\Support\Billing\QuotaGates;
use Illuminate\Console\Command;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('billing:preflight-free-tier', function (): int {
    // docker/render/start.sh deliberately runs this BEFORE `php artisan
    // migrate`, specifically so it can inspect existing data before the
    // billing migration touches it. But that means it also runs on a
    // genuinely fresh database — a brand-new deployment, or this exact CI
    // job — where `plans`/`organizations` don't exist yet at all. There is
    // nothing to preflight on a database with no prior schema; migrate will
    // create everything correctly from scratch. Without this guard, every
    // fresh boot crashed here (SQLSTATE[42S02]: Base table or view not
    // found), before nginx/php-fpm ever started — confirmed live in this
    // repo's own "Docker build and boot smoke test" CI job.
    if (! Schema::hasTable('plans') || ! Schema::hasTable('organizations')) {
        $this->info('Read-only preflight skipped: no prior schema to audit on a fresh database.');

        return Command::SUCCESS;
    }

    // 2026-08-22 incident: a plan row saved before a new QuotaGates key
    // shipped has no way to gain that key on its own — Plan::booted() only
    // validates an actual save(), and this preflight itself used to just
    // report the mismatch and exit non-zero. Because start.sh's `set -e`
    // runs this BEFORE `php artisan migrate`, that non-zero exit silently
    // blocked migrate — including a same-day data-backfill migration meant
    // to fix exactly this — from ever running at all, on every subsequent
    // deploy. This is data any operator would always want auto-corrected
    // the same deterministic way (see DefaultPlans' own docblocks: `false`
    // for the Free plan, `true` — never take away access a legacy plan
    // already had — for anything else), so it is safe to self-heal here
    // rather than requiring a migration that cannot itself run until this
    // check passes.
    $invalidPlans = Plan::query()
        ->where('is_active', true)
        ->get()
        ->filter(fn (Plan $plan): bool => QuotaGates::missingFrom($plan->limits) !== []);

    foreach ($invalidPlans as $plan) {
        $missing = QuotaGates::missingFrom($plan->limits);
        $fallback = $plan->slug === DefaultPlans::FREE_SLUG;
        $limits = $plan->limits ?? [];
        foreach ($missing as $key) {
            $limits[$key] = ! $fallback;
        }
        $plan->limits = $limits;
        $plan->save();

        $this->info("Self-healed plan '{$plan->slug}': backfilled ".implode(', ', $missing).' to '.($fallback ? 'false' : 'true').'.');
    }

    $stillInvalid = Plan::query()
        ->where('is_active', true)
        ->get()
        ->mapWithKeys(fn (Plan $plan): array => [$plan->slug => QuotaGates::missingFrom($plan->limits)])
        ->filter(fn (array $missing): bool => $missing !== [])
        ->all();

    if ($stillInvalid !== []) {
        $this->error('Active plans with missing quota gates (could not self-heal):');
        foreach ($stillInvalid as $slug => $missing) {
            $this->line("- {$slug}: ".implode(', ', $missing));
        }

        return Command::FAILURE;
    }

    $audit = app(FreeTierGrandfathering::class)->auditOrganizationsWithoutSubscriptions();
    $this->table(
        ['Organization ID', 'Organization', 'Members', 'Social accounts', 'Scheduled/published this month', 'Migration plan'],
        array_map(static fn (array $organization): array => [
            $organization['id'],
            $organization['name'],
            $organization['usage'][QuotaGates::TEAM_MEMBERS],
            $organization['usage'][QuotaGates::SOCIAL_ACCOUNTS],
            $organization['usage'][QuotaGates::SCHEDULED_POSTS_PER_MONTH],
            $organization['exceeds_free_limits'] ? 'legacy-grandfathered' : 'free',
        ], $audit),
    );

    $grandfathered = count(array_filter(
        $audit,
        static fn (array $organization): bool => $organization['exceeds_free_limits'],
    ));

    $this->info(sprintf(
        'Read-only preflight complete: %d organization(s) without subscriptions; %d will retain unlimited legacy capacity because current usage exceeds Free.',
        count($audit),
        $grandfathered,
    ));

    return Command::SUCCESS;
})->purpose('Audit Free-tier overages before the organization-subscription backfill');

Schedule::job(new ProcessScheduledPostsJob)
    ->everyMinute()
    ->name('process-scheduled-posts')
    ->withoutOverlapping();

Schedule::job(new RetryDuePublishAttemptsJob)
    ->everyMinute()
    ->name('retry-due-publish-attempts')
    ->withoutOverlapping();

Schedule::job(new ReclaimStalePublishAttemptsJob)
    ->everyMinute()
    ->name('reclaim-stale-publish-attempts')
    ->withoutOverlapping();

Schedule::command('app:backup-database')
    ->daily()
    ->name('backup-database')
    ->withoutOverlapping()
    ->onFailure(function (): void {
        ContextLogger::error('backup.database.failed', []);
    });

Schedule::command('oauth-providers:health-check')
    ->dailyAt('03:00')
    ->name('oauth-providers-health-check')
    ->withoutOverlapping()
    ->onFailure(function (): void {
        ContextLogger::error('oauth_providers.health_check.failed', []);
    });

Schedule::command('social-pages:sync')
    ->hourly()
    ->name('social-pages-sync')
    ->withoutOverlapping()
    ->onFailure(function (): void {
        ContextLogger::error('social_pages.sync.failed', []);
    });

Schedule::command('post-metrics:sync')
    ->hourly()
    ->name('post-metrics-sync')
    ->withoutOverlapping()
    ->onFailure(function (): void {
        ContextLogger::error('post_metrics.sync.failed', []);
    });

Schedule::command('app:ops-snapshot')
    ->everyFiveMinutes()
    ->name('ops-snapshot')
    ->withoutOverlapping();

// Prepaid-billing model (2026-08-21): none of the Iraqi gateways this
// product integrates with support recurring subscriptions — see
// ExpireSubscriptionsCommand's own docblock for why a daily sweep is what
// keeps status/current_period_end meaningful at all.
Schedule::command('billing:expire-subscriptions')
    ->daily()
    ->name('billing-expire-subscriptions')
    ->withoutOverlapping()
    ->onFailure(function (): void {
        ContextLogger::error('billing.expire_subscriptions.failed', []);
    });
