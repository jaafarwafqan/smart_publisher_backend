<?php

namespace Tests\Feature;

use App\Infrastructure\ExternalServices\Publishing\PublishEngineService;
use App\Jobs\PublishPostJob;
use App\Models\MediaAttachment;
use App\Models\Post;
use App\Models\PostPublicationAttempt;
use App\Models\Scopes\OrganizationScope;
use App\Models\SocialAccount;
use App\Models\SocialPage;
use App\Models\User;
use App\Support\Publishing\AttemptStateMachine;
use App\Support\Publishing\PostStateMachine;
use App\Support\Publishing\PublishErrorClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Dedicated regression coverage for the Sprint 3 (Publishing Core) mandatory
 * acceptance criteria. Each test method name/docblock maps to one numbered
 * criterion from the Sprint 3 brief. Criterion #8 (manual DLQ retry,
 * permission-gated + audited) and #10 (org isolation of dead letters) are
 * covered separately in DeadLetterRetryTest.
 *
 * Criterion #13 ("concurrency tests must run on the production DB engine —
 * MySQL — not SQLite alone") is NOT covered here: this environment's local
 * MySQL instance has been down since before this work started (Laragon's
 * InnoDB data directory fails to start — confirmed via mysqld.log, unrelated
 * to this project) and was deliberately not touched, since reinitializing a
 * user's local MySQL data directory risks other local databases outside
 * this project's scope. Every claim/transition here is written as a
 * portable conditional UPDATE with an affected-rows check specifically so
 * the *logic* doesn't depend on SQLite's behavior — but running the same
 * tests against real MySQL to rule out an engine-specific surprise (e.g.
 * isolation-level differences under real concurrent connections) has not
 * been done and should be tracked as an explicit follow-up before
 * production launch.
 */
