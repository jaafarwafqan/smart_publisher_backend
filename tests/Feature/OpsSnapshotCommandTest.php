<?php

namespace Tests\Feature;

use App\Models\DeadLetterJob;
use App\Models\Post;
use App\Models\PostPublicationAttempt;
use App\Models\SocialAccount;
use App\Models\SocialPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * CTO audit Sprint 6: app:ops-snapshot computes real alert signals from
 * PostPublicationAttempt data, unlike the Flutter MonitoringAlertPolicy
 * (docs/operations/incident_runbook.md's "reference implementation"),
 * which has zero callers and mostly-unpopulated metrics.
 */
class OpsSnapshotCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeAttempt(string $status): void
    {
        $user = User::factory()->create();

        $this->asOrganizationOf($user, function () use ($user, $status): void {
            $post = Post::query()->create([
                'user_id' => $user->id,
                'title' => 'Ops snapshot fixture',
                'status' => 'publishing',
            ]);

            $socialAccount = SocialAccount::query()->create([
                'user_id' => $user->id,
                'provider' => 'facebook',
                'provider_account_id' => 'ops-'.uniqid(),
                'status' => 'connected',
                'is_active' => true,
            ]);

            $page = SocialPage::query()->create([
                'social_account_id' => $socialAccount->id,
                'page_id' => 'ops-page-'.uniqid(),
                'name' => 'Ops Page',
                'can_publish' => true,
                'status' => 'valid',
            ]);

            PostPublicationAttempt::query()->create([
                'post_id' => $post->id,
                'social_account_id' => $socialAccount->id,
                'social_page_id' => $page->id,
                'idempotency_key' => 'ops-'.uniqid(),
                'attempt_number' => 1,
                'status' => $status,
            ]);
        });
    }

    public function test_reports_healthy_metrics_and_logs_nothing_when_under_every_threshold(): void
    {
        config()->set('ops.alert_thresholds.queue_length', 200);
        config()->set('ops.alert_thresholds.retry_storm_count', 50);

        $this->makeAttempt('pending');

        Log::shouldReceive('warning')->never();
        Log::shouldReceive('error')->never();

        Artisan::call('app:ops-snapshot');

        $this->assertStringContainsString('queue_length=1', Artisan::output());
    }

    public function test_logs_a_warning_when_queue_length_breaches_its_threshold(): void
    {
        config()->set('ops.alert_thresholds.queue_length', 1);

        $this->makeAttempt('pending');
        $this->makeAttempt('processing');

        Log::shouldReceive('warning')
            ->once()
            ->with('ops.alert.queue_length', \Mockery::on(fn (array $context) => $context['value'] === 2 && $context['threshold'] === 1));

        Artisan::call('app:ops-snapshot');
    }

    public function test_logs_an_error_when_retry_storm_count_breaches_its_threshold(): void
    {
        config()->set('ops.alert_thresholds.retry_storm_count', 1);
        config()->set('ops.alert_thresholds.queue_length', 200);

        $this->makeAttempt('retry_scheduled');
        $this->makeAttempt('retry_scheduled');

        Log::shouldReceive('error')
            ->once()
            ->with('ops.alert.retry_storm', \Mockery::on(fn (array $context) => $context['value'] === 2 && $context['threshold'] === 1));

        Artisan::call('app:ops-snapshot');
    }

    public function test_logs_an_error_when_open_dead_letter_count_breaches_its_threshold(): void
    {
        config()->set('ops.alert_thresholds.queue_length', 200);
        config()->set('ops.alert_thresholds.retry_storm_count', 200);
        config()->set('ops.alert_thresholds.dead_letter_open_count', 1);

        $user = User::factory()->create();
        $this->asOrganizationOf($user, function (): void {
            DeadLetterJob::query()->create([
                'job_class' => 'App\\Jobs\\PublishPostJob',
                'error_message' => 'unit test fixture',
                'failed_at' => now(),
            ]);
            DeadLetterJob::query()->create([
                'job_class' => 'App\\Jobs\\PublishPostJob',
                'error_message' => 'unit test fixture',
                'failed_at' => now(),
            ]);
            // Already retried — must not count toward the open total.
            DeadLetterJob::query()->create([
                'job_class' => 'App\\Jobs\\PublishPostJob',
                'error_message' => 'unit test fixture',
                'failed_at' => now(),
                'retried_at' => now(),
            ]);
        });

        Log::shouldReceive('error')
            ->once()
            ->with('ops.alert.dead_letter_open_count', \Mockery::on(fn (array $context) => $context['value'] === 2 && $context['threshold'] === 1));

        Artisan::call('app:ops-snapshot');

        $this->assertStringContainsString('dead_letter_open_count=2', Artisan::output());
    }

    public function test_sends_a_real_telegram_message_when_a_threshold_breaches_and_the_admin_channel_is_configured(): void
    {
        config()->set('ops.alert_thresholds.queue_length', 1);
        config()->set('ops.telegram_alert.bot_token', 'test-bot-token');
        config()->set('ops.telegram_alert.chat_id', '-100987654321');

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $this->makeAttempt('pending');
        $this->makeAttempt('processing');

        Artisan::call('app:ops-snapshot');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'bottest-bot-token/sendMessage')
                && $request['chat_id'] === '-100987654321'
                && str_contains((string) $request['text'], 'queue length');
        });
    }

    public function test_does_not_attempt_telegram_delivery_when_the_admin_channel_is_not_configured(): void
    {
        config()->set('ops.alert_thresholds.queue_length', 1);
        config()->set('ops.telegram_alert.bot_token', null);
        config()->set('ops.telegram_alert.chat_id', null);

        Http::fake();

        $this->makeAttempt('pending');

        Artisan::call('app:ops-snapshot');

        Http::assertNothingSent();
    }

    public function test_a_failed_telegram_delivery_does_not_fail_the_command_or_suppress_the_real_alert(): void
    {
        config()->set('ops.alert_thresholds.queue_length', 1);
        config()->set('ops.telegram_alert.bot_token', 'test-bot-token');
        config()->set('ops.telegram_alert.chat_id', '-100987654321');

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'chat not found'], 400)]);

        $this->makeAttempt('pending');

        Log::shouldReceive('warning')
            ->once()
            ->with('ops.alert.queue_length', \Mockery::any());
        Log::shouldReceive('warning')
            ->once()
            ->with('ops.alert.telegram_delivery_failed', \Mockery::any());

        $exitCode = Artisan::call('app:ops-snapshot');

        $this->assertSame(0, $exitCode);
    }
}
