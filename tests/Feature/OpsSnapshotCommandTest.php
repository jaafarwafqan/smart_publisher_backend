<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostPublicationAttempt;
use App\Models\SocialAccount;
use App\Models\SocialPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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
}
