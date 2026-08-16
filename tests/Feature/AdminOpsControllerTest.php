<?php

namespace Tests\Feature;

use App\Models\DeadLetterJob;
use App\Models\Post;
use App\Models\PostPublicationAttempt;
use App\Models\SocialAccount;
use App\Models\SocialPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 4 (observability, 2026-08-16): GET /admin/ops reads the exact same
 * real metrics app:ops-snapshot already computes — see
 * OpsHealthSnapshot/OpsSnapshotCommandTest for the underlying computation's
 * own coverage. This file only exercises the endpoint's own concerns:
 * authorization and the response shape.
 */
class AdminOpsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_non_super_admin_cannot_access_it(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/admin/ops')->assertForbidden();
    }

    public function test_a_super_admin_sees_real_metrics_and_an_honest_health_flag(): void
    {
        config()->set('ops.alert_thresholds.queue_length', 1);

        $user = User::factory()->create();
        $this->asOrganizationOf($user, function () use ($user): void {
            $post = Post::query()->create(['user_id' => $user->id, 'title' => 'A post', 'status' => 'publishing']);
            $account = SocialAccount::query()->create([
                'user_id' => $user->id,
                'provider' => 'facebook',
                'provider_account_id' => 'ops-ctrl-1',
                'status' => 'connected',
                'is_active' => true,
            ]);
            $page = SocialPage::query()->create([
                'social_account_id' => $account->id,
                'page_id' => 'ops-ctrl-page-1',
                'name' => 'A Page',
                'status' => 'valid',
            ]);
            PostPublicationAttempt::query()->create([
                'post_id' => $post->id,
                'social_account_id' => $account->id,
                'social_page_id' => $page->id,
                'idempotency_key' => 'ops-ctrl-1',
                'attempt_number' => 1,
                'status' => 'pending',
            ]);
        });

        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        Sanctum::actingAs($superAdmin);

        $this->getJson('/api/v1/admin/ops')
            ->assertOk()
            ->assertJsonPath('data.queue_length', 1)
            ->assertJsonPath('data.breaches.queue_length', true)
            ->assertJsonPath('data.breaches.dead_letter_open_count', false)
            ->assertJsonPath('data.healthy', false);
    }

    public function test_reports_healthy_true_when_every_metric_is_under_its_threshold(): void
    {
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        Sanctum::actingAs($superAdmin);

        $this->getJson('/api/v1/admin/ops')
            ->assertOk()
            ->assertJsonPath('data.healthy', true)
            ->assertJsonPath('data.queue_length', 0)
            ->assertJsonPath('data.dead_letter_open_count', 0);
    }

    public function test_open_dead_letter_count_is_reflected_and_already_retried_ones_are_excluded(): void
    {
        config()->set('ops.alert_thresholds.dead_letter_open_count', 1);

        $user = User::factory()->create();
        $this->asOrganizationOf($user, function (): void {
            DeadLetterJob::query()->create([
                'job_class' => 'App\\Jobs\\PublishPostJob',
                'error_message' => 'fixture',
                'failed_at' => now(),
            ]);
            DeadLetterJob::query()->create([
                'job_class' => 'App\\Jobs\\PublishPostJob',
                'error_message' => 'fixture, already retried',
                'failed_at' => now(),
                'retried_at' => now(),
            ]);
        });

        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        Sanctum::actingAs($superAdmin);

        $this->getJson('/api/v1/admin/ops')
            ->assertOk()
            ->assertJsonPath('data.dead_letter_open_count', 1)
            ->assertJsonPath('data.breaches.dead_letter_open_count', true)
            ->assertJsonPath('data.healthy', false);
    }
}
