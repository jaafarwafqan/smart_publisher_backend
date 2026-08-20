<?php

use App\Jobs\ProcessScheduledPostsJob;
use App\Jobs\ReclaimStalePublishAttemptsJob;
use App\Jobs\RetryDuePublishAttemptsJob;
use App\Models\Plan;
use App\Services\ContextLogger;
use App\Support\Billing\FreeTierGrandfathering;
use App\Support\Billing\QuotaGates;
use Illuminate\Console\Command;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('billing:preflight-free-tier', function (): int {
    $invalidPlans = Plan::query()
        ->where('is_active', true)
        ->get()
        ->mapWithKeys(fn (Plan $plan): array => [$plan->slug => QuotaGates::missingFrom($plan->limits)])
        ->filter(fn (array $missing): bool => $missing !== [])
        ->all();

    if ($invalidPlans !== []) {
        $this->error('Active plans with missing quota gates:');
        foreach ($invalidPlans as $slug => $missing) {
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
