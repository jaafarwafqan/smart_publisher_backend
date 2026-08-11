<?php

namespace Tests\Unit;

use App\Exceptions\Publishing\ProviderPublishException;
use App\Infrastructure\ExternalServices\SocialOAuth\FacebookOAuthProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 2026-08-11: FacebookOAuthProvider::publishPost() previously only ever sent
 * the caption to /feed, silently dropping every attachment (reproduced live
 * — a real Facebook Page connection with a single photo attached reported a
 * fully successful publish while the photo never reached Facebook). This
 * covers the real Graph API calls it now makes instead — one photo, several
 * photos, and a single video — plus what's still genuinely unsupported
 * (ClosedBetaPublishingGateTest covers the same combinations at the HTTP
 * layer, before a job is even queued).
 */
class FacebookOAuthProviderPublishTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.facebook.graph_url', 'https://graph.facebook.com');
        Storage::fake('public');
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

    public function test_publishes_text_only_to_feed_when_there_are_no_attachments(): void
    {
        Http::fake([
            'graph.facebook.com/page-1/feed' => Http::response(['id' => 'page-1_post-1'], 200),
        ]);

        $provider = new FacebookOAuthProvider(Http::getFacadeRoot());
        $result = $provider->publishPost('user-token', [
            'provider_account_id' => 'page-1',
            'content' => 'Hello world',
            'attachments' => [],
        ]);

        $this->assertSame('page-1_post-1', $result['provider_post_id']);
        Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/page-1/feed'
            && $request['message'] === 'Hello world');
    }

    public function test_publishes_a_single_image_via_the_photos_endpoint(): void
    {
        Http::fake([
            'graph.facebook.com/page-1/photos' => Http::response(['id' => 'photo-1', 'post_id' => 'page-1_post-2'], 200),
        ]);

        $provider = new FacebookOAuthProvider(Http::getFacadeRoot());
        $result = $provider->publishPost('user-token', [
            'provider_account_id' => 'page-1',
            'content' => 'One photo',
            'attachments' => [$this->attachment('image', 'media/photo.jpg')],
        ]);

        // The real Feed post id (post_id), not the photo's own id.
        $this->assertSame('page-1_post-2', $result['provider_post_id']);
        // A multipart body (attach() + post()) — $request['field'] array access
        // isn't reliable for multipart under Http::fake(), so check the raw
        // encoded body directly instead.
        Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/page-1/photos'
            && str_contains((string) $request->body(), 'One photo'));
    }

    public function test_publishes_multiple_images_as_one_post_via_attached_media(): void
    {
        $photoUploads = 0;
        Http::fake([
            'graph.facebook.com/page-1/photos' => function () use (&$photoUploads) {
                $photoUploads++;

                return Http::response(['id' => 'photo-'.$photoUploads], 200);
            },
            'graph.facebook.com/page-1/feed' => Http::response(['id' => 'page-1_post-3'], 200),
        ]);

        $provider = new FacebookOAuthProvider(Http::getFacadeRoot());
        $result = $provider->publishPost('user-token', [
            'provider_account_id' => 'page-1',
            'content' => 'Two photos',
            'attachments' => [
                $this->attachment('image', 'media/photo-1.jpg'),
                $this->attachment('image', 'media/photo-2.jpg'),
            ],
        ]);

        $this->assertSame('page-1_post-3', $result['provider_post_id']);
        $this->assertSame(2, $photoUploads);
        // Multipart body — see the single-photo test's comment.
        Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/page-1/photos'
            && str_contains((string) $request->body(), 'published')
            && str_contains((string) $request->body(), 'false'));
        Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/page-1/feed'
            && $request['message'] === 'Two photos'
            && $request['attached_media[0]'] === json_encode(['media_fbid' => 'photo-1'])
            && $request['attached_media[1]'] === json_encode(['media_fbid' => 'photo-2']));
    }

    public function test_publishes_a_single_video_via_the_videos_endpoint(): void
    {
        Http::fake([
            'graph.facebook.com/page-1/videos' => Http::response(['id' => 'video-1'], 200),
        ]);

        $provider = new FacebookOAuthProvider(Http::getFacadeRoot());
        $result = $provider->publishPost('user-token', [
            'provider_account_id' => 'page-1',
            'content' => 'One clip',
            'attachments' => [$this->attachment('video', 'media/clip.mp4')],
        ]);

        $this->assertSame('video-1', $result['provider_post_id']);
        // Multipart body — see the single-photo test's comment.
        Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/page-1/videos'
            && str_contains((string) $request->body(), 'One clip'));
    }

    public function test_rejects_mixing_video_and_image_attachments(): void
    {
        $provider = new FacebookOAuthProvider(Http::getFacadeRoot());

        $this->expectException(ProviderPublishException::class);

        $provider->publishPost('user-token', [
            'provider_account_id' => 'page-1',
            'content' => 'Mixed',
            'attachments' => [
                $this->attachment('image', 'media/photo.jpg'),
                $this->attachment('video', 'media/clip.mp4'),
            ],
        ]);
    }

    public function test_rejects_more_than_one_video(): void
    {
        $provider = new FacebookOAuthProvider(Http::getFacadeRoot());

        $this->expectException(ProviderPublishException::class);

        $provider->publishPost('user-token', [
            'provider_account_id' => 'page-1',
            'content' => 'Two clips',
            'attachments' => [
                $this->attachment('video', 'media/clip-1.mp4'),
                $this->attachment('video', 'media/clip-2.mp4'),
            ],
        ]);
    }

    public function test_rejects_an_unsupported_attachment_type(): void
    {
        $provider = new FacebookOAuthProvider(Http::getFacadeRoot());

        $this->expectException(ProviderPublishException::class);

        $provider->publishPost('user-token', [
            'provider_account_id' => 'page-1',
            'content' => 'A document',
            'attachments' => [$this->attachment('document', 'media/file.pdf')],
        ]);
    }
}
