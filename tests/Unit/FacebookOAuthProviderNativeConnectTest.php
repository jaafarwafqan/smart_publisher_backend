<?php

namespace Tests\Unit;

use App\Infrastructure\ExternalServices\SocialOAuth\FacebookOAuthProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * 2026-08-12: FacebookOAuthProvider::verifyNativeToken() — the server-side
 * half of the Android/iOS flutter_facebook_auth flow. Exercises the actual
 * Graph API shape of /debug_token responses directly, separate from the
 * HTTP-layer trust-boundary tests in SocialAccountNativeConnectTest.
 */
class FacebookOAuthProviderNativeConnectTest extends TestCase
{
    private function providerConfig(): array
    {
        return ['client_id' => 'app-id-123', 'client_secret' => 'app-secret-xyz'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.facebook.graph_url', 'https://graph.facebook.com');
    }

    public function test_returns_the_real_granted_scopes_and_expiry_from_debug_token(): void
    {
        Http::fake([
            'graph.facebook.com/debug_token*' => Http::response([
                'data' => [
                    'app_id' => 'app-id-123',
                    'is_valid' => true,
                    'expires_at' => 1999999999,
                    'scopes' => ['pages_show_list', 'pages_manage_posts'],
                ],
            ], 200),
            'graph.facebook.com/me*' => Http::response(['id' => 'fb-1', 'name' => 'Jaafar'], 200),
        ]);

        $provider = new FacebookOAuthProvider(Http::getFacadeRoot());
        $result = $provider->verifyNativeToken('a-real-sdk-token', ['provider_config' => $this->providerConfig()]);

        $this->assertSame('fb-1', $result['provider_account_id']);
        $this->assertSame('Jaafar', $result['account_name']);
        $this->assertSame('a-real-sdk-token', $result['access_token']);
        $this->assertSame('', $result['refresh_token']);
        $this->assertSame(['pages_show_list', 'pages_manage_posts'], $result['scopes']);
        $this->assertSame('native_sdk', $result['metadata']['auth_method']);
        $this->assertSame(1999999999, $result['expires_at']->getTimestamp());
    }

    /**
     * expires_at=0 is Graph API's real convention for "never expires" (a
     * long-lived Page token, or App-Review-approved indefinite tokens) —
     * must not be read as "already expired".
     */
    public function test_a_zero_expires_at_falls_back_to_a_sensible_default_not_immediate_expiry(): void
    {
        Http::fake([
            'graph.facebook.com/debug_token*' => Http::response([
                'data' => ['app_id' => 'app-id-123', 'is_valid' => true, 'expires_at' => 0, 'scopes' => []],
            ], 200),
            'graph.facebook.com/me*' => Http::response(['id' => 'fb-2', 'name' => 'No Expiry'], 200),
        ]);

        $provider = new FacebookOAuthProvider(Http::getFacadeRoot());
        $result = $provider->verifyNativeToken('never-expires-token', ['provider_config' => $this->providerConfig()]);

        $this->assertTrue($result['expires_at']->isFuture());
    }

    public function test_throws_when_facebook_reports_the_token_invalid(): void
    {
        Http::fake([
            'graph.facebook.com/debug_token*' => Http::response([
                'data' => ['is_valid' => false, 'error' => ['message' => 'Token has been expired.']],
            ], 200),
        ]);

        $provider = new FacebookOAuthProvider(Http::getFacadeRoot());

        $this->expectException(RuntimeException::class);
        $provider->verifyNativeToken('stale-token', ['provider_config' => $this->providerConfig()]);
    }

    public function test_throws_when_the_token_was_minted_for_a_different_app(): void
    {
        Http::fake([
            'graph.facebook.com/debug_token*' => Http::response([
                'data' => ['app_id' => 'not-our-app', 'is_valid' => true, 'scopes' => []],
            ], 200),
        ]);

        $provider = new FacebookOAuthProvider(Http::getFacadeRoot());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('was not issued for this application');
        $provider->verifyNativeToken('foreign-token', ['provider_config' => $this->providerConfig()]);
    }

    public function test_throws_when_client_credentials_are_not_configured(): void
    {
        $provider = new FacebookOAuthProvider(Http::getFacadeRoot());

        $this->expectException(RuntimeException::class);
        $provider->verifyNativeToken('any-token', ['provider_config' => ['client_id' => '', 'client_secret' => '']]);
    }
}
