<?php

namespace Tests\Feature;

use App\Infrastructure\ExternalServices\Publishing\PublishEngineService;
use App\Jobs\RetryDeadLetteredAttemptJob;
use App\Models\DeadLetterJob;
use App\Models\OrganizationMembership;
use App\Models\Post;
use App\Models\PostPublicationAttempt;
use App\Models\SocialAccount;
use App\Models\SocialPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Sprint 3 acceptance criteria #8 ("manual DLQ retry is permission-gated and
 * audited") and #10 ("switching organization never reveals another
 * organization's attempts or errors") for the dead-letter retry endpoint.
 */
class DeadLetterRetryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Post, 1: SocialPage, 2: PostPublicationAttempt, 3: DeadLetterJob}
     */
    private function makeDeadLetteredAttempt(User $user): array
    {
        return $this->asOrganizationOf($user, function () use ($user) {
            $post = Post::query()->create([
                'user_id' => $user->id,
                'title' => 'Dead lettered post',
                'content' => 'Body',
                'status' => 'failed',
                'publish_batch_key' => 'batch-dlq-1',
                'failed_at' => now(),
                'last_error' => 'Revoked token.',
            ]);

            $socialAccount = SocialAccount::query()->create([
                'user_id' => $user->id,
                'provider' => 'facebook',
                'provider_account_id' => 'page-dlq-1',
                'access_token' => 'live-token',
                'status' => 'connected',
                'is_active' => true,
            ]);

            $page = SocialPage::query()->create([
                'social_account_id' => $socialAccount->id,
                'page_id' => 'page-dlq-1',
                'name' => 'Facebook Page',
                'can_publish' => true,
                'status' => 'valid',
            ]);

            $attempt = PostPublicationAttempt::query()->create([
                'post_id' => $post->id,
                'social_account_id' => $socialAccount->id,
                'social_page_id' => $page->id,
                'idempotency_key' => hash('sha256', 'org-'.$user->current_organization_id.'-post-'.$post->id.'-page-'.$page->id.'-batch-batch-dlq-1'),
                'attempt_number' => 1,
                'status' => 'dead_letter',
                'error_message' => 'Revoked token.',
                'error_classification' => 'non_retryable',
            ]);

            $deadLetterJob = DeadLetterJob::query()->create([
                'queue_name' => 'publishing',
                'job_class' => PostPublicationAttempt::class,
                'reference_type' => 'post_publication_attempt',
                'reference_id' => $attempt->id,
                'payload' => json_encode(['post_id' => $post->id, 'social_page_id' => $page->id]),
                'error_message' => 'Revoked token.',
                'attempts' => 1,
                'failed_at' => now(),
            ]);

            return [$post, $page, $attempt, $deadLetterJob];
        });
    }

    public function test_manual_retry_is_permission_gated(): void
    {
        $user = User::factory()->create();
        [, , , $deadLetterJob] = $this->makeDeadLetteredAttempt($user);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/publishing/dead-letters/'.$deadLetterJob->id.'/retry');

        $response->assertForbidden();
    }

    public function test_manual_retry_dispatches_a_job_and_audits_who_and_when(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        Permission::query()->firstOrCreate(['name' => 'publishing.manage', 'guard_name' => 'sanctum']);
        $user->givePermissionTo('publishing.manage');

        [, , , $deadLetterJob] = $this->makeDeadLetteredAttempt($user);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/publishing/dead-letters/'.$deadLetterJob->id.'/retry');

        $response->assertOk();

        $deadLetterJob->refresh();
        $this->assertNotNull($deadLetterJob->retried_at);
        $this->assertSame($user->id, $deadLetterJob->retried_by);

        Queue::assertPushed(RetryDeadLetteredAttemptJob::class);
    }

    public function test_manual_retry_cannot_be_claimed_twice(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        Permission::query()->firstOrCreate(['name' => 'publishing.manage', 'guard_name' => 'sanctum']);
        $user->givePermissionTo('publishing.manage');

        [, , , $deadLetterJob] = $this->makeDeadLetteredAttempt($user);

        Sanctum::actingAs($user);

        $first = $this->postJson('/api/v1/publishing/dead-letters/'.$deadLetterJob->id.'/retry');
        $second = $this->postJson('/api/v1/publishing/dead-letters/'.$deadLetterJob->id.'/retry');

        $first->assertOk();
        $second->assertStatus(409);

        Queue::assertPushed(RetryDeadLetteredAttemptJob::class, 1);
    }

    public function test_manual_retry_404s_for_a_dead_letter_belonging_to_another_organization(): void
    {
        $owner = User::factory()->create();
        Permission::query()->firstOrCreate(['name' => 'publishing.manage', 'guard_name' => 'sanctum']);
        $owner->givePermissionTo('publishing.manage');
        [, , , $deadLetterJob] = $this->makeDeadLetteredAttempt($owner);

        $otherOrgUser = User::factory()->create();
        $otherOrgUser->givePermissionTo('publishing.manage');

        Sanctum::actingAs($otherOrgUser);

        $response = $this->postJson('/api/v1/publishing/dead-letters/'.$deadLetterJob->id.'/retry');

        $response->assertNotFound();
    }

    public function test_manual_retry_re_publishes_using_the_same_attempt_row_and_marks_the_post_published(): void
    {
        Http::fake([
            'graph.facebook.com/*/feed' => Http::response(['id' => 'fb-recovered-1'], 200),
        ]);
        config()->set('services.facebook.graph_url', 'https://graph.facebook.com');

        $user = User::factory()->create();
        Permission::query()->firstOrCreate(['name' => 'publishing.manage', 'guard_name' => 'sanctum']);
        $user->givePermissionTo('publishing.manage');

        [$post, , $attempt, $deadLetterJob] = $this->makeDeadLetteredAttempt($user);

        Sanctum::actingAs($user);
        $this->postJson('/api/v1/publishing/dead-letters/'.$deadLetterJob->id.'/retry')->assertOk();

        (new RetryDeadLetteredAttemptJob($attempt->id, $user->current_organization_id))
            ->handle(app(PublishEngineService::class));

        $this->assertSame(1, PostPublicationAttempt::query()->count());

        $this->asOrganizationOf($user, function () use ($attempt, $post): void {
            $attempt->refresh();
            $this->assertSame('success', $attempt->status);

            $post->refresh();
            $this->assertSame('published', $post->status);
        });
    }

    public function test_manual_retry_does_not_execute_if_the_author_was_removed_from_the_organization(): void
    {
        $owner = User::factory()->create();
        $author = User::factory()->create();
        OrganizationMembership::query()->create([
            'organization_id' => $owner->current_organization_id,
            'user_id' => $author->id,
            'role' => 'editor',
            'status' => 'active',
        ]);

        [$post, , $attempt] = $this->asOrganizationOf($owner, function () use ($owner, $author) {
            $post = Post::query()->create([
                'user_id' => $author->id,
                'title' => 'Dead lettered post',
                'content' => 'Body',
                'status' => 'failed',
                'publish_batch_key' => 'batch-dlq-2',
                'failed_at' => now(),
                'last_error' => 'Revoked token.',
            ]);

            $socialAccount = SocialAccount::query()->create([
                'user_id' => $author->id,
                'provider' => 'facebook',
                'provider_account_id' => 'page-dlq-2',
                'access_token' => 'live-token',
                'status' => 'connected',
                'is_active' => true,
            ]);

            $page = SocialPage::query()->create([
                'social_account_id' => $socialAccount->id,
                'page_id' => 'page-dlq-2',
                'name' => 'Facebook Page',
                'can_publish' => true,
                'status' => 'valid',
            ]);

            $attempt = PostPublicationAttempt::query()->create([
                'post_id' => $post->id,
                'social_account_id' => $socialAccount->id,
                'social_page_id' => $page->id,
                'idempotency_key' => hash('sha256', 'org-'.$owner->current_organization_id.'-post-'.$post->id.'-page-'.$page->id.'-batch-batch-dlq-2'),
                'attempt_number' => 1,
                'status' => 'dead_letter',
                'error_message' => 'Revoked token.',
                'error_classification' => 'non_retryable',
            ]);

            return [$post, $page, $attempt];
        });

        OrganizationMembership::query()
            ->where('organization_id', $owner->current_organization_id)
            ->where('user_id', $author->id)
            ->delete();

        (new RetryDeadLetteredAttemptJob($attempt->id, $owner->current_organization_id))
            ->handle(app(PublishEngineService::class));

        $this->asOrganizationOf($owner, function () use ($attempt, $post): void {
            $attempt->refresh();
            $this->assertSame('dead_letter', $attempt->status);

            $post->refresh();
            $this->assertSame('failed', $post->status);
        });
    }
}