class PublishingReliabilityAcceptanceTest extends TestCase
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
                'title' => 'Reliability test post',
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
     * #1: two workers race to claim the same job — only one wins. Exercises
     * AttemptStateMachine::claim() directly: it's the exact atomic
     * conditional-UPDATE-with-affected-rows-check every higher-level path
     * (PublishEngineService, RetryDuePublishAttemptsJob) goes through.
     */
    public function test_only_one_of_two_racing_workers_can_claim_the_same_pending_attempt(): void
    {
        $user = User::factory()->create();
        [$post, $page] = $this->makeFacebookPost($user, 'batch-race-1');

        $attempt = $this->asOrganizationOf($user, fn () => PostPublicationAttempt::query()->create([
            'post_id' => $post->id,
            'social_account_id' => $page->social_account_id,
            'social_page_id' => $page->id,
            'idempotency_key' => hash('sha256', 'race-key-1'),
            'attempt_number' => 1,
            'status' => 'pending',
        ]));

        $stateMachine = new AttemptStateMachine;

        [$workerAWon, $workerBWon] = $this->asOrganizationOf($user, function () use ($stateMachine, $attempt) {
            $workerAWon = $stateMachine->claim($attempt->fresh(), 'worker-a');
            $workerBWon = $stateMachine->claim($attempt->fresh(), 'worker-b');

            return [$workerAWon, $workerBWon];
        });

        $this->assertTrue($workerAWon);
        $this->assertFalse($workerBWon);

        $this->asOrganizationOf($user, function () use ($attempt): void {
            $attempt->refresh();
            $this->assertSame('processing', $attempt->status);
            $this->assertSame('worker-a', $attempt->claimed_by);
        });
    }

    /**
     * #2: resending the same request doesn't publish twice. Calling
     * publish() again with the identical (post, page, batchKey) tuple after
     * the first call already succeeded must hit the provider exactly once —
     * the second call finds the existing 'success' attempt via the
     * idempotency key and short-circuits as 'duplicate_ignored'.
     */
    public function test_resending_the_same_publish_request_does_not_call_the_provider_twice(): void
    {
        Http::fake([
            'graph.facebook.com/*/feed' => Http::response(['id' => 'fb-dup-1'], 200),
        ]);
        config()->set('services.facebook.graph_url', 'https://graph.facebook.com');

        $user = User::factory()->create();
        [$post, $page] = $this->makeFacebookPost($user, 'batch-dup-1');

        [$first, $second] = $this->asOrganizationOf($user, function () use ($post, $page) {
            $engine = app(PublishEngineService::class);
            $first = $engine->publish($post->fresh(), $page, 'batch-dup-1');
            $second = $engine->publish($post->fresh(), $page, 'batch-dup-1');

            return [$first, $second];
        });

        $this->assertSame('success', $first['status']);
        $this->assertSame('duplicate_ignored', $second['status']);
        $this->assertSame($first['attempt_id'], $second['attempt_id']);

        Http::assertSentCount(1);
        $this->assertSame(1, PostPublicationAttempt::withoutGlobalScope(OrganizationScope::class)->count());
    }

    /**
     * #3: worker crash after provider success but before DB update doesn't
     * cause blind duplication. Neither Facebook's nor Telegram's public
     * APIs accept a client idempotency token, so there's no safe way to
     * confirm whether a crashed worker's in-flight provider call already
     * succeeded — PublishEngineService now refuses to blindly retry a
     * reclaimed stale 'processing' attempt. It finalizes as
     * 'ambiguous_after_crash' and lands in the DLQ instead of silently
     * re-calling the provider (which would risk a real duplicate post) or
     * silently vanishing.
     */
    public function test_reclaiming_a_stale_processing_attempt_never_calls_the_provider_again(): void
    {
        Http::fake([
            'graph.facebook.com/*/feed' => Http::response(['id' => 'fb-should-not-be-called'], 200),
        ]);
        config()->set('services.facebook.graph_url', 'https://graph.facebook.com');
        config()->set('publishing.claim_stale_after_seconds', 300);

        $user = User::factory()->create();
        [$post, $page] = $this->makeFacebookPost($user, 'batch-crash-1');

        $attempt = $this->asOrganizationOf($user, fn () => PostPublicationAttempt::query()->create([
            'post_id' => $post->id,
            'social_account_id' => $page->social_account_id,
            'social_page_id' => $page->id,
            'idempotency_key' => hash('sha256', 'org-'.$user->current_organization_id.'-post-'.$post->id.'-page-'.$page->id.'-batch-batch-crash-1'),
            'attempt_number' => 1,
            'status' => 'processing',
            'claimed_at' => now()->subSeconds(600),
            'claimed_by' => 'crashed-worker',
        ]));

        $result = $this->asOrganizationOf($user, fn () => app(PublishEngineService::class)->publish($post->fresh(), $page, 'batch-crash-1'));

        Http::assertNothingSent();
        $this->assertSame('failed', $result['status']);
        $this->assertSame(PublishErrorClassifier::AMBIGUOUS_AFTER_CRASH, $result['classification']);

        $attempt->refresh();
        $this->assertSame('dead_letter', $attempt->status);
        $this->assertSame(PublishErrorClassifier::AMBIGUOUS_AFTER_CRASH, $attempt->error_classification);
    }

    /**
     * #4: a 429 response's Retry-After header is respected verbatim rather
     * than the generic backoff calculation.
     */
    public function test_429_with_retry_after_header_schedules_the_retry_for_that_exact_delay(): void
    {
        Http::fake([
            'graph.facebook.com/*/feed' => Http::response(['error' => 'rate limited'], 429, ['Retry-After' => '120']),
        ]);
        config()->set('services.facebook.graph_url', 'https://graph.facebook.com');

        $user = User::factory()->create();
        [$post, $page] = $this->makeFacebookPost($user, 'batch-429-1');

        $result = $this->asOrganizationOf($user, fn () => app(PublishEngineService::class)->publish($post->fresh(), $page, 'batch-429-1'));

        $this->assertSame('retry_scheduled', $result['status']);
        $this->assertSame(PublishErrorClassifier::RETRYABLE, $result['classification']);

        $nextAttemptAt = $result['next_attempt_at'];
        $this->assertNotNull($nextAttemptAt);
        $this->assertTrue($nextAttemptAt->between(now()->addSeconds(118), now()->addSeconds(122)));
    }

    /**
     * #5: a 5xx (provider-side trouble) is classified retryable and
     * scheduled per the exponential-backoff-with-jitter calculation rather
     * than Facebook's Retry-After (which it didn't send).
     */
    public function test_5xx_response_is_retried_using_backoff_with_jitter(): void
    {
        Http::fake([
            'graph.facebook.com/*/feed' => Http::response(['error' => 'internal'], 503),
        ]);
        config()->set('services.facebook.graph_url', 'https://graph.facebook.com');
        config()->set('publishing.backoff_base_seconds', 10);
        config()->set('publishing.backoff_max_seconds', 900);

        $user = User::factory()->create();
        [$post, $page] = $this->makeFacebookPost($user, 'batch-5xx-1');

        $result = $this->asOrganizationOf($user, fn () => app(PublishEngineService::class)->publish($post->fresh(), $page, 'batch-5xx-1'));

        $this->assertSame('retry_scheduled', $result['status']);
        $this->assertSame(PublishErrorClassifier::RETRYABLE, $result['classification']);

        // attempt_number 1 -> base 10s ± 30% jitter = [7s, 13s]. Generous
        // bounds to avoid test flakiness while still proving it's neither
        // instant nor anywhere near the max backoff ceiling.
        $nextAttemptAt = $result['next_attempt_at'];
        $this->assertTrue($nextAttemptAt->between(now()->addSeconds(5), now()->addSeconds(20)));
    }

    /**
     * #6: a permanent auth/permission error (401) never enters the retry
     * loop — it goes straight to 'failed'/dead_letter on the very first
     * attempt, with exactly one provider call made.
     */
    public function test_permanent_auth_error_finalizes_immediately_without_any_retry(): void
    {
        Http::fake([
            'graph.facebook.com/*/feed' => Http::response(['error' => 'invalid token'], 401),
        ]);
        config()->set('services.facebook.graph_url', 'https://graph.facebook.com');

        $user = User::factory()->create();
        [$post, $page] = $this->makeFacebookPost($user, 'batch-401-1');

        $result = $this->asOrganizationOf($user, fn () => app(PublishEngineService::class)->publish($post->fresh(), $page, 'batch-401-1'));

        $this->assertSame('failed', $result['status']);
        $this->assertSame(PublishErrorClassifier::NON_RETRYABLE, $result['classification']);

        Http::assertSentCount(1);

        $this->asOrganizationOf($user, function () use ($result): void {
            $attempt = PostPublicationAttempt::query()->findOrFail($result['attempt_id']);
            $this->assertSame('dead_letter', $attempt->status);
        });
    }

    /**
     * #7: once an attempt has exhausted its retry budget (max_retries), the
     * next retryable failure finalizes straight to dead_letter instead of
     * scheduling yet another retry.
     */
    public function test_exhausting_max_retries_moves_the_attempt_to_dead_letter(): void
    {
        Http::fake([
            'graph.facebook.com/*/feed' => Http::response(['error' => 'internal'], 503),
        ]);
        config()->set('services.facebook.graph_url', 'https://graph.facebook.com');
        config()->set('publishing.max_retries', 3);

        $user = User::factory()->create();
        [$post, $page] = $this->makeFacebookPost($user, 'batch-maxretries-1');

        $attempt = $this->asOrganizationOf($user, fn () => PostPublicationAttempt::query()->create([
            'post_id' => $post->id,
            'social_account_id' => $page->social_account_id,
            'social_page_id' => $page->id,
            'idempotency_key' => hash('sha256', 'org-'.$user->current_organization_id.'-post-'.$post->id.'-page-'.$page->id.'-batch-batch-maxretries-1'),
            'attempt_number' => 3,
            'status' => 'retry_scheduled',
            'next_attempt_at' => now()->subSecond(),
        ]));

        $result = $this->asOrganizationOf($user, fn () => app(PublishEngineService::class)->publish($post->fresh(), $page, 'batch-maxretries-1'));

        $this->assertSame('failed', $result['status']);

        $attempt->refresh();
        $this->assertSame('dead_letter', $attempt->status);
    }

    /**
     * A retry is tied to its original idempotency key. This regression covers
     * the previous bug where RetryDuePublishAttemptsJob passed a null batch
     * key to PublishPostJob, causing PublishEngineService to create a second
     * "default" attempt instead of processing the retry that was due.
     */
    public function test_due_retry_processes_its_original_attempt_without_creating_a_default_batch_duplicate(): void
    {
        Http::fake([
            'graph.facebook.com/*/feed' => Http::response(['id' => 'fb-retry-1'], 200),
        ]);
        config()->set('services.facebook.graph_url', 'https://graph.facebook.com');

        $user = User::factory()->create();
        [$post, $page] = $this->makeFacebookPost($user, 'batch-retry-worker', postStatus: 'publishing');

        $attempt = $this->asOrganizationOf($user, fn () => PostPublicationAttempt::query()->create([
            'post_id' => $post->id,
            'social_account_id' => $page->social_account_id,
            'social_page_id' => $page->id,
            'idempotency_key' => hash('sha256', 'org-'.$user->current_organization_id.'-post-'.$post->id.'-page-'.$page->id.'-batch-batch-retry-worker'),
            'attempt_number' => 1,
            'status' => 'retry_scheduled',
            'next_attempt_at' => now()->subSecond(),
        ]));

        (new PublishPostJob($post->id, $page->id, null, $user->current_organization_id, $attempt->id))
            ->handle(app(PublishEngineService::class));

        Http::assertSentCount(1);

        $this->asOrganizationOf($user, function () use ($attempt, $post, $page): void {
            $this->assertSame('success', $attempt->fresh()->status);
            $this->assertSame(1, PostPublicationAttempt::query()
                ->where('post_id', $post->id)
                ->where('social_page_id', $page->id)
                ->count());
        });
    }

    /**
     * #9 (first half — post cancellation): a post cancelled (markDraft) back
     * to 'draft' after PublishPostJob was already dispatched must not be
     * published when that job eventually runs. The other half of #9
     * (author removed from the organization) is covered by
     * PublishPostJobMembershipCheckTest.
     */
    public function test_cancelling_a_post_after_dispatch_prevents_the_queued_job_from_publishing_it(): void
    {
        Http::fake([
            'graph.facebook.com/*/feed' => Http::response(['id' => 'fb-should-not-publish'], 200),
        ]);
        config()->set('services.facebook.graph_url', 'https://graph.facebook.com');

        $user = User::factory()->create();
        [$post, $page] = $this->makeFacebookPost($user, 'batch-cancel-1', postStatus: 'publishing');

        // Cancel it — user pulled the post back to draft after the job was
        // already queued but before a worker picked it up.
        $this->asOrganizationOf($user, fn () => app(PostStateMachine::class)->transition($post, 'draft'));

        (new PublishPostJob($post->id, $page->id, 'batch-cancel-1', $user->current_organization_id))
            ->handle(app(PublishEngineService::class));

        Http::assertNothingSent();

        $this->asOrganizationOf($user, function () use ($post): void {
            $post->refresh();
            $this->assertSame('draft', $post->status);
        });
    }

    /**
     * #11: the organization-scoped circuit breaker trips after that org's
     * own repeated failures on a provider, but a DIFFERENT organization
     * publishing to the same provider is completely unaffected — a single
     * noisy tenant (e.g. a revoked token) can never degrade publishing for
     * anyone else.
     */
    public function test_org_scoped_circuit_breaker_never_blocks_a_different_organization(): void
    {
        Cache::flush();
        config()->set('publishing.org_circuit_breaker_threshold', 3);
        config()->set('publishing.circuit_breaker_threshold', 5);

        $orgAUser = User::factory()->create();
        $orgBUser = User::factory()->create();

        config()->set('services.facebook.graph_url', 'https://graph.facebook.com');

        // A single fake covering the whole test: org B's page id gets a
        // success response, everything else (org A's pages) gets a 401.
        // Http::fake() stubs accumulate rather than replace when called
        // more than once for the same URL pattern, so this has to be one
        // fake with routing logic rather than two sequential Http::fake()
        // calls.
        Http::fake(function ($request) {
            return str_contains($request->url(), 'page-batch-orgB-1')
                ? Http::response(['id' => 'fb-org-b-ok'], 200)
                : Http::response(['error' => 'invalid token'], 401);
        });

        // Org A racks up 3 non-retryable failures on facebook — enough to
        // trip ITS OWN org-scoped circuit, but well under the shared
        // provider-wide threshold (5).
        foreach (range(1, 3) as $i) {
            [$post, $page] = $this->makeFacebookPost($orgAUser, 'batch-orgA-'.$i);
            $this->asOrganizationOf($orgAUser, fn () => app(PublishEngineService::class)->publish($post->fresh(), $page, 'batch-orgA-'.$i));
        }

        // Org A's own next publish attempt is now circuit-blocked...
        [$blockedPost, $blockedPage] = $this->makeFacebookPost($orgAUser, 'batch-orgA-blocked');
        $blockedResult = $this->asOrganizationOf($orgAUser, fn () => app(PublishEngineService::class)->publish($blockedPost->fresh(), $blockedPage, 'batch-orgA-blocked'));
        $this->assertSame('retry_scheduled', $blockedResult['status']);
        $this->assertSame('circuit_open', $blockedResult['reason'] ?? null);

        // ...but Org B, publishing to facebook via its own unrelated,
        // working account, is entirely unaffected.
        [$orgBPost, $orgBPage] = $this->makeFacebookPost($orgBUser, 'batch-orgB-1');
        $orgBResult = $this->asOrganizationOf($orgBUser, fn () => app(PublishEngineService::class)->publish($orgBPost->fresh(), $orgBPage, 'batch-orgB-1'));

        $this->assertSame('success', $orgBResult['status']);
    }

    /**
     * A single org's account-specific failures (revoked token, missing
     * permission — HTTP 400/401/403/404/410/422, PublishErrorClassifier's
     * NON_RETRYABLE bucket) used to still count toward the SHARED
     * provider-wide circuit, alongside every other org's. Two unrelated
     * orgs each hitting a few of their own 401s could sum past the shared
     * threshold and block a third org that never had any problem at all —
     * even though neither original failure was ever the provider's fault.
     * Confirms NON_RETRYABLE failures no longer contribute to the shared
     * counter at all, regardless of how many orgs produce them.
     */
    public function test_non_retryable_failures_across_multiple_orgs_never_trip_the_shared_circuit(): void
    {
        Cache::flush();
        config()->set('publishing.org_circuit_breaker_threshold', 3);
        config()->set('publishing.circuit_breaker_threshold', 5);

        $orgAUser = User::factory()->create();
        $orgCUser = User::factory()->create();
        $orgDUser = User::factory()->create();

        config()->set('services.facebook.graph_url', 'https://graph.facebook.com');

        Http::fake(function ($request) {
            return str_contains($request->url(), 'page-batch-orgD-1')
                ? Http::response(['id' => 'fb-org-d-ok'], 200)
                : Http::response(['error' => 'invalid token'], 401);
        });

        // Org A: 2 non-retryable failures (under its own org threshold of 3).
        foreach (range(1, 2) as $i) {
            [$post, $page] = $this->makeFacebookPost($orgAUser, 'batch-orgA2-'.$i);
            $this->asOrganizationOf($orgAUser, fn () => app(PublishEngineService::class)->publish($post->fresh(), $page, 'batch-orgA2-'.$i));
        }

        // Org C: 3 non-retryable failures (trips its OWN org circuit).
        foreach (range(1, 3) as $i) {
            [$post, $page] = $this->makeFacebookPost($orgCUser, 'batch-orgC-'.$i);
            $this->asOrganizationOf($orgCUser, fn () => app(PublishEngineService::class)->publish($post->fresh(), $page, 'batch-orgC-'.$i));
        }

        // Combined, orgs A+C produced 5 non-retryable failures on facebook —
        // exactly the shared threshold under the old (buggy) accounting.
        // Org D, with a working account and zero failures of its own, must
        // still publish successfully.
        [$orgDPost, $orgDPage] = $this->makeFacebookPost($orgDUser, 'batch-orgD-1');
        $orgDResult = $this->asOrganizationOf($orgDUser, fn () => app(PublishEngineService::class)->publish($orgDPost->fresh(), $orgDPage, 'batch-orgD-1'));

        $this->assertSame('success', $orgDResult['status']);
    }

    /**
     * #12: scheduled and immediate ("publish now") publishing converge on
     * the identical trusted path — both transition the post to the same
     * 'publishing' intermediate state (never straight to 'published' or
     * skipping it to 'scheduled') before PublishPostJob is dispatched, so
     * exactly one code path (PublishPostJob::handle) is ever responsible
     * for the actual publish attempt regardless of how it was triggered.
     */
    public function test_immediate_publish_now_uses_the_same_publishing_intermediate_state_as_scheduled_posts(): void
    {
        $user = User::factory()->create();
        Permission::query()->firstOrCreate(['name' => 'posts.publish', 'guard_name' => 'sanctum']);
        $user->givePermissionTo('posts.publish');

        [$post, $page] = $this->makeFacebookPost($user, 'batch-parity-1', postStatus: 'draft');

        Queue::fake();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/posts/'.$post->id.'/publish-now', [
            'social_page_ids' => [$page->id],
        ]);

        $response->assertOk();

        $post->refresh();
        $this->assertSame('publishing', $post->status);

        Queue::assertPushed(PublishPostJob::class);
    }

    private function makeImageAttachment(Post $post, User $user, string $path = 'media/2026/08/photo.jpg'): MediaAttachment
    {
        return MediaAttachment::query()->create([
            'post_id' => $post->id,
            'user_id' => $user->id,
            'type' => 'image',
            'collection' => 'default',
            'disk' => 'public',
            'path' => $path,
            'mime_type' => 'image/jpeg',
            'size' => 16,
            'meta' => ['original_name' => basename($path)],
        ]);
    }

    /**
     * 2026-08-11: FacebookOAuthProvider::publishPost() genuinely uploads
     * images now (single via /photos, multiple via unpublished /photos +
     * /feed attached_media — see that class) instead of silently dropping
     * every attachment, so this combination is no longer rejected up
     * front. ClosedBetaPublishingGateTest below still covers what remains
     * genuinely unsupported (a document attachment, more than one video,
     * mixing video with images).
     */
    public function test_publish_now_accepts_a_facebook_target_with_a_single_image_attachment(): void
    {
        $user = User::factory()->create();
        Permission::query()->firstOrCreate(['name' => 'posts.publish', 'guard_name' => 'sanctum']);
        $user->givePermissionTo('posts.publish');

        [$post, $page] = $this->makeFacebookPost($user, 'batch-fb-media-1', postStatus: 'draft');
        $this->asOrganizationOf($user, fn () => $this->makeImageAttachment($post, $user));

        Queue::fake();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/posts/'.$post->id.'/publish-now', [
            'social_page_ids' => [$page->id],
        ]);

        $response->assertOk();
        $this->assertSame('publishing', $post->fresh()->status);
        Queue::assertPushed(PublishPostJob::class);
    }

    public function test_publish_now_accepts_a_facebook_target_with_multiple_image_attachments(): void
    {
        $user = User::factory()->create();
        Permission::query()->firstOrCreate(['name' => 'posts.publish', 'guard_name' => 'sanctum']);
        $user->givePermissionTo('posts.publish');

        [$post, $page] = $this->makeFacebookPost($user, 'batch-fb-media-multi', postStatus: 'draft');
        $this->asOrganizationOf($user, function () use ($post, $user): void {
            $this->makeImageAttachment($post, $user, 'media/2026/08/photo-1.jpg');
            $this->makeImageAttachment($post, $user, 'media/2026/08/photo-2.jpg');
        });

        Queue::fake();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/posts/'.$post->id.'/publish-now', [
            'social_page_ids' => [$page->id],
        ]);

        $response->assertOk();
        Queue::assertPushed(PublishPostJob::class);
    }

    /**
     * Companion to LocalizedApiErrorsTest — the gate's remaining message
     * (for what's genuinely still unsupported) was still hardcoded English
     * regardless of locale until 2026-08-11.
     */
    public function test_publish_now_rejects_a_facebook_target_with_a_document_attachment_with_an_arabic_message_when_requested(): void
    {
        $user = User::factory()->create();
        Permission::query()->firstOrCreate(['name' => 'posts.publish', 'guard_name' => 'sanctum']);
        $user->givePermissionTo('posts.publish');

        [$post, $page] = $this->makeFacebookPost($user, 'batch-fb-media-ar-1', postStatus: 'draft');
        $this->asOrganizationOf($user, fn () => MediaAttachment::query()->create([
            'post_id' => $post->id,
            'user_id' => $user->id,
            'type' => 'document',
            'collection' => 'default',
            'disk' => 'public',
            'path' => 'media/2026/08/file.pdf',
            'mime_type' => 'application/pdf',
            'size' => 16,
            'meta' => ['original_name' => 'file.pdf'],
        ]));

        Queue::fake();
        Sanctum::actingAs($user);

        $this->withHeaders(['Accept-Language' => 'ar'])
            ->postJson('/api/v1/posts/'.$post->id.'/publish-now', [
                'social_page_ids' => [$page->id],
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.media_attachments.0',
                'تركيبة الوسائط هذه غير مدعومة لفيسبوك — يدعم فيسبوك صورة واحدة أو أكثر، أو فيديو واحد بالضبط (وليس فيديو مع صور معًا، ولا أكثر من فيديو واحد)، لكل منشور.',
            );

        $this->assertSame('draft', $post->fresh()->status);
        Queue::assertNotPushed(PublishPostJob::class);
    }

    public function test_publish_now_rejects_a_facebook_target_mixing_video_and_image_attachments(): void
    {
        $user = User::factory()->create();
        Permission::query()->firstOrCreate(['name' => 'posts.publish', 'guard_name' => 'sanctum']);
        $user->givePermissionTo('posts.publish');

        [$post, $page] = $this->makeFacebookPost($user, 'batch-fb-media-mixed', postStatus: 'draft');
        $this->asOrganizationOf($user, function () use ($post, $user): void {
            $this->makeImageAttachment($post, $user);
            MediaAttachment::query()->create([
                'post_id' => $post->id,
                'user_id' => $user->id,
                'type' => 'video',
                'collection' => 'default',
                'disk' => 'public',
                'path' => 'media/2026/08/clip.mp4',
                'mime_type' => 'video/mp4',
                'size' => 16,
                'meta' => ['original_name' => 'clip.mp4'],
            ]);
        });

        Queue::fake();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/posts/'.$post->id.'/publish-now', [
            'social_page_ids' => [$page->id],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath(
                'errors.media_attachments.0',
                "This combination of media isn't supported for Facebook yet — Facebook allows one or more images, or exactly one video (not mixed with images, and not more than one video), per post.",
            );

        $this->assertSame('draft', $post->fresh()->status);
        Queue::assertNotPushed(PublishPostJob::class);
    }

    public function test_schedule_accepts_a_facebook_target_with_a_single_image_attachment(): void
    {
        $user = User::factory()->create();
        Permission::query()->firstOrCreate(['name' => 'posts.schedule', 'guard_name' => 'sanctum']);
        $user->givePermissionTo('posts.schedule');

        [$post, $page] = $this->makeFacebookPost($user, 'batch-fb-media-2', postStatus: 'draft');
        $this->asOrganizationOf($user, function () use ($post, $page, $user): void {
            $post->socialPages()->sync([$page->id]);
            $this->makeImageAttachment($post, $user);
        });

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/posts/'.$post->id.'/schedule', [
            'scheduled_at' => now()->addHour()->toIso8601String(),
        ]);

        $response->assertOk();
        $this->assertSame('scheduled', $post->fresh()->status);
    }
}
