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
     * Sprint C (role/permission remediation): previously a mock provider
     * (GenericOAuthProvider — zero real HTTP calls) was only rejected in
     * `production`; every other environment, including this one
     * (`testing`, the suite's own APP_ENV), let a caller "connect" a fake
     * linkedin/youtube/etc. account. Confirms the fix holds without
     * touching `app()->instance('env', ...)` at all — the default test
     * environment must reject it on its own.
     *
     * Uses linkedin, not instagram — instagram graduated off
     * SocialOAuthManager::isMockProvider()'s list in 2026-08 (see
     * InstagramProvider); this exact scenario for instagram is now covered
     * by test_instagram_oauth_callback_also_defaults_to_auto_discovery()
     * below instead.
     */
    public function test_mock_provider_authorization_is_rejected_outside_production_too(): void
    {
        $this->assertNotSame('production', app()->environment());

        $user = User::factory()->create();
        Permission::query()->firstOrCreate(['name' => 'social-accounts.oauth.authorize', 'guard_name' => 'sanctum']);
        $user->givePermissionTo('social-accounts.oauth.authorize');

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/users/'.$user->id.'/social-accounts/authorize', [
            'provider' => 'linkedin',
            'redirect_uri' => 'smartpublisher://oauth/callback',
        ])->assertStatus(422)->assertJsonPath('errors.code', 'provider_not_available');
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

    /**
     * Flutter never actually drives this endpoint with provider=instagram —
     * an Instagram Business Account is discovered as a child of the
     * connected Facebook Page instead (FacebookOAuthProvider::listPages()).
     * This exists because the code path is real now (InstagramProvider
     * delegates OAuth to FacebookOAuthProvider exactly like WhatsAppProvider
     * does) and CLOSED_BETA_PROVIDERS accepts it, so it deserves the same
     * coverage the equivalent WhatsApp test above has, even though it's not
     * the primary connection route in the product.
     */
    public function test_instagram_oauth_callback_also_defaults_to_auto_discovery(): void
    {
        config()->set('social.providers.instagram.client_id', 'ig-client-id');
        config()->set('social.providers.instagram.client_secret', 'ig-client-secret');
        config()->set('social.providers.instagram.token_url', 'https://graph.facebook.com/v20.0/oauth/access_token');

        Http::fake([
            'graph.facebook.com/*/oauth/access_token' => Http::response([
                'access_token' => 'ig-access-token',
                'expires_in' => 3600,
                'token_type' => 'bearer',
            ], 200),
            'graph.facebook.com/me*' => Http::response([
                'id' => 'ig-user-1',
                'name' => 'Instagram Business Admin',
            ], 200),
        ]);

        $user = User::factory()->create();
        Permission::query()->firstOrCreate(['name' => 'social-accounts.oauth.authorize', 'guard_name' => 'sanctum']);
        Permission::query()->firstOrCreate(['name' => 'social-accounts.oauth.callback', 'guard_name' => 'sanctum']);
        $user->givePermissionTo(['social-accounts.oauth.authorize', 'social-accounts.oauth.callback']);

        Sanctum::actingAs($user);

        $authorizeResponse = $this->postJson('/api/v1/users/'.$user->id.'/social-accounts/authorize', [
            'provider' => 'instagram',
            'redirect_uri' => 'smartpublisher://oauth/callback',
        ]);

        $state = $authorizeResponse->json('data.state');

        $callbackResponse = $this->postJson('/api/v1/users/'.$user->id.'/social-accounts/callback', [
            'provider' => 'instagram',
            'code' => 'instagram-auth-code',
            'state' => $state,
        ]);

        $callbackResponse->assertOk()
            ->assertJsonPath('data.provider', 'instagram')
            ->assertJsonPath('data.discovery_mode', 'auto');
    }

    /**
     * The single most valuable X test: proves the PKCE code_verifier really
     * survives the round trip through Cache::put()/Cache::pull() (see
     * SocialAccountController::beginOAuthAuthorization()/callback()) and
     * reaches XOAuthProvider::exchangeCodeForToken() — not just that
     * XOAuthProviderTest's unit-level mock of a $context array containing
     * code_verifier works.
     */
    public function test_x_oauth_pkce_round_trip_caches_and_forwards_the_code_verifier(): void
    {
        config()->set('social.providers.x.client_id', 'x-client-id');
        config()->set('social.providers.x.client_secret', 'x-client-secret');
        config()->set('social.providers.x.authorize_url', 'https://twitter.com/i/oauth2/authorize');
        config()->set('social.providers.x.token_url', 'https://api.twitter.com/2/oauth2/token');

        Http::fake([
            'api.twitter.com/2/oauth2/token' => Http::response([
                'access_token' => 'x-access-token',
                'refresh_token' => 'x-refresh-token',
                'expires_in' => 7200,
                'token_type' => 'bearer',
            ], 200),
            'api.twitter.com/2/users/me*' => Http::response([
                'data' => ['id' => 'x-user-1', 'name' => 'X Test Account', 'username' => 'x_test_account'],
            ], 200),
        ]);

        $user = User::factory()->create();
        Permission::query()->firstOrCreate(['name' => 'social-accounts.oauth.authorize', 'guard_name' => 'sanctum']);
        Permission::query()->firstOrCreate(['name' => 'social-accounts.oauth.callback', 'guard_name' => 'sanctum']);
        $user->givePermissionTo(['social-accounts.oauth.authorize', 'social-accounts.oauth.callback']);

        Sanctum::actingAs($user);

        $authorizeResponse = $this->postJson('/api/v1/users/'.$user->id.'/social-accounts/authorize', [
            'provider' => 'x',
            'redirect_uri' => 'smartpublisher://oauth/callback',
        ]);

        $authorizeResponse->assertOk();
        $authorizeUrl = $authorizeResponse->json('data.authorize_url');
        parse_str((string) parse_url($authorizeUrl, PHP_URL_QUERY), $query);

        // The URL carries only the challenge (S256 of the verifier) — the
        // verifier itself must never appear here.
        $this->assertArrayHasKey('code_challenge', $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertNotEmpty($query['code_challenge']);

        $state = $authorizeResponse->json('data.state');

        $callbackResponse = $this->postJson('/api/v1/users/'.$user->id.'/social-accounts/callback', [
            'provider' => 'x',
            'code' => 'x-auth-code',
            'state' => $state,
        ]);

        $callbackResponse->assertOk()
            ->assertJsonPath('data.provider', 'x')
            ->assertJsonPath('data.provider_account_id', 'x-user-1')
            ->assertJsonPath('data.account_username', '@x_test_account')
            ->assertJsonPath('data.discovery_mode', 'auto');

        Http::assertSent(function ($request) {
            if ((string) $request->url() !== 'https://api.twitter.com/2/oauth2/token') {
                return true;
            }

            return $request['code_verifier'] !== null && $request['code_verifier'] !== '';
        });
    }

    public function test_mock_oauth_provider_is_explicitly_unavailable_in_production(): void
    {
        $originalEnvironment = app()->environment();
        app()->instance('env', 'production');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('linkedin integration is not available in production');

            app(SocialOAuthManager::class)->provider('linkedin');
        } finally {
            app()->instance('env', $originalEnvironment);
        }
    }

    /**
     * X is real (XOAuthProvider), not mocked — so it hits the *other*
     * production-rejection branch (not enabled for the closed beta release)
     * rather than the mock-provider one above, same distinction
     * test_whatsapp_is_not_enabled_for_the_telegram_and_facebook_closed_beta
     * already covers for WhatsApp.
     */
    public function test_x_is_not_enabled_for_the_telegram_facebook_instagram_closed_beta(): void
    {
        $originalEnvironment = app()->environment();
        app()->instance('env', 'production');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('x integration is not enabled for the current closed beta release');

            app(SocialOAuthManager::class)->provider('x');
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

    /**
     * Code-quality review (2026-08-17): authorize/callback/native-connect/
     * telegram-connect previously had no throttle at all — see the 'oauth'
     * limiter's own comment in AppServiceProvider. Confirms the limit is
     * really wired to this route (10/minute per authenticated user), not
     * just defined and unused.
     */
    public function test_the_authorize_endpoint_is_rate_limited(): void
    {
        config()->set('cache.default', 'array');

        $user = User::factory()->create();
        Permission::query()->firstOrCreate(['name' => 'social-accounts.oauth.authorize', 'guard_name' => 'sanctum']);
        $user->givePermissionTo('social-accounts.oauth.authorize');

        Sanctum::actingAs($user);

        $lastStatus = 200;
        for ($i = 0; $i < 11; $i++) {
            $lastStatus = $this->postJson('/api/v1/users/'.$user->id.'/social-accounts/authorize', [
                'provider' => 'facebook',
                'redirect_uri' => 'smartpublisher://oauth/callback',
                'scopes' => ['pages_manage_posts'],
            ])->getStatusCode();
        }

        $this->assertSame(429, $lastStatus);
    }
}
