<?php

namespace Tests\Feature;

use App\Infrastructure\ExternalServices\Publishing\PublishEngineService;
use App\Jobs\ProcessScheduledPostsJob;
use App\Jobs\PublishPostJob;
use App\Models\Post;
use App\Models\PostPublicationAttempt;
use App\Models\SocialAccount;
use App\Models\SocialPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClosedBetaPublishingTargetGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->instance('env', 'production');
        Queue::fake();
    }

    public function test_production_publish_now_rejects_a_non_beta_provider_before_attempt_or_job_creation(): void
    {
        $user = User::factory()->create();
        [$post] = $this->makePostWithTarget($user, 'whatsapp', 'whatsapp_number');

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/posts/'.$post->id.'/publish-now')
            ->assertStatus(422)
            ->assertJsonPath('errors.social_page_ids.0', 'Only Facebook Pages and Telegram channels are enabled for the production closed beta.');

        $this->assertNoAttemptsOrJobs($user, $post);
        $this->assertSame('draft', $post->fresh()->status);
    }

    public function test_production_publish_now_rejects_an_instagram_business_target_discovered_through_facebook(): void
    {
        $user = User::factory()->create();
        [$post] = $this->makePostWithTarget($user, 'facebook', 'instagram_business');

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/posts/'.$post->id.'/publish-now')
            ->assertStatus(422);

        $this->assertNoAttemptsOrJobs($user, $post);
        $this->assertSame('draft', $post->fresh()->status);
    }

    public function test_production_publish_now_rejects_a_telegram_target_that_is_not_a_channel(): void
    {
        $user = User::factory()->create();
        [$post] = $this->makePostWithTarget($user, 'telegram', 'group');

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/posts/'.$post->id.'/publish-now')
            ->assertStatus(422);

        $this->assertNoAttemptsOrJobs($user, $post);
        $this->assertSame('draft', $post->fresh()->status);
    }

    public function test_production_schedule_rejects_an_unsupported_target_before_it_can_be_queued(): void
    {
        $user = User::factory()->create();
        [$post] = $this->makePostWithTarget($user, 'x', 'profile');

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/posts/'.$post->id.'/schedule', [
            'scheduled_at' => now()->addHour()->toIso8601String(),
        ])->assertStatus(422);

        $this->assertNoAttemptsOrJobs($user, $post);
        $this->assertSame('draft', $post->fresh()->status);
    }

    public function test_production_approval_does_not_dispatch_a_pending_unsupported_target(): void
    {
        $user = User::factory()->create();
        [$post, $page] = $this->makePostWithTarget($user, 'facebook', 'instagram_business');

        $this->asOrganizationOf($user, fn () => $post->update([
            'approval_status' => 'pending',
            'approval_requested_action' => 'publish_now',
            'meta' => ['_pending_publish_page_ids' => [$page->id]],
        ]));

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/posts/'.$post->id.'/approve')
            ->assertStatus(422);

        $post->refresh();
        $this->assertSame('draft', $post->status);
        $this->assertSame('pending', $post->approval_status);
        $this->assertNull($post->approved_by);
        $this->assertNoAttemptsOrJobs($user, $post);
    }

    public function test_scheduler_settles_a_legacy_unsupported_scheduled_post_without_creating_attempts_or_jobs(): void
    {
        $user = User::factory()->create();
        [$post] = $this->makePostWithTarget($user, 'facebook', 'instagram_business', [
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinute(),
            'publish_batch_key' => 'legacy-unsupported-target',
        ]);

        (new ProcessScheduledPostsJob)->handle();

        $post->refresh();
        $this->assertSame('failed', $post->status);
        $this->assertStringContainsString('not enabled for the production closed beta', (string) $post->last_error);
        $this->assertNoAttemptsOrJobs($user, $post);
    }

    public function test_publish_engine_rejects_an_unsupported_target_before_creating_an_attempt(): void
    {
        $user = User::factory()->create();
        [$post, $page] = $this->makePostWithTarget($user, 'instagram', 'instagram_business');

        try {
            $this->asOrganizationOf($user, fn () => app(PublishEngineService::class)->publish(
                $post->fresh(),
                $page->fresh()->load('socialAccount'),
                'engine-unsupported-target',
            ));
            $this->fail('Expected the production closed-beta target gate to reject the publish.');
        } catch (ValidationException) {
            // Expected: no durable attempt exists yet because the engine's
            // public service boundary validates before findOrCreateAttempt().
        }

        $this->assertNoAttemptsOrJobs($user, $post);
    }

    public function test_production_allows_a_facebook_page_and_telegram_channel_for_an_authorized_owner(): void
    {
        $user = User::factory()->create();
        [$post] = $this->makePostWithTargets($user, [
            ['provider' => 'facebook', 'kind' => 'page'],
            ['provider' => 'telegram', 'kind' => 'channel'],
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/posts/'.$post->id.'/publish-now')
            ->assertOk()
            ->assertJsonPath('data.jobs_count', 2);

        $this->assertSame(2, $this->asOrganizationOf(
            $user,
            fn () => PostPublicationAttempt::query()->where('post_id', $post->id)->count(),
        ));
        Queue::assertPushed(PublishPostJob::class, 2);
        $this->assertSame('publishing', $post->fresh()->status);
    }

    /**
     * @param  array<string, mixed>  $postAttributes
     * @return array{0: Post, 1: SocialPage}
     */
    private function makePostWithTarget(
        User $user,
        string $provider,
        string $kind,
        array $postAttributes = [],
    ): array {
        [$post, $pages] = $this->makePostWithTargets($user, [[
            'provider' => $provider,
            'kind' => $kind,
        ]], $postAttributes);

        return [$post, $pages[0]];
    }

    /**
     * @param  list<array{provider: string, kind: string}>  $targets
     * @param  array<string, mixed>  $postAttributes
     * @return array{0: Post, 1: list<SocialPage>}
     */
    private function makePostWithTargets(User $user, array $targets, array $postAttributes = []): array
    {
        return $this->asOrganizationOf($user, function () use ($user, $targets, $postAttributes): array {
            $post = Post::query()->create(array_merge([
                'user_id' => $user->id,
                'title' => 'Closed beta target gate',
                'content' => 'Release guard regression coverage.',
                'status' => 'draft',
            ], $postAttributes));

            $pages = [];
            foreach ($targets as $index => $target) {
                $account = SocialAccount::query()->create([
                    'user_id' => $user->id,
                    'provider' => $target['provider'],
                    'provider_account_id' => $target['provider'].'-account-'.$post->id.'-'.$index,
                    'access_token' => 'closed-beta-test-token',
                    'status' => 'connected',
                    'is_active' => true,
                ]);

                $pages[] = SocialPage::query()->create([
                    'social_account_id' => $account->id,
                    'page_id' => $target['provider'].'-target-'.$post->id.'-'.$index,
                    'kind' => $target['kind'],
                    'name' => ucfirst($target['provider']).' target',
                    'can_publish' => true,
                    'is_selected' => true,
                    'status' => 'valid',
                ]);
            }

            $post->socialPages()->sync(collect($pages)->pluck('id')->all());

            return [$post, $pages];
        });
    }

    private function assertNoAttemptsOrJobs(User $user, Post $post): void
    {
        $this->assertSame(0, $this->asOrganizationOf(
            $user,
            fn () => PostPublicationAttempt::query()->where('post_id', $post->id)->count(),
        ));
        Queue::assertNotPushed(PublishPostJob::class);
    }
}
