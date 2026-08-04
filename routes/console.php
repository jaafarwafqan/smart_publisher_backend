<?php

use App\Jobs\ProcessScheduledPostsJob;
use App\Jobs\RetryDuePublishAttemptsJob;
use App\Services\ContextLogger;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new ProcessScheduledPostsJob)
    ->everyMinute()
    ->name('process-scheduled-posts')
    ->withoutOverlapping();

Schedule::job(new RetryDuePublishAttemptsJob)
    ->everyMinute()
    ->name('retry-due-publish-attempts')
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
