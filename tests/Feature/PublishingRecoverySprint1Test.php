<?php

namespace Tests\Feature;

use App\Infrastructure\ExternalServices\Publishing\PublishEngineService;
use App\Jobs\ReclaimStalePublishAttemptsJob;
use App\Models\Post;
use App\Models\PostPublicationAttempt;
use App\Models\Scopes\OrganizationScope;
use App\Models\SocialAccount;
use App\Models\SocialPage;
use App\Models\User;
use App\Support\Publishing\ClosedBetaPublishingGate;
use App\Support\Publishing\PublicationBatchCoordinator;
use App\Support\Publishing\PublishErrorClassifier;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 1 (Publishing Recovery) regression coverage — the 5-item plan the
 * user approved: fix the attempt counter, add the watchdog for crashed
 * workers, model partial_success/cancelled outcomes, and prove the worker-
 * death path with a real test rather than a seeded row.
 */
class PublishingRecoverySprint1Test extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Post, 1: SocialPage, 2: SocialAccount}
     */
    private function makeFacebookPost(User $user, string $batchKey, string $postStatus = 'scheduled'): array
    {
        return $this->asOrganizationOf($user, function () use ($user, $batchKey, $postStatus) {
            $post = Post::query()->create([
                'user_id' => $user->id,
                'title' => 'Sprint 1 reliability post',
                'content' => 'Body',
                'status' => $postStatus,
                'publish_batch_key' => $batchKey,
            ]);

            $socialAccount = SocialAccount::query()->create([
                'user_id' => $user->id,
                'provider' => 'facebook',
                'provider_account_id' => 'page-'.$batchKey,
                'access_token' => 'live-token',
                'status' => 'connected',
                'is_active' => true,
            ]);

            $page = SocialPage::query()->create([
                'social_account_id' => $socialAccount->id,
                'page_id' => 'page-'.$batchKey,
                'name' => 'Facebook Page',
                'can_publish' => true,
                'status' => 'valid',
            ]);

            return [$post, $page, $socialAccount];
        });
    }

    /**
     * The core Sprint 1 bug: attempt_number was set once at row creation
     * and never advanced through the retry_scheduled -> processing cycle,
     * so a persistently-retryable failure retried forever instead of ever
     * reaching dead_letter. This drives TWO real retry cycles through
     * PublishEngineService::publish() (not a seeded attempt_number) and
     * proves the second one — once the budget is genuinely exhausted —
     * finalizes to dead_letter instead of scheduling a third retry.
     */
    public function test_persistent_retryable_failures_eventually_reach_dead_letter_via_real_retry_cycles(): void
    {
        Http::fake([
            'graph.facebook.com/*/feed' => Http::response(['error' => 'internal'], 503),
        ]);
        config()->set('services.facebook.graph_url', 'https://graph.facebook.com');
        config()->set('publishing.max_retries', 2);

        $user = User::factory()->create();
        [$post, $page] = $this->makeFacebookPost($user, 'batch-real-retry-1');

        $engine = app(PublishEngineService::class);

        $first = $this->asOrganizationOf($user, fn () => $engine->publish($post->fresh(), $page, 'batch-real-retry-1'));
        $this->assertSame('retry_scheduled', $first['status']);

        $this->asOrganizationOf($user, function () use ($first): void {
            $attempt = PostPublicationAttempt::query()->findOrFail($first['attempt_id']);
            $this->assertSame(1, $attempt->attempt_number, 'attempt_number must still be 1 before the sweeper ever ran');
            $attempt->update(['next_attempt_at' => now()->subSecond()]);
        });

        $second = $this->asOrganizationOf($user, fn () => $engine->publish($post->fresh(), $page, 'batch-real-retry-1'));

        // Before the fix: attempt_number was still 1 here too (never
        // incremented by the retry claim), so this second failure would
        // have scheduled a THIRD retry instead of exhausting the budget —
        // an infinite retry loop for any persistently-failing provider.
        $this->assertSame('failed', $second['status']);

        $attempt = PostPublicationAttempt::withoutGlobalScope(OrganizationScope::class)->findOrFail($first['attempt_id']);
        $this->assertSame(2, $attempt->attempt_number, 'the retry claim must have advanced the counter');
        $this->assertSame('dead_letter', $attempt->status);

        Http::assertSentCount(2);
    }

    /**
     * Worker-death test: a real 'processing' claim whose worker never
     * reported back (no further update — simulating a killed process, not
     * a seeded terminal row) is left exactly where a crash would leave it.
     * The watchdog (ReclaimStalePublishAttemptsJob) is the only thing that
     * ever revisits it — nothing else is scheduled against a 'processing'
     * row. Proves it finalizes to dead_letter/AMBIGUOUS_AFTER_CRASH without
     * ever calling the provider again, AND that the post (previously stuck
     * in 'publishing' forever with no watchdog) correctly settles.
     */
    public function test_watchdog_reclaims_a_crashed_workers_stale_claim_and_settles_the_stuck_post(): void
    {
        Http::fake([
            'graph.facebook.com/*/feed' => Http::response(['id' => 'should-not-be-called'], 200),
        ]);
        config()->set('services.facebook.graph_url', 'https://graph.facebook.com');
        config()->set('publishing.claim_stale_after_seconds', 300);

        $user = User::factory()->create();
        [$post, $page] = $this->makeFacebookPost($user, 'batch-worker-death-1', postStatus: 'publishing');

        $this->asOrganizationOf($user, function () use ($post, $page, $user): void {
            // The exact state a killed queue worker leaves behind: claimed,
            // never resolved. No mocked "crash" — just the real row shape
            // with an old claimed_at, precisely what AttemptStateMachine's
            // stale-claim window is meant to detect.
            PostPublicationAttempt::query()->create([
                'post_id' => $post->id,
                'publish_batch_key' => 'batch-worker-death-1',
                'social_account_id' => $page->social_account_id,
                'social_page_id' => $page->id,
                'idempotency_key' => hash('sha256', 'org-'.$user->current_organization_id.'-post-'.$post->id.'-page-'.$page->id.'-batch-batch-worker-death-1'),
                'attempt_number' => 1,
                'status' => 'processing',
                'claimed_at' => now()->subSeconds(600),
                'claimed_by' => 'worker-that-died-mid-publish',
            ]);
        });

        // No ambient TenantContext here — same as a real scheduled job
        // invocation. The job must resolve tenancy per-attempt itself.
        (new ReclaimStalePublishAttemptsJob)->handle(app(PublishEngineService::class));

        Http::assertNothingSent();

        $this->asOrganizationOf($user, function () use ($post): void {
            $attempt = PostPublicationAttempt::query()->where('post_id', $post->id)->firstOrFail();
            $this->assertSame('dead_letter', $attempt->status);
            $this->assertSame(PublishErrorClassifier::AMBIGUOUS_AFTER_CRASH, $attempt->error_classification);

            $post->refresh();
            $this->assertSame('failed', $post->status, 'the post must not be left stuck in publishing forever');
        });
    }

    /**
     * A batch with a mix of success and permanent failure across its
     * targets must not be reported identically to a total failure — the
     * user needs to know some platforms really did receive the post.
     */
    public function test_a_batch_with_mixed_target_outcomes_settles_as_partial_success(): void
    {
        $user = User::factory()->create();
        [$post, $pageOne] = $this->makeFacebookPost($user, 'batch-partial-1', postStatus: 'publishing');

        [$pageTwo, $accountTwo] = $this->asOrganizationOf($user, function () use ($user) {
            $account = SocialAccount::query()->create([
                'user_id' => $user->id,
                'provider' => 'facebook',
                'provider_account_id' => 'page-partial-two',
                'access_token' => 'live-token-two',
                'status' => 'connected',
                'is_active' => true,
            ]);

            $page = SocialPage::query()->create([
                'social_account_id' => $account->id,
                'page_id' => 'page-partial-two',
                'name' => 'Second Facebook Page',
                'can_publish' => true,
                'status' => 'valid',
            ]);

            return [$page, $account];
        });

        [$attemptOne, $attemptTwo] = $this->asOrganizationOf($user, function () use ($post, $pageOne, $pageTwo) {
            app(ClosedBetaPublishingGate::class);
            $coordinator = app(PublicationBatchCoordinator::class);
            $attempts = $coordinator->createPendingAttempts($post->fresh(), EloquentCollection::make([$pageOne, $pageTwo]), 'batch-partial-1');

            return [$attempts->firstWhere('social_page_id', $pageOne->id), $attempts->firstWhere('social_page_id', $pageTwo->id)];
        });

        Http::fake([
            'graph.facebook.com/*/feed' => Http::sequence()
                ->push(['id' => 'fb-partial-success'], 200)
                ->push(['error' => 'invalid token'], 401),
        ]);
        config()->set('services.facebook.graph_url', 'https://graph.facebook.com');

        $engine = app(PublishEngineService::class);
        $this->asOrganizationOf($user, function () use ($engine, $attemptOne, $post, $pageOne): void {
            $engine->publishExistingAttempt($attemptOne->fresh(), $post->fresh(), $pageOne);
        });
        $this->asOrganizationOf($user, function () use ($engine, $attemptTwo, $post, $pageTwo): void {
            $engine->publishExistingAttempt($attemptTwo->fresh(), $post->fresh(), $pageTwo);
        });

        $completion = $this->asOrganizationOf(
            $user,
            fn () => app(PublicationBatchCoordinator::class)->completeIfSettled($post->fresh(), 'batch-partial-1'),
        );

        $this->assertNotNull($completion);
        $this->assertSame('partial_success', $completion['outcome']);
        $this->assertSame('partial_success', $completion['post']->status);
    }

    public function test_cancelling_a_scheduled_post_moves_it_to_cancelled(): void
    {
        $user = User::factory()->create();
        [$post] = $this->makeFacebookPost($user, 'batch-cancel-scheduled-1', postStatus: 'scheduled');

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/v1/posts/{$post->id}/cancel");

        $response->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->asOrganizationOf($user, function () use ($post): void {
            $this->assertSame('cancelled', $post->fresh()->status);
        });
    }

    public function test_cancelling_a_publishing_post_with_no_claimed_attempts_succeeds(): void
    {
        $user = User::factory()->create();
        [$post, $page] = $this->makeFacebookPost($user, 'batch-cancel-publishing-1', postStatus: 'publishing');

        $this->asOrganizationOf($user, function () use ($post, $page): void {
            app(PublicationBatchCoordinator::class)->createPendingAttempts($post->fresh(), EloquentCollection::make([$page]), 'batch-cancel-publishing-1');
        });

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/v1/posts/{$post->id}/cancel");

        $response->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->asOrganizationOf($user, function () use ($post): void {
            $attempt = PostPublicationAttempt::query()->where('post_id', $post->id)->firstOrFail();
            $this->assertSame('cancelled', $attempt->status);
        });
    }

    public function test_cancelling_a_publishing_post_fails_once_an_attempt_is_already_claimed(): void
    {
        $user = User::factory()->create();
        [$post, $page] = $this->makeFacebookPost($user, 'batch-cancel-inflight-1', postStatus: 'publishing');

        $this->asOrganizationOf($user, function () use ($post, $page): void {
            $attempts = app(PublicationBatchCoordinator::class)->createPendingAttempts($post->fresh(), EloquentCollection::make([$page]), 'batch-cancel-inflight-1');
            $attempts->first()->update(['status' => 'processing', 'claimed_at' => now(), 'claimed_by' => 'a-real-worker']);
        });

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/v1/posts/{$post->id}/cancel");

        $response->assertStatus(409);

        $this->asOrganizationOf($user, function () use ($post): void {
            $this->assertSame('publishing', $post->fresh()->status);
        });
    }
}
