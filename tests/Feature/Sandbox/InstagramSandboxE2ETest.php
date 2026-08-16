<?php

namespace Tests\Feature\Sandbox;

use App\Infrastructure\ExternalServices\Publishing\PublishEngineService;
use App\Models\MediaAttachment;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\SocialPage;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * A REAL sandbox E2E test against the live Instagram Content Publishing API,
 * not Http::fake — mirrors TelegramSandboxE2ETest's shape and reasoning.
 * Deliberately opt-in: skips itself unless every SANDBOX_INSTAGRAM_* env var
 * below is present, so it never runs in CI and never accidentally publishes
 * to a real Instagram account from an environment that didn't deliberately
 * opt in. Run manually via:
 *   php artisan test --filter=InstagramSandboxE2ETest
 *
 * Unlike Telegram/Facebook, Instagram's Content Publishing API fetches the
 * image itself over HTTP — it needs a real, already-uploaded attachment on a
 * disk PublicMediaUrlResolver can turn into a genuinely public URL (the real
 * media_upload_disk, i.e. Cloudflare R2/S3 — see filesystems.php), not a
 * throwaway local file. SANDBOX_INSTAGRAM_TEST_IMAGE_PATH must already exist
 * on that disk before running this.
 *
 * Unlike Facebook (which has no delete API for a Page post — see
 * docs/audit/KNOWN_ISSUES.md's 2026-08-16 entry), Instagram DOES support a
 * real delete, so this cleans up after itself the same way the Telegram
 * test does.
 */
#[Group('sandbox')]
class InstagramSandboxE2ETest extends TestCase
{
    use RefreshDatabase;

    public function test_a_real_image_publishes_to_the_live_sandbox_account_and_is_immediately_deleted(): void
    {
        $pageAccessToken = env('SANDBOX_INSTAGRAM_PAGE_ACCESS_TOKEN');
        $igUserId = env('SANDBOX_INSTAGRAM_IG_USER_ID');
        $imagePath = env('SANDBOX_INSTAGRAM_TEST_IMAGE_PATH');
        $mediaDisk = env('MEDIA_UPLOAD_DISK', 'local');

        if (! $pageAccessToken || ! $igUserId || ! $imagePath) {
            $this->markTestSkipped('SANDBOX_INSTAGRAM_PAGE_ACCESS_TOKEN/SANDBOX_INSTAGRAM_IG_USER_ID/SANDBOX_INSTAGRAM_TEST_IMAGE_PATH not set — this sandbox E2E test only runs when real Instagram sandbox credentials and a pre-uploaded test image are configured locally.');
        }

        $user = User::factory()->create();

        $result = app(TenantContext::class)->run((int) $user->current_organization_id, function () use ($user, $pageAccessToken, $igUserId, $imagePath, $mediaDisk) {
            $post = Post::query()->create([
                'user_id' => $user->id,
                'title' => 'Instagram sandbox E2E test',
                'content' => '🧪 Automated sandbox E2E test post — deleted immediately after this assertion.',
                'status' => 'publishing',
                'publish_batch_key' => 'instagram-e2e-'.uniqid(),
            ]);

            MediaAttachment::query()->create([
                'post_id' => $post->id,
                'user_id' => $user->id,
                'type' => 'image',
                'collection' => 'default',
                'disk' => $mediaDisk,
                'path' => $imagePath,
                'mime_type' => 'image/jpeg',
                'size' => 1,
                'meta' => ['original_name' => basename((string) $imagePath)],
            ]);

            $socialAccount = SocialAccount::query()->create([
                'user_id' => $user->id,
                'provider' => 'facebook',
                'discovery_mode' => 'auto',
                'provider_account_id' => 'instagram-e2e-account',
                'access_token' => $pageAccessToken,
                'status' => 'connected',
                'is_active' => true,
            ]);

            $page = SocialPage::query()->create([
                'social_account_id' => $socialAccount->id,
                'page_id' => $igUserId,
                'kind' => 'instagram_business',
                'name' => 'Sandbox E2E Instagram account',
                'access_token' => $pageAccessToken,
                'can_publish' => true,
                'status' => 'valid',
            ]);

            return app(PublishEngineService::class)->publish($post->fresh(), $page, $post->publish_batch_key);
        });

        $this->assertSame('success', $result['status']);

        $mediaId = $result['provider_response']['provider_post_id'] ?? null;
        $this->assertNotEmpty($mediaId, 'Expected a real Instagram media id back from the live API.');

        $delete = Http::delete('https://graph.facebook.com/'.$mediaId, [
            'access_token' => $pageAccessToken,
        ]);

        $this->assertTrue((bool) $delete->json('success'), 'Failed to delete the sandbox test post — it may still be visible on the real Instagram account.');
    }
}
