<?php

namespace Tests\Feature\Sandbox;

use App\Infrastructure\ExternalServices\Publishing\PublishEngineService;
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
 * CTO audit Sprint 4 (Connectors) — a REAL sandbox E2E test against the
 * live Telegram Bot API, not Http::fake. Deliberately opt-in: skips itself
 * unless SANDBOX_TELEGRAM_BOT_TOKEN/SANDBOX_TELEGRAM_CHANNEL are present in
 * .env, so it never runs in CI (no real secrets there, nor should there
 * be) and never accidentally posts to a real channel from an environment
 * that didn't deliberately opt in. Run manually via:
 *   php artisan test --filter=TelegramSandboxE2ETest
 *
 * First verified manually 2026-07-30: posted a clearly-marked test message
 * to the real "UOK Faculty Nursing" channel through the actual
 * PublishEngineService (the same code path production uses), confirmed
 * success, then deleted the message immediately via the Bot API — exactly
 * what this test automates for repeatable re-verification.
 */
#[Group('sandbox')]
class TelegramSandboxE2ETest extends TestCase
{
    use RefreshDatabase;

    public function test_a_real_message_publishes_to_the_live_sandbox_channel_and_is_immediately_deleted(): void
    {
        $botToken = env('SANDBOX_TELEGRAM_BOT_TOKEN');
        $channel = env('SANDBOX_TELEGRAM_CHANNEL');

        if (! $botToken || ! $channel) {
            $this->markTestSkipped('SANDBOX_TELEGRAM_BOT_TOKEN/SANDBOX_TELEGRAM_CHANNEL not set — this sandbox E2E test only runs when real Telegram sandbox credentials are configured locally.');
        }

        $user = User::factory()->create();

        $result = app(TenantContext::class)->run((int) $user->current_organization_id, function () use ($user, $botToken, $channel) {
            $post = Post::query()->create([
                'user_id' => $user->id,
                'title' => 'Sprint 4 sandbox E2E test',
                'content' => '🧪 Automated sandbox E2E test message — deleted immediately after this assertion.',
                'status' => 'publishing',
                'publish_batch_key' => 'sprint4-e2e-telegram-'.uniqid(),
            ]);

            $socialAccount = SocialAccount::query()->create([
                'user_id' => $user->id,
                'provider' => 'telegram',
                'discovery_mode' => 'manual',
                'provider_account_id' => 'sprint4-e2e-bot',
                'access_token' => $botToken,
                'status' => 'connected',
                'is_active' => true,
            ]);

            $page = SocialPage::query()->create([
                'social_account_id' => $socialAccount->id,
                'page_id' => $channel,
                'name' => 'Sandbox E2E channel',
                'can_publish' => true,
                'status' => 'valid',
            ]);

            return app(PublishEngineService::class)->publish($post->fresh(), $page, $post->publish_batch_key);
        });

        $this->assertSame('success', $result['status']);

        $messageId = $result['provider_response']['provider_post_id'] ?? null;
        $this->assertNotEmpty($messageId, 'Expected a real Telegram message_id back from the live API.');

        $delete = Http::post('https://api.telegram.org/bot'.$botToken.'/deleteMessage', [
            'chat_id' => $channel,
            'message_id' => $messageId,
        ]);

        $this->assertTrue((bool) $delete->json('ok'), 'Failed to delete the sandbox test message — it may still be visible in the real channel.');
    }
}
