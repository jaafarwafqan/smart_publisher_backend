<?php

namespace Tests\Unit;

use App\Exceptions\Publishing\ProviderPublishException;
use App\Infrastructure\ExternalServices\SocialOAuth\XOAuthProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Unit-level coverage of XOAuthProvider in isolation, given a pre-built
 * $context — the code_verifier/code_challenge cache plumbing itself (the
 * part that can actually break silently) is covered end to end instead by
 * SocialAccountOAuthTest::test_x_oauth_pkce_round_trip_caches_and_forwards_the_code_verifier().
 */
class XOAuthProviderTest extends TestCase
{
    private XOAuthProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new XOAuthProvider(Http::getFacadeRoot());
    }

    public function test_build_authorize_url_includes_the_pkce_challenge(): void
    {
        $url = $this->provider->buildAuthorizeUrl([
            'provider_config' => [
                'authorize_url' => 'https://twitter.com/i/oauth2/authorize',
                'client_id' => 'client-1',
                'default_scopes' => ['tweet.read', 'tweet.write'],
            ],
            'redirect_uri' => 'smartpublisher://oauth/callback',
            'state' => 'state-1',
            'code_challenge' => 'challenge-1',
        ]);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('challenge-1', $query['code_challenge']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('client-1', $query['client_id']);
        $this->assertSame('tweet.read tweet.write', $query['scope']);
    }

    public function test_exchange_code_for_token_sends_the_verifier_with_basic_auth(): void
    {
        Http::fake([
            'api.twitter.com/2/oauth2/token' => Http::response([
                'access_token' => 'x-token',
                'refresh_token' => 'x-refresh',
                'expires_in' => 7200,
                'token_type' => 'bearer',
            ], 200),
            'api.twitter.com/2/users/me*' => Http::response([
                'data' => ['id' => 'x-1', 'name' => 'Test User', 'username' => 'testuser'],
            ], 200),
        ]);

        $result = $this->provider->exchangeCodeForToken('auth-code-1', [
            'provider_config' => [
                'client_id' => 'client-1',
                'client_secret' => 'secret-1',
                'token_url' => 'https://api.twitter.com/2/oauth2/token',
            ],
            'redirect_uri' => 'smartpublisher://oauth/callback',
            'code_verifier' => 'verifier-1',
        ]);

        $this->assertSame('x-1', $result['provider_account_id']);
        $this->assertSame('Test User', $result['account_name']);
        $this->assertSame('@testuser', $result['account_username']);
        $this->assertSame('x-token', $result['access_token']);
        $this->assertSame('x-refresh', $result['refresh_token']);

        Http::assertSent(function ($request) {
            if ((string) $request->url() !== 'https://api.twitter.com/2/oauth2/token') {
                return true;
            }

            $authHeader = $request->header('Authorization')[0] ?? '';

            return $request['code_verifier'] === 'verifier-1'
                && $request['code'] === 'auth-code-1'
                && str_starts_with($authHeader, 'Basic ');
        });
    }

    public function test_exchange_code_for_token_requires_a_code_verifier(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('code_verifier');

        $this->provider->exchangeCodeForToken('auth-code-1', [
            'provider_config' => [
                'client_id' => 'client-1',
                'client_secret' => 'secret-1',
                'token_url' => 'https://api.twitter.com/2/oauth2/token',
            ],
            'redirect_uri' => 'smartpublisher://oauth/callback',
        ]);
    }

    public function test_publish_post_sends_the_tweet_text(): void
    {
        Http::fake([
            'api.twitter.com/2/tweets' => Http::response(['data' => ['id' => 'tweet-1', 'text' => 'Hello X']], 201),
        ]);

        $result = $this->provider->publishPost('x-access-token', [
            'content' => 'Hello X',
            'attachments' => [],
        ]);

        $this->assertSame('tweet-1', $result['provider_post_id']);
        $this->assertSame('published', $result['status']);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.twitter.com/2/tweets'
            && $request['text'] === 'Hello X'
            && $request->hasHeader('Authorization', 'Bearer x-access-token'));
    }

    public function test_publish_post_rejects_attachments(): void
    {
        $this->expectException(ProviderPublishException::class);
        $this->expectExceptionMessage('text-only');

        $this->provider->publishPost('x-access-token', [
            'content' => 'A tweet with a photo',
            'attachments' => [['disk' => 'public', 'path' => 'media/photo.jpg', 'type' => 'image']],
        ]);
    }

    public function test_publish_post_converts_a_rate_limit_reset_timestamp_into_seconds(): void
    {
        $resetAt = now()->addSeconds(42)->timestamp;

        Http::fake([
            'api.twitter.com/2/tweets' => Http::response(
                ['title' => 'Too Many Requests'],
                429,
                ['x-rate-limit-reset' => (string) $resetAt],
            ),
        ]);

        try {
            $this->provider->publishPost('x-access-token', ['content' => 'Rate limited', 'attachments' => []]);
            $this->fail('Expected a ProviderPublishException.');
        } catch (ProviderPublishException $e) {
            $this->assertSame(429, $e->httpStatus);
            $this->assertNotNull($e->retryAfterSeconds);
            $this->assertLessThanOrEqual(42, $e->retryAfterSeconds);
            $this->assertGreaterThan(0, $e->retryAfterSeconds);
        }
    }

    public function test_list_pages_returns_a_single_synthetic_profile_target(): void
    {
        Http::fake([
            'api.twitter.com/2/users/me*' => Http::response([
                'data' => ['id' => 'x-1', 'name' => 'Test User', 'username' => 'testuser'],
            ], 200),
        ]);

        $pages = $this->provider->listPages('x-access-token', []);

        $this->assertCount(1, $pages);
        $this->assertSame('profile', $pages[0]['kind']);
        $this->assertSame('x-1', $pages[0]['page_id']);
        $this->assertTrue($pages[0]['can_publish']);
    }

    public function test_test_connection_verifies_app_credentials_via_client_credentials_grant(): void
    {
        Http::fake([
            'api.twitter.com/2/oauth2/token' => Http::response(['access_token' => 'app-token'], 200),
        ]);

        $result = $this->provider->testConnection([
            'client_id' => 'client-1',
            'client_secret' => 'secret-1',
            'token_url' => 'https://api.twitter.com/2/oauth2/token',
        ]);

        $this->assertTrue($result['success']);
    }

    public function test_test_connection_reports_failure_for_rejected_credentials(): void
    {
        Http::fake([
            'api.twitter.com/2/oauth2/token' => Http::response(['error_description' => 'Invalid client'], 401),
        ]);

        $result = $this->provider->testConnection([
            'client_id' => 'bad-client',
            'client_secret' => 'bad-secret',
            'token_url' => 'https://api.twitter.com/2/oauth2/token',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('Invalid client', $result['message']);
    }

    public function test_check_account_health_reports_healthy_for_a_valid_token(): void
    {
        Http::fake([
            'api.twitter.com/2/users/me*' => Http::response(['data' => ['id' => 'x-1']], 200),
        ]);

        $result = $this->provider->checkAccountHealth('x-access-token', []);

        $this->assertTrue($result['healthy']);
    }

    public function test_check_account_health_reports_unhealthy_for_a_rejected_token(): void
    {
        Http::fake([
            'api.twitter.com/2/users/me*' => Http::response(['title' => 'Unauthorized'], 401),
        ]);

        $result = $this->provider->checkAccountHealth('bad-token', []);

        $this->assertFalse($result['healthy']);
    }

    public function test_fetch_post_metrics_maps_public_metrics(): void
    {
        Http::fake([
            'api.twitter.com/2/tweets/tweet-1*' => Http::response([
                'data' => [
                    'id' => 'tweet-1',
                    'public_metrics' => [
                        'like_count' => 5,
                        'retweet_count' => 2,
                        'reply_count' => 1,
                        'impression_count' => 100,
                    ],
                ],
            ], 200),
        ]);

        $result = $this->provider->fetchPostMetrics('x-access-token', 'tweet-1', []);

        $this->assertTrue($result['available']);
        $this->assertSame(100, $result['metrics']['impressions']);
        $this->assertSame(5, $result['metrics']['reactions']);
        $this->assertSame(2, $result['metrics']['shares']);
        $this->assertSame(1, $result['metrics']['comments']);
    }
}
