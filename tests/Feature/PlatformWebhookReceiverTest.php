<?php

namespace Tests\Feature;

use App\Infrastructure\ExternalServices\Publishing\PostMetricsSyncService;
use App\Jobs\ProcessPlatformWebhookEventJob;
use App\Models\PlatformWebhookEvent;
use App\Models\Post;
use App\Models\PostPublicationAttempt;
use App\Models\SocialAccount;
use App\Models\SocialPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 3 (webhook receiver, 2026-08-16): real signature verification
 * (Facebook X-Hub-Signature-256), real secret-token verification
 * (Telegram X-Telegram-Bot-Api-Secret-Token), and idempotent/replay-safe
 * event storage — mirroring the rigor of the existing MySQL publishing
 * reliability suite for this feature's own trust boundaries.
 */
class PlatformWebhookReceiverTest extends TestCase
{
    use RefreshDatabase;

    private function configureFacebook(string $appSecret = 'app-secret-xyz'): void
    {
        config()->set('social.providers.facebook.client_secret', $appSecret);
        config()->set('services.facebook.webhook_verify_token', 'verify-me-123');
    }

    private function facebookSignature(string $body, string $appSecret): string
    {
        return 'sha256='.hash_hmac('sha256', $body, $appSecret);
    }

    // --- Facebook: subscription handshake -----------------------------

    public function test_facebook_handshake_echoes_the_challenge_when_the_verify_token_matches(): void
    {
        $this->configureFacebook();

        $response = $this->get('/api/v1/webhooks/facebook?hub.mode=subscribe&hub.verify_token=verify-me-123&hub.challenge=echo-this-back');

        $response->assertOk();
        $this->assertSame('echo-this-back', $response->getContent());
    }

    public function test_facebook_handshake_rejects_a_wrong_verify_token(): void
    {
        $this->configureFacebook();

        $response = $this->get('/api/v1/webhooks/facebook?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=echo-this-back');

        $response->assertStatus(403);
    }

    public function test_facebook_handshake_fails_closed_when_no_verify_token_is_configured(): void
    {
        config()->set('services.facebook.webhook_verify_token', null);

        $response = $this->get('/api/v1/webhooks/facebook?hub.mode=subscribe&hub.verify_token=anything&hub.challenge=echo-this-back');

        $response->assertStatus(403);
    }

    // --- Facebook: event delivery ---------------------------------------

    public function test_facebook_delivery_rejects_a_missing_signature(): void
    {
        $this->configureFacebook();

        $response = $this->postJson('/api/v1/webhooks/facebook', ['object' => 'page', 'entry' => []]);

        $response->assertStatus(401);
        $this->assertDatabaseCount('platform_webhook_events', 0);
    }

    public function test_facebook_delivery_rejects_a_signature_computed_with_the_wrong_secret(): void
    {
        $this->configureFacebook();
        $body = json_encode(['object' => 'page', 'entry' => []]);

        $response = $this->call('POST', '/api/v1/webhooks/facebook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Hub-Signature-256' => $this->facebookSignature((string) $body, 'not-the-real-secret'),
        ], (string) $body);

