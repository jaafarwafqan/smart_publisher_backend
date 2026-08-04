<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Infrastructure\ExternalServices\Publishing\PublishEngineService;
use App\Jobs\ProcessScheduledPostsJob;
use App\Jobs\PublishPostJob;
use App\Models\Notification;
use App\Models\OrganizationMembership;
use App\Models\Post;
use App\Models\PostPublicationAttempt;
use App\Models\SocialAccount;
use App\Models\SocialPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifications_are_isolated_by_organization_and_recipient(): void
    {
        $owner = User::factory()->create();
        $teammate = User::factory()->create();
        $otherOrganizationUser = User::factory()->create();
        $this->addToOrganization($owner, $teammate, OrganizationRole::Editor);

        $ownerNotification = $this->createNotification($owner, $owner, 'Owner-only notice');
        $teammateNotification = $this->createNotification($owner, $teammate, 'Teammate-only notice');
        $otherOrganizationNotification = $this->createNotification(
            $otherOrganizationUser,
            $otherOrganizationUser,
            'Another organization notice',
        );

        Sanctum::actingAs($owner);

        $this->withHeaders($this->organizationHeader($owner))
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.unread', 1)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', (string) $ownerNotification->id)
            ->assertJsonPath('data.items.0.read', false)
            ->assertJsonPath('data.items.0.is_read', false);

        // Same organization is not sufficient: a notification still belongs
        // to its individual recipient and must not be mutable by a teammate.
        $this->withHeaders($this->organizationHeader($owner))
            ->patchJson('/api/v1/notifications/'.$teammateNotification->id, ['is_read' => true])
            ->assertNotFound();

        // The global organization scope keeps even a guessed cross-tenant ID
        // out of route binding before the recipient policy is reached.
        $this->withHeaders($this->organizationHeader($owner))
            ->patchJson('/api/v1/notifications/'.$otherOrganizationNotification->id, ['is_read' => true])
            ->assertNotFound();
    }

    public function test_mark_read_endpoints_persist_only_the_authenticated_users_rows(): void
    {
        $owner = User::factory()->create();
        $teammate = User::factory()->create();
        $this->addToOrganization($owner, $teammate, OrganizationRole::Editor);

        $first = $this->createNotification($owner, $owner, 'First unread notice');
        $second = $this->createNotification($owner, $owner, 'Second unread notice');
        $teammateNotification = $this->createNotification($owner, $teammate, 'Teammate unread notice');

        Sanctum::actingAs($owner);

        $this->withHeaders($this->organizationHeader($owner))
            ->patchJson('/api/v1/notifications/'.$first->id, ['is_read' => true])
            ->assertOk();

        $this->withHeaders($this->organizationHeader($owner))
            ->postJson('/api/v1/notifications/mark-all-read')
            ->assertOk();

        $this->asOrganizationOf($owner, function () use ($first, $second, $teammateNotification): void {
            $this->assertNotNull($first->fresh()->read_at);
            $this->assertNotNull($second->fresh()->read_at);
            $this->assertNull($teammateNotification->fresh()->read_at);
        });
    }

    public function test_approval_request_approval_and_rejection_create_recipient_scoped_notifications(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();
        $this->addToOrganization($owner, $editor, OrganizationRole::Editor);

        $approvalPost = $this->createPostFor($owner, $editor, 'Needs approval');

        Sanctum::actingAs($editor);
        $this->withHeaders($this->organizationHeader($owner))
            ->postJson('/api/v1/posts/'.$approvalPost->id.'/schedule', [
                'scheduled_at' => now()->addHour()->toIso8601String(),
            ])
            ->assertStatus(202);

        $this->assertNotificationExists($owner, $owner, 'post.approval_requested', $approvalPost->id);

        Sanctum::actingAs($owner);
        $this->withHeaders($this->organizationHeader($owner))
            ->postJson('/api/v1/posts/'.$approvalPost->id.'/approve')
            ->assertOk();

        $this->assertNotificationExists($owner, $editor, 'post.approved', $approvalPost->id);

        $rejectionPost = $this->createPostFor($owner, $editor, 'Needs changes');

        Sanctum::actingAs($editor);
        $this->withHeaders($this->organizationHeader($owner))
            ->postJson('/api/v1/posts/'.$rejectionPost->id.'/schedule', [
                'scheduled_at' => now()->addHour()->toIso8601String(),
            ])
            ->assertStatus(202);

        Sanctum::actingAs($owner);
        $this->withHeaders($this->organizationHeader($owner))
            ->postJson('/api/v1/posts/'.$rejectionPost->id.'/reject', [
                'note' => 'Please add the source link.',
            ])
            ->assertOk();

        $this->assertNotificationExists($owner, $editor, 'post.rejected', $rejectionPost->id);
    }

    public function test_publish_success_failure_and_retry_exhaustion_create_real_notifications(): void
    {
        config()->set('services.facebook.graph_url', 'https://graph.facebook.com');
        config()->set('publishing.org_circuit_breaker_threshold', 100);
        config()->set('publishing.circuit_breaker_threshold', 100);

        $user = User::factory()->create();

        [$successfulPost, $successfulPage] = $this->createPublishingTarget(
            $user,
            'Successful publish',
            'success-batch',
            'access-token',
        );
        [$failedPost, $failedPage] = $this->createPublishingTarget(
            $user,
            'Failed publish',
            'failed-batch',
            null,
        );
        config()->set('publishing.max_retries', 1);
        [$exhaustedPost, $exhaustedPage] = $this->createPublishingTarget(
            $user,
            'Exhausted retries',
            'exhausted-batch',
            'access-token',
        );
        Http::fake([
            'graph.facebook.com/page-success-batch/feed' => Http::response(['id' => 'provider-post-1'], 200),
            'graph.facebook.com/page-exhausted-batch/feed' => Http::response(['error' => 'temporary outage'], 503),
        ]);

        (new PublishPostJob(
            $successfulPost->id,
            $successfulPage->id,
            $successfulPost->publish_batch_key,
            $user->current_organization_id,
        ))->handle(app(PublishEngineService::class));

        $this->assertNotificationExists($user, $user, 'post.publish_succeeded', $successfulPost->id);

        (new PublishPostJob(
            $failedPost->id,
            $failedPage->id,
            $failedPost->publish_batch_key,
            $user->current_organization_id,
        ))->handle(app(PublishEngineService::class));

        $this->assertNotificationExists($user, $user, 'post.publish_failed', $failedPost->id);

        (new PublishPostJob(
            $exhaustedPost->id,
            $exhaustedPage->id,
            $exhaustedPost->publish_batch_key,
            $user->current_organization_id,
        ))->handle(app(PublishEngineService::class));

        $this->asOrganizationOf($user, function () use ($exhaustedPost): void {
            $this->assertSame('failed', $exhaustedPost->fresh()->status);

            $attempt = PostPublicationAttempt::query()
                ->where('post_id', $exhaustedPost->id)
                ->latest('id')
                ->firstOrFail();
            $this->assertSame('dead_letter', $attempt->status);
            $this->assertSame('retryable', $attempt->error_classification);
        });

        $this->assertNotificationExists($user, $user, 'post.retry_exhausted', $exhaustedPost->id);
    }

    public function test_scheduler_failure_without_a_publishable_page_notifies_the_post_author(): void
    {
        $user = User::factory()->create();
        $post = $this->asOrganizationOf($user, fn () => Post::query()->create([
            'user_id' => $user->id,
            'title' => 'Scheduled post without a target',
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinute(),
        ]));

        (new ProcessScheduledPostsJob)->handle();

        $this->assertNotificationExists($user, $user, 'post.publish_failed', $post->id);
    }

    private function addToOrganization(User $owner, User $user, OrganizationRole $role): void
    {
        OrganizationMembership::query()->updateOrCreate(
            ['organization_id' => $owner->current_organization_id, 'user_id' => $user->id],
            ['role' => $role, 'status' => 'active'],
        );
    }

    /**
     * @return array<string, string>
     */
    private function organizationHeader(User $owner): array
    {
        return ['X-Organization-Id' => (string) $owner->current_organization_id];
    }

    private function createNotification(User $organizationOwner, User $recipient, string $title): Notification
    {
        return $this->asOrganizationOf($organizationOwner, fn () => Notification::query()->create([
            'user_id' => $recipient->id,
            'type' => 'test.notification',
            'title' => $title,
            'body' => 'Notification persistence test body.',
        ]));
    }

    private function createPostFor(User $organizationOwner, User $author, string $title): Post
    {
        return $this->asOrganizationOf($organizationOwner, fn () => Post::query()->create([
            'user_id' => $author->id,
            'title' => $title,
            'content' => 'Post content.',
            'status' => 'draft',
        ]));
    }

    /**
     * @return array{Post, SocialPage}
     */
    private function createPublishingTarget(
        User $user,
        string $title,
        string $batchKey,
        ?string $accessToken,
    ): array {
        return $this->asOrganizationOf($user, function () use ($user, $title, $batchKey, $accessToken): array {
            $post = Post::query()->create([
                'user_id' => $user->id,
                'title' => $title,
                'content' => 'Post body.',
                'status' => 'publishing',
                'publish_batch_key' => $batchKey,
            ]);

            $account = SocialAccount::query()->create([
                'user_id' => $user->id,
                'provider' => 'facebook',
                'provider_account_id' => 'facebook-'.$batchKey,
                'access_token' => $accessToken,
                'status' => 'connected',
                'is_active' => true,
            ]);

            $page = SocialPage::query()->create([
                'social_account_id' => $account->id,
                'page_id' => 'page-'.$batchKey,
                'kind' => 'page',
                'name' => 'Notification test page',
                'can_publish' => true,
                'status' => 'valid',
            ]);

            return [$post, $page];
        });
    }

    private function assertNotificationExists(User $organizationOwner, User $recipient, string $type, int $postId): void
    {
        $this->asOrganizationOf($organizationOwner, function () use ($recipient, $type, $postId): void {
            $notification = Notification::query()
                ->where('user_id', $recipient->id)
                ->where('type', $type)
                ->latest('id')
                ->first();

            $this->assertNotNull($notification, "Expected a {$type} notification.");
            $this->assertSame($postId, $notification->data['post_id'] ?? null);
        });
    }
}
