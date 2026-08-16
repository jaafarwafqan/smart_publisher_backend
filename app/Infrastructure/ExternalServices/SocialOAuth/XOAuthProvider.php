<?php

namespace App\Infrastructure\ExternalServices\SocialOAuth;

use App\Exceptions\Publishing\ProviderPublishException;
use App\Infrastructure\ExternalServices\Contracts\SocialOAuthProviderContract;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * X (Twitter) API v2. Unlike Facebook/Telegram, X's OAuth 2.0 authorization-
 * code flow requires PKCE (code_challenge at authorize time, code_verifier
 * at token-exchange time) — the verifier/challenge pair itself is generated
 * by SocialAccountController::beginOAuthAuthorization() (not here) and
 * threaded through the same oauth-state Cache entry that already carries
 * redirect_uri, since this class's methods are otherwise stateless and the
 * SocialOAuthProviderContract signatures only pass a $context array, not a
 * side channel back to the caller. See that controller for the cache
 * plumbing.
 *
 * 2026-08: real code, real automated tests — but deliberately NOT added to
 * SocialOAuthManager::CLOSED_BETA_PROVIDERS yet. Posting write access on
 * X's API requires a paid Basic-or-above developer tier that hasn't been
 * live-verified against a real account. Same "real but not yet
 * production-approved" state WhatsAppProvider is already in.
 */