        $response->assertStatus(401);
        $this->assertDatabaseCount('platform_webhook_events', 0);
    }

    public function test_facebook_delivery_with_a_valid_signature_stores_the_event_and_dispatches_processing(): void
    {
        Bus::fake();
        $this->configureFacebook();

        $body = json_encode([
            'object' => 'page',
            'entry' => [[
                'id' => 'fb-page-1',
                'time' => 1700000000,
                'changes' => [[
                    'field' => 'feed',
                    'value' => ['item' => 'reaction', 'post_id' => 'fb-page-1_post-1'],
                ]],
            ]],
        ]);

        $response = $this->call('POST', '/api/v1/webhooks/facebook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Hub-Signature-256' => $this->facebookSignature((string) $body, 'app-secret-xyz'),
        ], (string) $body);

        $response->assertOk();
        $this->assertDatabaseCount('platform_webhook_events', 1);
        $this->assertDatabaseHas('platform_webhook_events', ['provider' => 'facebook', 'type' => 'feed']);

        Bus::assertDispatched(ProcessPlatformWebhookEventJob::class);
    }

    public function test_facebook_redelivery_of_the_same_event_is_idempotent(): void
    {
        Bus::fake();
        $this->configureFacebook();

        $body = json_encode([
            'object' => 'page',
            'entry' => [[
                'id' => 'fb-page-1',
                'time' => 1700000000,
                'changes' => [['field' => 'feed', 'value' => ['item' => 'reaction', 'post_id' => 'fb-page-1_post-1']]],
            ]],
        ]);
        $signature = $this->facebookSignature((string) $body, 'app-secret-xyz');

        $this->call('POST', '/api/v1/webhooks/facebook', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X-Hub-Signature-256' => $signature,
        ], (string) $body)->assertOk();

        // A real Meta redelivery: byte-identical body, sent again.
        $this->call('POST', '/api/v1/webhooks/facebook', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X-Hub-Signature-256' => $signature,
        ], (string) $body)->assertOk();

        $this->assertDatabaseCount('platform_webhook_events', 1);
        // Only the first delivery's insert dispatched processing — the
        // duplicate row lookup is a no-op, not a second dispatch.
        Bus::assertDispatchedTimes(ProcessPlatformWebhookEventJob::class, 1);
    }

    // --- Telegram ---------------------------------------------------------

    private function makeTelegramAccount(): array
    {
        $user = User::factory()->create();

        $result = $this->asOrganizationOf($user, function () use ($user) {
            $account = SocialAccount::query()->create([
                'user_id' => $user->id,
                'provider' => 'telegram',
                'provider_account_id' => 'bot-1',
                'access_token' => 'bot-token',
                'webhook_secret' => 'a-real-secret-token',
                'status' => 'connected',
                'is_active' => true,
            ]);

            $page = SocialPage::query()->create([
                'social_account_id' => $account->id,
                'page_id' => '-100123456',
                'kind' => 'page',
                'name' => 'A Channel',
                'status' => 'valid',
            ]);

            return [$account, $page];
        });

        return [$result[0], $result[1], $user];
    }

    public function test_telegram_delivery_rejects_a_missing_secret_token(): void
    {
        [$account] = $this->makeTelegramAccount();

        $response = $this->postJson('/api/v1/webhooks/telegram/'.$account->id, ['update_id' => 1]);

        $response->assertStatus(401);
        $this->assertDatabaseCount('platform_webhook_events', 0);
    }

    public function test_telegram_delivery_rejects_a_wrong_secret_token(): void
    {
        [$account] = $this->makeTelegramAccount();

        $response = $this->postJson('/api/v1/webhooks/telegram/'.$account->id, ['update_id' => 1], [
            'X-Telegram-Bot-Api-Secret-Token' => 'wrong-secret',
        ]);

        $response->assertStatus(401);
        $this->assertDatabaseCount('platform_webhook_events', 0);
    }

    public function test_telegram_delivery_for_an_unknown_account_returns_404_without_a_db_write(): void
    {
        $response = $this->postJson('/api/v1/webhooks/telegram/999999', ['update_id' => 1], [
            'X-Telegram-Bot-Api-Secret-Token' => 'anything',
        ]);

        $response->assertStatus(404);
        $this->assertDatabaseCount('platform_webhook_events', 0);
    }

    public function test_telegram_delivery_with_a_valid_secret_stores_the_event_and_dispatches_processing(): void
    {
        Bus::fake();
        [$account] = $this->makeTelegramAccount();

        $response = $this->postJson('/api/v1/webhooks/telegram/'.$account->id, [
            'update_id' => 555,
            'channel_post' => ['chat' => ['id' => -100123456], 'text' => 'hi'],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'a-real-secret-token']);

        $response->assertOk();
        $this->assertDatabaseHas('platform_webhook_events', [
            'provider' => 'telegram',
            'provider_event_id' => $account->id.':555',
            'type' => 'channel_post',
        ]);
        Bus::assertDispatched(ProcessPlatformWebhookEventJob::class);
    }

    public function test_telegram_redelivery_of_the_same_update_id_is_idempotent(): void
    {
        Bus::fake();
        [$account] = $this->makeTelegramAccount();

        $payload = ['update_id' => 777, 'channel_post' => ['chat' => ['id' => -100123456], 'text' => 'hi']];
        $headers = ['X-Telegram-Bot-Api-Secret-Token' => 'a-real-secret-token'];

        $this->postJson('/api/v1/webhooks/telegram/'.$account->id, $payload, $headers)->assertOk();
        $this->postJson('/api/v1/webhooks/telegram/'.$account->id, $payload, $headers)->assertOk();

        $this->assertDatabaseCount('platform_webhook_events', 1);
        Bus::assertDispatchedTimes(ProcessPlatformWebhookEventJob::class, 1);
    }

    // --- Job processing -----------------------------------------------

    public function test_processing_a_bot_removal_event_marks_the_page_invalid(): void
    {
        [$account, $page, $user] = $this->makeTelegramAccount();

        $event = PlatformWebhookEvent::query()->create([
            'provider' => 'telegram',
            'provider_event_id' => $account->id.':1',
            'type' => 'my_chat_member',
            'payload' => [
                'my_chat_member' => [
                    'chat' => ['id' => '-100123456'],
                    'new_chat_member' => ['status' => 'kicked'],
                ],
            ],
            'social_account_id' => $account->id,
            'social_page_id' => $page->id,
            'organization_id' => $page->organization_id,
        ]);

        (new ProcessPlatformWebhookEventJob($event->id))->handle(app(PostMetricsSyncService::class));

        $this->asOrganizationOf($user, function () use ($page): void {
            $this->assertSame('invalid', $page->fresh()->status);
        });
        $this->assertNotNull($event->fresh()->processed_at);
    }

    public function test_processing_a_facebook_feed_event_triggers_a_real_metrics_resync(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'data' => [['name' => 'post_impressions', 'values' => [['value' => 42]]]],
            ], 200),
        ]);

        $user = User::factory()->create();

        [$page, $event] = $this->asOrganizationOf($user, function () use ($user) {
            $post = Post::query()->create(['user_id' => $user->id, 'title' => 'A post', 'status' => 'published']);
            $account = SocialAccount::query()->create([
                'user_id' => $user->id,
                'provider' => 'facebook',
                'provider_account_id' => 'fb-acc-1',
                'access_token' => 'fb-token',
                'status' => 'connected',
                'is_active' => true,
            ]);
            $page = SocialPage::query()->create([
                'social_account_id' => $account->id,
                'page_id' => 'fb-page-1',
                'name' => 'A Page',
                'status' => 'valid',
            ]);
            PostPublicationAttempt::query()->create([
                'post_id' => $post->id,
                'social_account_id' => $account->id,
                'social_page_id' => $page->id,
                'idempotency_key' => 'key-1',
                'attempt_number' => 1,
                'status' => 'success',
                'provider_response' => json_encode(['provider_post_id' => 'fb-page-1_post-1']),
                'processed_at' => now(),
            ]);

            $event = PlatformWebhookEvent::query()->create([
                'provider' => 'facebook',
                'provider_event_id' => 'evt-1',
                'type' => 'feed',
                'payload' => ['value' => ['post_id' => 'fb-page-1_post-1']],
                'social_page_id' => $page->id,
                'organization_id' => $page->organization_id,
            ]);

            return [$page, $event];
        });

        (new ProcessPlatformWebhookEventJob($event->id))->handle(app(PostMetricsSyncService::class));

        $this->asOrganizationOf($user, function () use ($page): void {
            $this->assertDatabaseHas('post_metrics', ['social_page_id' => $page->id, 'impressions' => 42]);
        });
    }
}
