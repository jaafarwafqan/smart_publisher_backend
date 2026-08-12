<?php

namespace Tests\Feature;

use App\Models\OAuthProviderSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 2026-08-12: Android/iOS mobile sign-in via flutter_facebook_auth — the
 * app hands this endpoint a real Facebook access token directly (no ?code=
 * to exchange, unlike authorize()/callback() in SocialAccountOAuthTest).
 * Everything here exercises the server-side /debug_token re-verification
 * that makes this safe to trust a client-asserted token for at all.
 */
class SocialAccountNativeConnectTest extends TestCase
{
    use RefreshDatabase;

    private function configureFacebook(): void
    {
        config()->set('social.providers.facebook.client_id', 'app-id-123');
        config()->set('social.providers.facebook.client_secret', 'app-secret-xyz');
    }

    public function test_native_connect_links_a_facebook_account_after_real_debug_token_verification(): void
    {
        $this->configureFacebook();

        Http::fake([
            'graph.facebook.com/debug_token*' => Http::response([
                'data' => [
                    'app_id' => 'app-id-123',
                    'is_valid' => true,
                    'expires_at' => 0,
                    'scopes' => ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts'],
                ],
            ], 200),
            'graph.facebook.com/me*' => Http::response([
                'id' => 'fb-native-user-1',
                'name' => 'Native Login User',
            ], 200),
        ]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/users/'.$user->id.'/social-accounts/native-connect', [
            'provider' => 'facebook',
            'access_token' => 'sdk-issued-token',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.provider', 'facebook')
            ->assertJsonPath('data.provider_account_id', 'fb-native-user-1')
            ->assertJsonPath('data.discovery_mode', 'auto');

        // The /debug_token call must actually carry the token being
        // verified and inspect it with an app (not user) access token —
        // this is the whole point of the endpoint, not incidental.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'debug_token')
            && $request['input_token'] === 'sdk-issued-token'
            && $request['access_token'] === 'app-id-123|app-secret-xyz');
    }

    public function test_native_connect_rejects_a_token_facebook_reports_as_invalid(): void
    {
        $this->configureFacebook();

        Http::fake([
            'graph.facebook.com/debug_token*' => Http::response([
                'data' => ['is_valid' => false, 'error' => ['message' => 'Token has expired.']],
            ], 200),
        ]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/users/'.$user->id.'/social-accounts/native-connect', [
            'provider' => 'facebook',
            'access_token' => 'expired-token',
        ]);

        $response->assertStatus(422)->assertJsonPath('errors.code', 'native_token_invalid');
        $this->assertDatabaseCount('social_accounts', 0);
    }

    /**
     * The core trust boundary this endpoint exists to enforce: a token that
     * is otherwise genuinely valid but was minted for a *different*
     * Facebook app must never be accepted — never trust a client-asserted
     * token at face value just because Meta itself says it's "valid".
     */
    public function test_native_connect_rejects_a_valid_token_issued_for_a_different_app(): void
    {
        $this->configureFacebook();

        Http::fake([
            'graph.facebook.com/debug_token*' => Http::response([
                'data' => ['app_id' => 'someone-elses-app', 'is_valid' => true, 'scopes' => []],
            ], 200),
        ]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/users/'.$user->id.'/social-accounts/native-connect', [
            'provider' => 'facebook',
            'access_token' => 'foreign-app-token',
        ]);

        $response->assertStatus(422)->assertJsonPath('errors.code', 'native_token_invalid');
        $this->assertDatabaseCount('social_accounts', 0);
    }

    /**
     * Only Facebook has an official mobile SDK wired up (2026-08-12 scope)
     * — every other provider string, including telegram/whatsapp/instagram,
     * must be rejected by validation before ever reaching the OAuth
     * manager, the same way an unsupported enum value would be.
     */
    public function test_native_connect_rejects_any_provider_other_than_facebook(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/users/'.$user->id.'/social-accounts/native-connect', [
            'provider' => 'whatsapp',
            'access_token' => 'some-token',
        ]);

        $response->assertStatus(422);
    }

    public function test_native_connect_rejects_a_disabled_provider(): void
    {
        $this->configureFacebook();
        OAuthProviderSetting::query()->create(['provider' => 'facebook', 'is_enabled' => false]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/users/'.$user->id.'/social-accounts/native-connect', [
            'provider' => 'facebook',
            'access_token' => 'some-token',
        ]);

        $response->assertStatus(422)->assertJsonPath('errors.code', 'provider_disabled');
    }

    /**
     * Re-connecting (a re-prompted, real Meta-forced re-auth) via native
     * sign-in must update the same existing account row, not create a
     * duplicate — same (provider, provider_account_id) upsert key the web
     * callback() flow already relies on.
     */
    public function test_native_connect_updates_the_existing_account_on_reconnect(): void
    {
        $this->configureFacebook();

        Http::fake([
            'graph.facebook.com/debug_token*' => Http::response([
                'data' => ['app_id' => 'app-id-123', 'is_valid' => true, 'expires_at' => 0, 'scopes' => ['pages_show_list']],
            ], 200),
            'graph.facebook.com/me*' => Http::response(['id' => 'fb-native-user-2', 'name' => 'Reconnecting User'], 200),
        ]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $first = $this->postJson('/api/v1/users/'.$user->id.'/social-accounts/native-connect', [
            'provider' => 'facebook',
            'access_token' => 'first-token',
        ])->assertOk();

        $second = $this->postJson('/api/v1/users/'.$user->id.'/social-accounts/native-connect', [
            'provider' => 'facebook',
            'access_token' => 'second-token-after-reauth',
        ])->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('social_accounts', 1);
    }
}
