<?php

namespace Tests\Feature;

use App\Infrastructure\ExternalServices\Publishing\SocialPageSyncService;
use App\Infrastructure\ExternalServices\SocialOAuth\SocialOAuthManager;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_pages_returns_nothing_when_no_business_id_is_set_yet(): void
    {
        $pages = app(SocialOAuthManager::class)->provider('whatsapp')->listPages('token', ['metadata' => []]);

        $this->assertSame([], $pages);
    }

    public function test_list_pages_returns_real_phone_numbers_once_a_business_id_is_provided(): void
    {
        Http::fake([
            'graph.facebook.com/biz-1/owned_whatsapp_business_accounts*' => Http::response([
                'data' => [['id' => 'waba-1', 'name' => 'My WABA']],
            ], 200),
            'graph.facebook.com/waba-1/phone_numbers*' => Http::response([
                'data' => [
                    ['id' => 'phone-1', 'display_phone_number' => '+1 555 0100', 'verified_name' => 'Support Line', 'quality_rating' => 'GREEN'],
                ],
            ], 200),
        ]);

        $pages = app(SocialOAuthManager::class)->provider('whatsapp')->listPages(
            'token',
            ['metadata' => ['business_id' => 'biz-1']]
        );

        $this->assertCount(1, $pages);
        $this->assertSame('whatsapp_number', $pages[0]['kind']);
        $this->assertSame('phone-1', $pages[0]['page_id']);
        $this->assertSame('Support Line', $pages[0]['name']);
        $this->assertTrue($pages[0]['can_publish']);
        $this->assertSame('waba-1', $pages[0]['metadata']['waba_id']);
    }

    public function test_syncing_a_whatsapp_account_creates_social_page_rows_for_real_phone_numbers(): void
    {
        Http::fake([
            'graph.facebook.com/biz-2/owned_whatsapp_business_accounts*' => Http::response([
                'data' => [['id' => 'waba-2', 'name' => 'Biz WABA']],
            ], 200),
            'graph.facebook.com/waba-2/phone_numbers*' => Http::response([
                'data' => [
                    ['id' => 'phone-2', 'display_phone_number' => '+1 555 0200', 'verified_name' => 'Sales Line'],
                ],
            ], 200),
        ]);

        $user = User::factory()->create();
        $account = $this->asOrganizationOf($user, fn () => SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'whatsapp',
            'discovery_mode' => 'auto',
            'provider_account_id' => 'wa-user-2',
            'access_token' => 'wa-token',
            'status' => 'connected',
            'is_active' => true,
            'metadata' => ['business_id' => 'biz-2'],
        ]));

        $this->asOrganizationOf($user, fn () => app(SocialPageSyncService::class)->syncAuto($account));

        $this->assertDatabaseHas('social_pages', [
            'social_account_id' => $account->id,
            'page_id' => 'phone-2',
            'kind' => 'whatsapp_number',
            'name' => 'Sales Line',
        ]);
    }

    public function test_whatsapp_provider_delegates_oauth_to_the_real_facebook_flow(): void
    {
        config()->set('social.providers.whatsapp.authorize_url', 'https://www.facebook.com/v20.0/dialog/oauth');
        config()->set('social.providers.whatsapp.client_id', 'wa-client-id');

        $url = app(SocialOAuthManager::class)->provider('whatsapp')->buildAuthorizeUrl([
            'provider_config' => ['authorize_url' => 'https://www.facebook.com/v20.0/dialog/oauth', 'client_id' => 'wa-client-id'],
            'redirect_uri' => 'smartpublisher://oauth/callback',
            'state' => 'xyz',
        ]);

        $this->assertStringStartsWith('https://www.facebook.com/v20.0/dialog/oauth?', $url);
        $this->assertStringContainsString('client_id=wa-client-id', $url);
    }
}
