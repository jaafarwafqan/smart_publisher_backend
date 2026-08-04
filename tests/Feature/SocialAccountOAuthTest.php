<?php

namespace Tests\Feature;

use App\Infrastructure\ExternalServices\SocialOAuth\SocialOAuthManager;
use App\Models\OAuthProviderSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SocialAccountOAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_can_request_oauth_authorization_url(): void
    {
        $user = User::factory()->create();
        Permission::query()->firstOrCreate(['name' => 'social-accounts.oauth.authorize', 'guard_name' => 'sanctum']);
        $user->givePermissionTo('social-accounts.oauth.authorize');

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/users/'.$user->id.'/social-accounts/authorize', [
            'provider' => 'facebook',
            'redirect_uri' => 'smartpublisher://oauth/callback',
            'scopes' => ['pages_manage_posts'],
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'message',
                    'provider',
                    'state',
                    'state_expires_at',
                    'authorize_url',
                ],
            ]);
    }

    /**
     * CTO audit 4.4: beginOAuthAuthorization() previously accepted any
     * syntactically-valid URL as redirect_uri, which would let a caller
     * hijack the OAuth authorization code to a domain they control. Now
     * only the exact allowlisted value(s) in config('social.allowed_redirect_uris')
     * are accepted.
     */
    public function test_oauth_authorization_rejects_a_redirect_uri_outside_the_allowlist(): void
    {
        $user = User::factory()->create();
        Permission::query()->firstOrCreate(['name' => 'social-accounts.oauth.authorize', 'guard_name' => 'sanctum']);
        $user->givePermissionTo('social-accounts.oauth.authorize');

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/users/'.$user->id.'/social-accounts/authorize', [
            'provider' => 'facebook',
            'redirect_uri' => 'https://attacker.example.com/callback',
            'scopes' => ['pages_manage_posts'],
        ]);

        $response->assertStatus(422);
    }

    public function test_facebook_oauth_callback_links_account_using_http_fake(): void
    {
        Http::fake([
            'graph.facebook.com/*/oauth/access_token' => Http::response([
                'access_token' => 'fb-access-token',
                'expires_in' => 3600,
                'token_type' => 'bearer',
            ], 200),
            'graph.facebook.com/me*' => Http::response([
                'id' => 'fb-user-1',
                'name' => 'Facebook Page Admin',
            ], 200),
        ]);

        config()->set('social.providers.facebook.client_id', 'client-id');
        config()->set('social.providers.facebook.client_secret', 'client-secret');
        config()->set('social.providers.facebook.token_url', 'https://graph.facebook.com/v20.0/oauth/access_token');

        $user = User::factory()->create();
        Permission::query()->firstOrCreate(['name' => 'social-accounts.oauth.authorize', 'guard_name' => 'sanctum']);
        Permission::query()->firstOrCreate(['name' => 'social-accounts.oauth.callback', 'guard_name' => 'sanctum']);
        $user->givePermissionTo(['social-accounts.oauth.authorize', 'social-accounts.oauth.callback']);

        Sanctum::actingAs($user);

        $authorizeResponse = $this->postJson('/api/v1/users/'.$user->id.'/social-accounts/authorize', [
            'provider' => 'facebook',
            'redirect_uri' => 'smartpublisher://oauth/callback',
            'scopes' => ['pages_manage_posts'],
        ]);

        $state = $authorizeResponse->json('data.state');

        $callbackResponse = $this->postJson('/api/v1/users/'.$user->id.'/social-accounts/callback', [
            'provider' => 'facebook',
            'code' => 'facebook-auth-code',
            'state' => $state,
            'scopes' => ['pages_manage_posts'],
        ]);

        $callbackResponse->assertOk()
            ->assertJsonPath('data.provider', 'facebook')
            ->assertJsonPath('data.provider_account_id', 'fb-user-1')
            // Regression: a freshly-connected Facebook account must default to
            // auto-discovery (real /me/accounts page listing), matching the
            // migration's own backfill rule for pre-existing rows — the
            // callback() updateOrCreate previously never set this at all.
            ->assertJsonPath('data.discovery_mode', 'auto');
    }

    public function test_whatsapp_oauth_callback_also_defaults_to_auto_discovery(): void
    {
        config()->set('social.providers.whatsapp.client_id', 'wa-client-id');
        config()->set('social.providers.whatsapp.client_secret', 'wa-client-secret');
        config()->set('social.providers.whatsapp.token_url', 'https://graph.facebook.com/v20.0/oauth/access_token');

        Http::fake([
            'graph.facebook.com/*/oauth/access_token' => Http::response([
                'access_token' => 'wa-access-token',
                'expires_in' => 3600,
                'token_type' => 'bearer',
            ], 200),
            'graph.facebook.com/me*' => Http::response([
                'id' => 'wa-user-1',
                'name' => 'WhatsApp Business Admin',
            ], 200),
        ]);

        $user = User::factory()->create();
        Permission::query()->firstOrCreate(['name' => 'social-accounts.oauth.authorize', 'guard_name' => 'sanctum']);
        Permission::query()->firstOrCreate(['name' => 'social-accounts.oauth.callback', 'guard_name' => 'sanctum']);
        $user->givePermissionTo(['social-accounts.oauth.authorize', 'social-accounts.oauth.callback']);

        Sanctum::actingAs($user);

        $authorizeResponse = $this->postJson('/api/v1/users/'.$user->id.'/social-accounts/authorize', [
            'provider' => 'whatsapp',
            'redirect_uri' => 'smartpublisher://oauth/callback',
            'scopes' => ['whatsapp_business_management'],
        ]);

        $state = $authorizeResponse->json('data.state');

        $callbackResponse = $this->postJson('/api/v1/users/'.$user->id.'/social-accounts/callback', [
            'provider' => 'whatsapp',
            'code' => 'whatsapp-auth-code',
            'state' => $state,
            'scopes' => ['whatsapp_business_management'],
        ]);

        $callbackResponse->assertOk()
            ->assertJsonPath('data.provider', 'whatsapp')
            ->assertJsonPath('data.discovery_mode', 'auto');
    }

    public function test_mock_oauth_provider_is_explicitly_unavailable_in_production(): void
    {
        $originalEnvironment = app()->environment();
        app()->instance('env', 'production');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('instagram integration is not available in production');

            app(SocialOAuthManager::class)->provider('instagram');
        } finally {
            app()->instance('env', $originalEnvironment);
        }
    }

    public function test_whatsapp_is_not_enabled_for_the_telegram_and_facebook_closed_beta(): void
    {
        $originalEnvironment = app()->environment();
        app()->instance('env', 'production');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('whatsapp integration is not enabled for the current closed beta release');

            app(SocialOAuthManager::class)->provider('whatsapp');
        } finally {
            app()->instance('env', $originalEnvironment);
        }
    }

    /**
     * The is_enabled toggle in System Settings used to be purely cosmetic —
     * disabling a provider there never actually stopped its OAuth endpoints
     * from working. Confirms the real HTTP-level 422, not just the manager
     * throwing.
     */
    public function test_oauth_authorization_rejects_a_disabled_provider(): void
    {
        OAuthProviderSetting::query()->create(['provider' => 'facebook', 'is_enabled' => false]);

        $user = User::factory()->create();
        Permission::query()->firstOrCreate(['name' => 'social-accounts.oauth.authorize', 'guard_name' => 'sanctum']);
        $user->givePermissionTo('social-accounts.oauth.authorize');

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/users/'.$user->id.'/social-accounts/authorize', [
            'provider' => 'facebook',
            'redirect_uri' => 'smartpublisher://oauth/callback',
            'scopes' => ['pages_manage_posts'],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.code', 'provider_disabled');
    }

    public function test_disabled_provider_throws_from_the_manager_across_every_entry_point(): void
    {
        OAuthProviderSetting::query()->create(['provider' => 'telegram', 'is_enabled' => false]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('telegram integration is currently disabled by an administrator');

        app(SocialOAuthManager::class)->provider('telegram');
    }
}
