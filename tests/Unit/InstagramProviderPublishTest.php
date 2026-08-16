<?php

namespace Tests\Unit;

use App\Exceptions\Publishing\ProviderPublishException;
use App\Infrastructure\ExternalServices\SocialOAuth\FacebookOAuthProvider;
use App\Infrastructure\ExternalServices\SocialOAuth\InstagramProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 2026-08: real Content Publishing API calls (container create, poll,
 * publish) — mirrors FacebookOAuthProviderPublishTest's structure for the
 * same real-vs-mocked-attachment reasoning.
 */
class InstagramProviderPublishTest extends TestCase
{
    private InstagramProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.facebook.graph_url', 'https://graph.facebook.com');
        config()->set('services.instagram.status_poll_delay_seconds', 0);
        Storage::fake('public');

        $this->provider = new InstagramProvider(
            new FacebookOAuthProvider(Http::getFacadeRoot()),
            Http::getFacadeRoot(),
        );
    }

    private function attachment(string $type, string $path): array
    {
        Storage::disk('public')->put($path, 'fake-binary-content');

        return [
            'disk' => 'public',
            'path' => $path,
            'mime_type' => $type === 'image' ? 'image/jpeg' : 'video/mp4',
            'type' => $type,
            'original_name' => basename($path),
        ];
    }

    public function test_rejects_a_text_only_post(): void
    {
        $this->expectException(ProviderPublishException::class);
        $this->expectExceptionMessage('at least one image or video');

        $this->provider->publishPost('page-token', [
            'provider_account_id' => 'ig-user-1',
            'content' => 'No media here',
            'attachments' => [],
        ]);
    }

    public function test_publishes_a_single_image(): void
    {
        Http::fake([
            'graph.facebook.com/ig-user-1/media' => Http::response(['id' => 'container-1'], 200),
            // 2026-08, found via a real live publish: even an image
            // container can still be processing when media_publish is
            // called right after creation ("Media ID is not available") —
            // publishContainer() now polls status_code before every
            // publish, not just for video.
            'graph.facebook.com/container-1*' => Http::response(['status_code' => 'FINISHED'], 200),
            'graph.facebook.com/ig-user-1/media_publish' => Http::response(['id' => 'ig-post-1'], 200),
        ]);

        $result = $this->provider->publishPost('page-token', [
            'provider_account_id' => 'ig-user-1',
            'content' => 'A single photo',
            'attachments' => [$this->attachment('image', 'media/photo.jpg')],
        ]);

        $this->assertSame('ig-post-1', $result['provider_post_id']);
        $this->assertSame('published', $result['status']);

        Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/ig-user-1/media'
            && $request['caption'] === 'A single photo'
            && $request['access_token'] === 'page-token'
            && str_contains((string) $request['image_url'], 'media/photo.jpg'));

        Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/ig-user-1/media_publish'
            && $request['creation_id'] === 'container-1');
    }

    /**
     * Regression test for a real bug found via a live staging publish
     * (2026-08): an image container that isn't instantly ready used to
     * hit media_publish immediately and fail with Meta's real
     * "Media ID is not available" (code 9007) error — the status poll only
     * ran for video containers before this fix.
     */
    public function test_publishes_a_single_image_after_polling_for_finished_status(): void
    {
        Http::fake([
            'graph.facebook.com/ig-user-1/media' => Http::response(['id' => 'container-slow-1'], 200),
            'graph.facebook.com/container-slow-1*' => Http::sequence()
                ->push(['status_code' => 'IN_PROGRESS'])
                ->push(['status_code' => 'FINISHED']),
            'graph.facebook.com/ig-user-1/media_publish' => Http::response(['id' => 'ig-post-slow-1'], 200),
        ]);

        $result = $this->provider->publishPost('page-token', [
            'provider_account_id' => 'ig-user-1',
            'content' => 'A slow-processing photo',
            'attachments' => [$this->attachment('image', 'media/slow.jpg')],
        ]);

        $this->assertSame('ig-post-slow-1', $result['provider_post_id']);
    }

    public function test_publishes_a_video_after_polling_for_finished_status(): void
    {
        Http::fake([
            'graph.facebook.com/ig-user-1/media' => Http::response(['id' => 'video-container-1'], 200),
            'graph.facebook.com/video-container-1*' => Http::sequence()
                ->push(['status_code' => 'IN_PROGRESS'])
                ->push(['status_code' => 'FINISHED']),
            'graph.facebook.com/ig-user-1/media_publish' => Http::response(['id' => 'ig-post-2'], 200),
        ]);

        $result = $this->provider->publishPost('page-token', [
            'provider_account_id' => 'ig-user-1',
            'content' => 'A reel',
            'attachments' => [$this->attachment('video', 'media/clip.mp4')],
        ]);

        $this->assertSame('ig-post-2', $result['provider_post_id']);

        Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/ig-user-1/media'
            && ($request['media_type'] ?? null) === 'REELS');
    }

    public function test_video_processing_error_is_surfaced_instead_of_publishing(): void
    {
        Http::fake([
            'graph.facebook.com/ig-user-1/media' => Http::response(['id' => 'video-container-2'], 200),
            'graph.facebook.com/video-container-2*' => Http::response(['status_code' => 'ERROR'], 200),
        ]);

        $this->expectException(ProviderPublishException::class);
        $this->expectExceptionMessage('processing failed');

        $this->provider->publishPost('page-token', [
            'provider_account_id' => 'ig-user-1',
            'content' => 'A broken reel',
            'attachments' => [$this->attachment('video', 'media/broken.mp4')],
        ]);
    }

    public function test_publishes_a_carousel_of_multiple_images(): void
    {
        Http::fake([
            'graph.facebook.com/ig-user-1/media' => Http::sequence()
                ->push(['id' => 'child-1'])
                ->push(['id' => 'child-2'])
                ->push(['id' => 'carousel-container-1']),
            'graph.facebook.com/carousel-container-1*' => Http::response(['status_code' => 'FINISHED'], 200),
            'graph.facebook.com/ig-user-1/media_publish' => Http::response(['id' => 'ig-post-3'], 200),
        ]);

        $result = $this->provider->publishPost('page-token', [
            'provider_account_id' => 'ig-user-1',
            'content' => 'A carousel',
            'attachments' => [
                $this->attachment('image', 'media/one.jpg'),
                $this->attachment('image', 'media/two.jpg'),
            ],
        ]);

        $this->assertSame('ig-post-3', $result['provider_post_id']);

        Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/ig-user-1/media'
            && ($request['media_type'] ?? null) === 'CAROUSEL'
            && $request['children'] === 'child-1,child-2');
    }

    public function test_rejects_an_unsupported_attachment_type(): void
    {
        $this->expectException(ProviderPublishException::class);
        $this->expectExceptionMessage('not this combination');

        $this->provider->publishPost('page-token', [
            'provider_account_id' => 'ig-user-1',
            'content' => 'A document',
            'attachments' => [$this->attachment('document', 'media/file.pdf')],
        ]);
    }

    public function test_rejects_more_than_ten_attachments(): void
    {
        $attachments = [];
        for ($i = 0; $i < 11; $i++) {
            $attachments[] = $this->attachment('image', "media/carousel-{$i}.jpg");
        }

        $this->expectException(ProviderPublishException::class);

        $this->provider->publishPost('page-token', [
            'provider_account_id' => 'ig-user-1',
            'content' => 'Too many photos',
            'attachments' => $attachments,
        ]);
    }
}
