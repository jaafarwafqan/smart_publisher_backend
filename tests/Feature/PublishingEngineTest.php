<?php

namespace Tests\Feature;

use App\Infrastructure\ExternalServices\Publishing\PublishEngineService;
use App\Jobs\PublishPostJob;
use App\Models\MediaAttachment;
use App\Models\Post;
use App\Models\PostPublicationAttempt;
use App\Models\SocialAccount;
use App\Models\SocialPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PublishingEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_publish_now_dispatches_jobs_for_selected_pages(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        Permission::query()->firstOrCreate(['name' => 'posts.publish', 'guard_name' => 'sanctum']);
        $user->givePermissionTo('posts.publish');

        [$post, $page] = $this->asOrganizationOf($user, function () use ($user) {
            $post = Post::query()->create([
                'user_id' => $user->id,
                'title' => 'Publish Me',
                'status' => 'draft',
            ]);

            $socialAccount = SocialAccount::query()->create([
                'user_id' => $user->id,
                'provider' => 'facebook',
                'provider_account_id' => 'fb-123',
                'status' => 'connected',
                'is_active' => true,
            ]);

            $page = SocialPage::query()->create([
                'social_account_id' => $socialAccount->id,
                'page_id' => 'page-1',
                'name' => 'Test Page',
                'can_publish' => true,
                'status' => 'valid',
            ]);

            $post->socialPages()->sync([$page->id]);

            return [$post, $page];
        });

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/posts/'.$post->id.'/publish-now');

        $response->assertOk();

        $attempt = $this->asOrganizationOf($user, fn () => PostPublicationAttempt::query()
            ->where('post_id', $post->id)
            ->where('social_page_id', $page->id)
            ->firstOrFail());

        Queue::assertPushed(PublishPostJob::class, function (PublishPostJob $job) use ($post, $page, $attempt) {
            return $job->postId === $post->id
                && $job->socialPageId === $page->id
                && $job->publicationAttemptId === $attempt->id
                && $job->publishBatchKey === $attempt->publish_batch_key;
        });
    }

    public function test_publish_now_returns_422_when_no_usable_pages_are_selected(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        Permission::query()->firstOrCreate(['name' => 'posts.publish', 'guard_name' => 'sanctum']);
        $user->givePermissionTo('posts.publish');

        $post = $this->asOrganizationOf($user, fn () => Post::query()->create([
            'user_id' => $user->id,
            'title' => 'Publish Me',
            'status' => 'draft',
        ]));

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/posts/'.$post->id.'/publish-now');

        $response->assertStatus(422);
        Queue::assertNotPushed(PublishPostJob::class);
    }

    public function test_publish_now_with_explicit_social_page_ids_narrows_the_targets(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        Permission::query()->firstOrCreate(['name' => 'posts.publish', 'guard_name' => 'sanctum']);
        $user->givePermissionTo('posts.publish');

        [$post, $pageA] = $this->asOrganizationOf($user, function () use ($user) {
            $post = Post::query()->create([
                'user_id' => $user->id,
                'title' => 'Publish Me',
                'status' => 'draft',
            ]);

            $socialAccount = SocialAccount::query()->create([
                'user_id' => $user->id,
                'provider' => 'facebook',
                'provider_account_id' => 'fb-123',
                'status' => 'connected',
                'is_active' => true,
            ]);

            $pageA = SocialPage::query()->create([
                'social_account_id' => $socialAccount->id,
                'page_id' => 'page-a',
                'name' => 'Page A',
                'can_publish' => true,
                'status' => 'valid',
            ]);

            $pageB = SocialPage::query()->create([
                'social_account_id' => $socialAccount->id,
                'page_id' => 'page-b',
                'name' => 'Page B',
                'can_publish' => true,
                'status' => 'valid',
            ]);

            $post->socialPages()->sync([$pageA->id, $pageB->id]);

            return [$post, $pageA];
        });

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/posts/'.$post->id.'/publish-now', [
            'social_page_ids' => [$pageA->id],
        ]);

        $response->assertOk();

        Queue::assertPushed(PublishPostJob::class, 1);
        Queue::assertPushed(PublishPostJob::class, fn (PublishPostJob $job) => $job->socialPageId === $pageA->id);
    }

    public function test_publish_engine_can_publish_to_facebook_using_http_fake(): void
    {
        Http::fake([
            'graph.facebook.com/*/feed' => Http::response([
                'id' => 'fb-post-1',
            ], 200),
        ]);

        config()->set('services.facebook.graph_url', 'https://graph.facebook.com');
        config()->set('social.providers.facebook.client_id', 'client-id');
        config()->set('social.providers.facebook.client_secret', 'client-secret');
        config()->set('social.providers.facebook.authorize_url', 'https://www.facebook.com/v20.0/dialog/oauth');
        config()->set('social.providers.facebook.token_url', 'https://graph.facebook.com/v20.0/oauth/access_token');

        $user = User::factory()->create();

        [$result, $socialAccount] = $this->asOrganizationOf($user, function () use ($user) {
            $post = Post::query()->create([
                'user_id' => $user->id,
                'title' => 'Publish To Facebook',
                'content' => 'Post body',
                'status' => 'scheduled',
                'publish_batch_key' => 'batch-1',
            ]);

            $socialAccount = SocialAccount::query()->create([
                'user_id' => $user->id,
                'provider' => 'facebook',
                'provider_account_id' => 'page-123',
                'access_token' => 'live-token',
                'status' => 'connected',
                'is_active' => true,
            ]);

            $page = SocialPage::query()->create([
                'social_account_id' => $socialAccount->id,
                'page_id' => 'page-123',
                'name' => 'Facebook Page',
                'can_publish' => true,
                'status' => 'valid',
            ]);

            $this->assertNull($socialAccount->last_published_at);

            $result = app(PublishEngineService::class)->publish($post, $page, 'batch-1');

            return [$result, $socialAccount];
        });

        $this->assertSame('success', $result['status']);
        $this->assertSame('fb-post-1', $result['provider_response']['provider_post_id']);
        $this->asOrganizationOf($user, function () use ($socialAccount): void {
            $this->assertNotNull($socialAccount->fresh()->last_published_at);
        });
    }

    public function test_publish_engine_sends_media_attachments_to_telegram_as_documents(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('media/2026/07/report.xlsx', 'fake-xlsx-bytes');

        Http::fake([
            'api.telegram.org/bot*/sendDocument*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 555],
            ], 200),
        ]);

        $user = User::factory()->create();

        $result = $this->asOrganizationOf($user, function () use ($user) {
            $post = Post::query()->create([
                'user_id' => $user->id,
                'title' => 'Results',
                'content' => 'See attached results.',
                'status' => 'scheduled',
                'publish_batch_key' => 'batch-tg-1',
            ]);

            MediaAttachment::query()->create([
                'post_id' => $post->id,
                'user_id' => $user->id,
                'type' => 'document',
                'collection' => 'default',
                'disk' => 'public',
                'path' => 'media/2026/07/report.xlsx',
                'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'size' => 16,
                'meta' => ['original_name' => 'report.xlsx'],
            ]);

            $socialAccount = SocialAccount::query()->create([
                'user_id' => $user->id,
                'provider' => 'telegram',
                'discovery_mode' => 'manual',
                'provider_account_id' => '555',
                'access_token' => '123:ABC',
                'status' => 'connected',
                'is_active' => true,
            ]);

            $page = SocialPage::query()->create([
                'social_account_id' => $socialAccount->id,
                'page_id' => '-1001',
                'name' => 'Nursing Channel',
                'can_publish' => true,
                'status' => 'valid',
            ]);

            return app(PublishEngineService::class)->publish($post->fresh(), $page, 'batch-tg-1');
        });

        $this->assertSame('success', $result['status']);
        $this->assertSame('555', $result['provider_response']['provider_post_id']);

        Http::assertSent(function ($request) {
            $body = (string) $request->body();

            return str_contains($request->url(), 'sendDocument')
                && $request->isMultipart()
                && str_contains($body, '-1001')
                && str_contains($body, 'See attached results.');
        });
    }

    public function test_telegram_receives_real_html_for_bold_and_italic_markers(): void
    {
        Http::fake([
            'api.telegram.org/bot*/sendMessage*' => Http::response(
                ['ok' => true, 'result' => ['message_id' => 700]],
                200
            ),
        ]);

        $user = User::factory()->create();

        $this->asOrganizationOf($user, function () use ($user) {
            $post = Post::query()->create([
                'user_id' => $user->id,
                'title' => 'Announcement',
                'content' => 'This is **big** news, _really_.',
                'status' => 'scheduled',
                'publish_batch_key' => 'batch-md-1',
            ]);

            $socialAccount = SocialAccount::query()->create([
                'user_id' => $user->id,
                'provider' => 'telegram',
                'discovery_mode' => 'manual',
                'provider_account_id' => '555',
                'access_token' => '123:ABC',
                'status' => 'connected',
                'is_active' => true,
            ]);

            $page = SocialPage::query()->create([
                'social_account_id' => $socialAccount->id,
                'page_id' => '-1002',
                'name' => 'News Channel',
                'can_publish' => true,
                'status' => 'valid',
            ]);

            return app(PublishEngineService::class)->publish($post->fresh(), $page, 'batch-md-1');
        });

        Http::assertSent(function ($request) {
            $body = urldecode((string) $request->body());

            return str_contains($request->url(), 'sendMessage')
                && str_contains($body, 'This is <b>big</b> news, <i>really</i>.');
        });
    }

    public function test_facebook_receives_clean_plain_text_with_markers_stripped(): void
    {
        Http::fake([
            'graph.facebook.com/*/feed' => Http::response(['id' => 'fb-post-2'], 200),
        ]);

        config()->set('services.facebook.graph_url', 'https://graph.facebook.com');

        $user = User::factory()->create();

        $this->asOrganizationOf($user, function () use ($user) {
            $post = Post::query()->create([
                'user_id' => $user->id,
                'title' => 'Announcement',
                'content' => 'This is **big** news, _really_.',
                'status' => 'scheduled',
                'publish_batch_key' => 'batch-md-2',
            ]);

            $socialAccount = SocialAccount::query()->create([
                'user_id' => $user->id,
                'provider' => 'facebook',
                'provider_account_id' => 'page-456',
                'access_token' => 'live-token',
                'status' => 'connected',
                'is_active' => true,
            ]);

            $page = SocialPage::query()->create([
                'social_account_id' => $socialAccount->id,
                'page_id' => 'page-456',
                'name' => 'Facebook Page',
                'can_publish' => true,
                'status' => 'valid',
            ]);

            return app(PublishEngineService::class)->publish($post->fresh(), $page, 'batch-md-2');
        });

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/feed')
                && str_contains((string) $request->body(), 'message=This+is+big+news%2C+really.');
        });
    }

    public function test_a_platform_content_override_wins_over_the_shared_body(): void
    {
        Http::fake([
            'graph.facebook.com/*/feed' => Http::response(['id' => 'fb-post-3'], 200),
        ]);

        config()->set('services.facebook.graph_url', 'https://graph.facebook.com');

        $user = User::factory()->create();

        $this->asOrganizationOf($user, function () use ($user) {
            $post = Post::query()->create([
                'user_id' => $user->id,
                'title' => 'Announcement',
                'content' => 'Shared body text.',
                'status' => 'scheduled',
                'publish_batch_key' => 'batch-md-3',
                'meta' => ['platform_content' => ['facebook' => 'Facebook-specific caption.']],
            ]);

            $socialAccount = SocialAccount::query()->create([
                'user_id' => $user->id,
                'provider' => 'facebook',
                'provider_account_id' => 'page-789',
                'access_token' => 'live-token',
                'status' => 'connected',
                'is_active' => true,
            ]);

            $page = SocialPage::query()->create([
                'social_account_id' => $socialAccount->id,
                'page_id' => 'page-789',
                'name' => 'Facebook Page',
                'can_publish' => true,
                'status' => 'valid',
            ]);

            return app(PublishEngineService::class)->publish($post->fresh(), $page, 'batch-md-3');
        });

        Http::assertSent(function ($request) {
            $body = (string) $request->body();

            return str_contains($request->url(), '/feed')
                && str_contains($body, 'Facebook-specific+caption.')
                && ! str_contains($body, 'Shared+body');
        });
    }
}
