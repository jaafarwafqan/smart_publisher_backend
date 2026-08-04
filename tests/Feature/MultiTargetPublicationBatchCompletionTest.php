<?php

namespace Tests\Feature;

use App\Infrastructure\ExternalServices\Publishing\PublishEngineService;
use App\Jobs\PublishPostJob;
use App\Models\Notification;
use App\Models\Post;
use App\Models\PostPublicationAttempt;
use App\Models\SocialAccount;
use App\Models\SocialPage;
use App\Models\User;
use App\Support\Publishing\PublicationBatchCoordinator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class MultiTargetPublicationBatchCompletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_post_is_not_published_until_every_exact_target_attempt_settles(): void
    {
        Http::fake([
            'graph.facebook.com/*/feed' => Http::response(['id' => 'fb-multi-target-post'], 200),
        ]);
        config()->set('services.facebook.graph_url', 'https://graph.facebook.com');

        $user = User::factory()->create();
        $batchKey = (string) Str::uuid();

        [$post, $attempts] = $this->asOrganizationOf($user, function () use ($user, $batchKey): array {
            $post = Post::query()->create([
                'user_id' => $user->id,
                'title' => 'Two target release check',
                'content' => 'Publish this only after both targets finish.',
                'status' => 'publishing',
                'publish_batch_key' => $batchKey,
            ]);

            $account = SocialAccount::query()->create([
                'user_id' => $user->id,
                'provider' => 'facebook',
                'provider_account_id' => 'fb-multi-target-account',
                'access_token' => 'test-token',
                'status' => 'connected',
                'is_active' => true,
            ]);

            $firstPage = SocialPage::query()->create([
                'social_account_id' => $account->id,
                'page_id' => 'fb-multi-target-page-one',
                'name' => 'First target',
                'kind' => 'page',
                'can_publish' => true,
                'status' => 'valid',
            ]);
            $secondPage = SocialPage::query()->create([
                'social_account_id' => $account->id,
                'page_id' => 'fb-multi-target-page-two',
                'name' => 'Second target',
                'kind' => 'page',
                'can_publish' => true,
                'status' => 'valid',
            ]);
            $post->socialPages()->sync([$firstPage->id, $secondPage->id]);

            $pages = SocialPage::query()
                ->with('socialAccount')
                ->whereIn('id', [$firstPage->id, $secondPage->id])
                ->orderBy('id')
                ->get();

            $attempts = app(PublicationBatchCoordinator::class)
                ->createPendingAttempts($post, $pages, $batchKey);

            return [$post, $attempts];
        });

        /** @var PostPublicationAttempt $firstAttempt */
        $firstAttempt = $attempts->firstOrFail();
        /** @var PostPublicationAttempt $secondAttempt */
        $secondAttempt = $attempts->last();

        (new PublishPostJob(
            $post->id,
            $firstAttempt->social_page_id,
            $batchKey,
            $user->current_organization_id,
            $firstAttempt->id,
        ))->handle(app(PublishEngineService::class), app(PublicationBatchCoordinator::class));

        $this->asOrganizationOf($user, function () use ($post, $batchKey): void {
            $this->assertSame('publishing', $post->fresh()->status);
            $this->assertSame(2, PostPublicationAttempt::query()
                ->where('post_id', $post->id)
                ->where('publish_batch_key', $batchKey)
                ->count());
            $this->assertSame(1, PostPublicationAttempt::query()
                ->where('post_id', $post->id)
                ->where('publish_batch_key', $batchKey)
                ->where('status', 'success')
                ->count());
            $this->assertSame(1, PostPublicationAttempt::query()
                ->where('post_id', $post->id)
                ->where('publish_batch_key', $batchKey)
                ->where('status', 'pending')
                ->count());
            $this->assertSame(0, Notification::query()
                ->where('type', 'post.publish_succeeded')
                ->count());
        });

        (new PublishPostJob(
            $post->id,
            $secondAttempt->social_page_id,
            $batchKey,
            $user->current_organization_id,
            $secondAttempt->id,
        ))->handle(app(PublishEngineService::class), app(PublicationBatchCoordinator::class));

        $this->asOrganizationOf($user, function () use ($post, $batchKey): void {
            $this->assertSame('published', $post->fresh()->status);
            $this->assertSame(2, PostPublicationAttempt::query()
                ->where('post_id', $post->id)
                ->where('publish_batch_key', $batchKey)
                ->where('status', 'success')
                ->count());
            $this->assertSame(1, Notification::query()
                ->where('type', 'post.publish_succeeded')
                ->count());
        });
    }
}