class XOAuthProvider implements SocialOAuthProviderContract
{
    public function __construct(private readonly HttpFactory $http) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function buildAuthorizeUrl(array $context): string
    {
        $authorizeUrl = (string) Arr::get($context, 'provider_config.authorize_url', '');
        $query = [
            'client_id' => (string) Arr::get($context, 'provider_config.client_id', ''),
            'redirect_uri' => (string) Arr::get($context, 'redirect_uri', ''),
            'state' => (string) Arr::get($context, 'state', ''),
            'response_type' => 'code',
            'scope' => implode(' ', Arr::get($context, 'scopes', Arr::get($context, 'provider_config.default_scopes', []))),
            'code_challenge' => (string) Arr::get($context, 'code_challenge', ''),
            'code_challenge_method' => 'S256',
        ];

        return $authorizeUrl.'?'.http_build_query($query);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function exchangeCodeForToken(string $authorizationCode, array $context): array
    {
        $providerConfig = Arr::get($context, 'provider_config', []);
        $codeVerifier = (string) Arr::get($context, 'code_verifier', '');

        if ($codeVerifier === '') {
            throw new RuntimeException('X token exchange requires a PKCE code_verifier.');
        }

        $response = $this->tokenRequest($providerConfig)->post((string) Arr::get($providerConfig, 'token_url'), [
            'grant_type' => 'authorization_code',
            'client_id' => (string) Arr::get($providerConfig, 'client_id'),
            'redirect_uri' => (string) Arr::get($context, 'redirect_uri', ''),
            'code' => $authorizationCode,
            'code_verifier' => $codeVerifier,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('X token exchange failed: '.$response->body());
        }

        $tokenData = $response->json();
        $accessToken = (string) Arr::get($tokenData, 'access_token', '');

        if ($accessToken === '') {
            throw new RuntimeException('X token exchange returned empty access token.');
        }

        $profile = $this->fetchProfile($accessToken);
        $expiresIn = (int) Arr::get($tokenData, 'expires_in', (int) config('social.default_token_ttl_minutes', 60) * 60);

        return [
            'provider_account_id' => (string) Arr::get($profile, 'id'),
            'account_name' => (string) Arr::get($profile, 'name'),
            'account_username' => Arr::get($profile, 'username') !== null ? '@'.Arr::get($profile, 'username') : null,
            'access_token' => $accessToken,
            'refresh_token' => (string) Arr::get($tokenData, 'refresh_token', ''),
            'expires_at' => Carbon::now()->addSeconds($expiresIn),
            'scopes' => Arr::get($context, 'scopes', Arr::get($providerConfig, 'default_scopes', [])),
            'metadata' => [
                'provider' => 'x',
                'token_type' => Arr::get($tokenData, 'token_type'),
                'raw_profile' => $profile,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function refreshAccessToken(string $refreshToken, array $context): array
    {
        $providerConfig = Arr::get($context, 'provider_config', []);
        $response = $this->tokenRequest($providerConfig)->post((string) Arr::get($providerConfig, 'token_url'), [
            'grant_type' => 'refresh_token',
            'client_id' => (string) Arr::get($providerConfig, 'client_id'),
            'refresh_token' => $refreshToken,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('X token refresh failed: '.$response->body());
        }

        $tokenData = $response->json();
        $accessToken = (string) Arr::get($tokenData, 'access_token', '');
        $expiresIn = (int) Arr::get($tokenData, 'expires_in', (int) config('social.default_token_ttl_minutes', 60) * 60);

        if ($accessToken === '') {
            throw new RuntimeException('X refresh returned empty access token.');
        }

        return [
            'access_token' => $accessToken,
            'refresh_token' => (string) Arr::get($tokenData, 'refresh_token', $refreshToken),
            'expires_at' => Carbon::now()->addSeconds($expiresIn),
            'metadata' => [
                'provider' => 'x',
                'refreshed' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function publishPost(string $accessToken, array $context): array
    {
        $attachments = (array) Arr::get($context, 'attachments', []);

        // Deliberate, honest scope limit for this release — not a silent
        // drop. Media on X needs a separate v1.1 chunked-upload dance
        // (media_id first, then attached to the tweet) that isn't built
        // yet; rejecting clearly before a job is even queued beats a
        // caption-only tweet that quietly lost its image.
        if ($attachments !== []) {
            throw new ProviderPublishException(
                'X publishing supports text-only posts in this release — image/video attachments are not yet supported.',
                httpStatus: 422,
            );
        }

        $text = (string) Arr::get($context, 'content', Arr::get($context, 'title', ''));

        $response = $this->http->withToken($accessToken)
            ->asJson()
            ->post('https://api.twitter.com/2/tweets', ['text' => $text]);

        $data = $this->assertSucceeded($response, 'X publish failed');
        $tweetId = (string) Arr::get($data, 'data.id', '');

        if ($tweetId === '') {
            throw new ProviderPublishException(
                'X publish returned no tweet id: '.$response->body(),
                httpStatus: $response->status(),
            );
        }

        return [
            'provider_post_id' => $tweetId,
            'status' => 'published',
            'published_at' => Carbon::now()->toIso8601String(),
            'raw_response' => $data,
        ];
    }

    /**
     * X has no equivalent of a Facebook Page or Telegram channel — a
     * connected account publishes to its own profile timeline, so this
     * returns exactly one synthetic target rather than a real list.
     *
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    public function listPages(string $accessToken, array $context): array
    {
        $profile = $this->fetchProfile($accessToken);
        $userId = (string) Arr::get($profile, 'id', '');

        if ($userId === '') {
            return [];
        }

        return [[
            'kind' => 'profile',
            'page_id' => $userId,
            'name' => Arr::get($profile, 'name', Arr::get($profile, 'username')),
            'picture_url' => Arr::get($profile, 'profile_image_url'),
            'can_publish' => true,
            'metadata' => [
                'username' => Arr::get($profile, 'username'),
            ],
        ]];
    }

    /**
     * X supports OAuth 2.0 App-Only auth (grant_type=client_credentials) for
     * app-level bearer tokens — mints one iff the client id/secret pair is
     * genuinely valid, same trick FacebookOAuthProvider::testConnection()
     * uses.
     *
     * @param  array<string, mixed>  $providerConfig
     * @return array{success: bool, message: string}
     */
    public function testConnection(array $providerConfig): array
    {
        $clientId = (string) Arr::get($providerConfig, 'client_id', '');
        $clientSecret = (string) Arr::get($providerConfig, 'client_secret', '');
        $tokenUrl = (string) Arr::get($providerConfig, 'token_url', '');

        if ($clientId === '' || $clientSecret === '') {
            return ['success' => false, 'message' => 'Client ID and Client Secret are required.'];
        }

        if ($tokenUrl === '') {
            return ['success' => false, 'message' => 'Token URL is not configured for X.'];
        }

        $response = $this->tokenRequest($providerConfig)->post($tokenUrl, [
            'grant_type' => 'client_credentials',
        ]);

        if ($response->failed() || ! $response->json('access_token')) {
            $error = (string) Arr::get($response->json() ?? [], 'error_description', 'X rejected these credentials.');

            return ['success' => false, 'message' => $error];
        }

        return ['success' => true, 'message' => 'X credentials verified successfully.'];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{available: bool, healthy: bool, message: string}
     */
    public function checkAccountHealth(string $accessToken, array $context): array
    {
        $response = $this->http->withToken($accessToken)->get('https://api.twitter.com/2/users/me');

        if ($response->failed()) {
            $error = (string) Arr::get($response->json() ?? [], 'title', 'X rejected this access token.');

            return ['available' => true, 'healthy' => false, 'message' => $error];
        }

        return ['available' => true, 'healthy' => true, 'message' => 'Connection is healthy.'];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{available: bool, message: string, metrics?: array{impressions:int,reach:int,clicks:int,reactions:int,shares:int,comments:int}}
     */
    public function fetchPostMetrics(string $accessToken, string $providerPostId, array $context): array
    {
        $response = $this->http->withToken($accessToken)->get(
            'https://api.twitter.com/2/tweets/'.$providerPostId,
            ['tweet.fields' => 'public_metrics'],
        );

        if ($response->failed()) {
            $error = (string) Arr::get($response->json() ?? [], 'title', 'X metrics fetch failed.');

            return ['available' => false, 'message' => $error];
        }

        $metrics = (array) Arr::get($response->json(), 'data.public_metrics', []);

        return [
            'available' => true,
            'message' => 'Metrics fetched successfully.',
            'metrics' => [
                // X's Basic tier exposes impression_count on the tweet
                // itself; there is no separate "unique reach" figure, so it
                // is left at 0 rather than double-counting impressions.
                'impressions' => (int) ($metrics['impression_count'] ?? 0),
                'reach' => 0,
                'clicks' => 0,
                'reactions' => (int) ($metrics['like_count'] ?? 0),
                'shares' => (int) ($metrics['retweet_count'] ?? 0),
                'comments' => (int) ($metrics['reply_count'] ?? 0),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $providerConfig
     */
    private function tokenRequest(array $providerConfig): PendingRequest
    {
        // X's token endpoint (authorization_code, refresh_token, and
        // client_credentials grants alike) authenticates a confidential
        // client via HTTP Basic auth, not a client_secret form field.
        return $this->http->asForm()->withBasicAuth(
            (string) Arr::get($providerConfig, 'client_id', ''),
            (string) Arr::get($providerConfig, 'client_secret', ''),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchProfile(string $accessToken): array
    {
        $response = $this->http->withToken($accessToken)->get(
            'https://api.twitter.com/2/users/me',
            ['user.fields' => 'profile_image_url'],
        );

        if ($response->failed()) {
            throw new RuntimeException('X profile fetch failed: '.$response->body());
        }

        return (array) Arr::get($response->json(), 'data', []);
    }

    /**
     * @return array<string, mixed>
     */
    private function assertSucceeded(Response $response, string $errorPrefix): array
    {
        if ($response->failed()) {
            // X returns the Unix timestamp the limit resets at, not a
            // seconds-until-reset delta like Facebook's/Telegram's
            // Retry-After — PublishErrorClassifier/RetryBackoffCalculator
            // both expect seconds-from-now, so convert here rather than
            // pushing that provider-specific quirk further up the stack.
            $resetAt = $response->header('x-rate-limit-reset');
            $retryAfterSeconds = null;
            if ($resetAt !== '' && ctype_digit($resetAt)) {
                $retryAfterSeconds = max(0, (int) $resetAt - Carbon::now()->timestamp);
            }

            throw new ProviderPublishException(
                $errorPrefix.': '.$response->body(),
                httpStatus: $response->status(),
                retryAfterSeconds: $retryAfterSeconds,
                responseBody: $response->body(),
            );
        }

        return (array) $response->json();
    }
}
