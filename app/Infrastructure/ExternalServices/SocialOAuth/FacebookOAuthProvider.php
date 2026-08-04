<?php

namespace App\Infrastructure\ExternalServices\SocialOAuth;

use App\Exceptions\Publishing\ProviderPublishException;
use App\Infrastructure\ExternalServices\Contracts\SocialOAuthProviderContract;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use RuntimeException;

class FacebookOAuthProvider implements SocialOAuthProviderContract
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
            'scope' => implode(',', Arr::get($context, 'scopes', Arr::get($context, 'provider_config.default_scopes', []))),
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
        $response = $this->http->asForm()->post((string) Arr::get($providerConfig, 'token_url'), [
            'client_id' => (string) Arr::get($providerConfig, 'client_id'),
            'client_secret' => (string) Arr::get($providerConfig, 'client_secret'),
            'redirect_uri' => (string) Arr::get($context, 'redirect_uri', ''),
            'code' => $authorizationCode,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Facebook token exchange failed: '.$response->body());
        }

        $tokenData = $response->json();
        $accessToken = (string) Arr::get($tokenData, 'access_token', '');

        if ($accessToken === '') {
            throw new RuntimeException('Facebook token exchange returned empty access token.');
        }

        $profileResponse = $this->http->get('https://graph.facebook.com/me', [
            'fields' => 'id,name',
            'access_token' => $accessToken,
        ]);

        if ($profileResponse->failed()) {
            throw new RuntimeException('Facebook profile fetch failed: '.$profileResponse->body());
        }

        $profile = $profileResponse->json();
        $expiresIn = (int) Arr::get($tokenData, 'expires_in', (int) config('social.default_token_ttl_minutes', 60) * 60);

        return [
            'provider_account_id' => (string) Arr::get($profile, 'id'),
            'account_name' => (string) Arr::get($profile, 'name'),
            'account_username' => null,
            'access_token' => $accessToken,
            'refresh_token' => (string) Arr::get($tokenData, 'refresh_token', ''),
            'expires_at' => Carbon::now()->addSeconds($expiresIn),
            'scopes' => Arr::get($context, 'scopes', Arr::get($providerConfig, 'default_scopes', [])),
            'metadata' => [
                'provider' => 'facebook',
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
        $response = $this->http->asForm()->post((string) Arr::get($providerConfig, 'token_url'), [
            'grant_type' => 'fb_exchange_token',
            'client_id' => (string) Arr::get($providerConfig, 'client_id'),
            'client_secret' => (string) Arr::get($providerConfig, 'client_secret'),
            'fb_exchange_token' => $refreshToken,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Facebook token refresh failed: '.$response->body());
        }

        $tokenData = $response->json();
        $accessToken = (string) Arr::get($tokenData, 'access_token', '');
        $expiresIn = (int) Arr::get($tokenData, 'expires_in', (int) config('social.default_token_ttl_minutes', 60) * 60);

        if ($accessToken === '') {
            throw new RuntimeException('Facebook refresh returned empty access token.');
        }

        return [
            'access_token' => $accessToken,
            'refresh_token' => (string) Arr::get($tokenData, 'refresh_token', $refreshToken),
            'expires_at' => Carbon::now()->addSeconds($expiresIn),
            'metadata' => [
                'provider' => 'facebook',
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
        $providerAccountId = (string) Arr::get($context, 'provider_account_id', 'me');
        $graphUrl = rtrim((string) config('services.facebook.graph_url', 'https://graph.facebook.com'), '/');

        $response = $this->http->asForm()->post($graphUrl.'/'.$providerAccountId.'/feed', [
            'message' => (string) Arr::get($context, 'content', Arr::get($context, 'title', '')),
            'access_token' => $accessToken,
        ]);

        if ($response->failed()) {
            $retryAfter = $response->header('Retry-After');

            throw new ProviderPublishException(
                'Facebook publish failed: '.$response->body(),
                httpStatus: $response->status(),
                retryAfterSeconds: $retryAfter === '' ? null : (int) $retryAfter,
                responseBody: $response->body(),
            );
        }

        $data = $response->json();

        return [
            'provider_post_id' => (string) Arr::get($data, 'id', ''),
            'status' => 'published',
            'published_at' => Carbon::now()->toIso8601String(),
            'raw_response' => $data,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    public function listPages(string $accessToken, array $context): array
    {
        $graphUrl = rtrim((string) config('services.facebook.graph_url', 'https://graph.facebook.com'), '/');

        $response = $this->http->get($graphUrl.'/me/accounts', [
            // instagram_business_account is requested here (not via a separate
            // Instagram OAuth flow) because Instagram Business accounts have no
            // independent OAuth — they only exist linked to a Facebook Page.
            // Note: page-scoped access_token is deliberately NOT requested —
            // publishing always uses the account-level (user) access token
            // (see PublishEngineService::callProvider), so a page token would
            // only ever sit unused in storage while being a credential leak.
            'fields' => 'id,name,picture,tasks,instagram_business_account{id,username,profile_picture_url}',
            'access_token' => $accessToken,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Facebook pages fetch failed: '.$response->body());
        }

        $pages = (array) Arr::get($response->json(), 'data', []);

        $results = [];

        foreach ($pages as $page) {
            $tasks = (array) Arr::get($page, 'tasks', []);
            $canPublish = in_array('CREATE_CONTENT', $tasks, true);

            $results[] = [
                'page_id' => (string) Arr::get($page, 'id'),
                'name' => Arr::get($page, 'name'),
                'picture_url' => Arr::get($page, 'picture.data.url'),
                'can_publish' => $canPublish,
                'metadata' => [
                    'tasks' => $tasks,
                ],
            ];

            $instagramAccount = Arr::get($page, 'instagram_business_account');
            if (is_array($instagramAccount) && ! empty($instagramAccount['id'])) {
                $results[] = [
                    'kind' => 'instagram_business',
                    'page_id' => (string) $instagramAccount['id'],
                    'name' => Arr::get($instagramAccount, 'username'),
                    'picture_url' => Arr::get($instagramAccount, 'profile_picture_url'),
                    'can_publish' => $canPublish,
                    'metadata' => [
                        'parent_page_id' => (string) Arr::get($page, 'id'),
                    ],
                ];
            }
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{available: bool, healthy: bool, message: string}
     */
    public function checkAccountHealth(string $accessToken, array $context): array
    {
        $graphUrl = rtrim((string) config('services.facebook.graph_url', 'https://graph.facebook.com'), '/');

        $response = $this->http->get($graphUrl.'/me', [
            'fields' => 'id',
            'access_token' => $accessToken,
        ]);

        if ($response->failed()) {
            $error = (string) Arr::get($response->json() ?? [], 'error.message', 'Facebook rejected this access token.');

            return ['available' => true, 'healthy' => false, 'message' => $error];
        }

        return ['available' => true, 'healthy' => true, 'message' => 'Connection is healthy.'];
    }

    /**
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
            return ['success' => false, 'message' => 'Token URL is not configured for Facebook.'];
        }

        // Facebook's token endpoint mints an app-only access token when asked
        // for grant_type=client_credentials, which only succeeds if the
        // Client ID/Secret pair genuinely matches a real Facebook app.
        $response = $this->http->get($tokenUrl, [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'client_credentials',
        ]);

        if ($response->failed() || ! $response->json('access_token')) {
            $error = (string) Arr::get($response->json() ?? [], 'error.message', 'Facebook rejected these credentials.');

            return ['success' => false, 'message' => $error];
        }

        return ['success' => true, 'message' => 'Facebook credentials verified successfully.'];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{available: bool, message: string, metrics?: array{impressions:int,reach:int,clicks:int,reactions:int,shares:int,comments:int}}
     */
    public function fetchPostMetrics(string $accessToken, string $providerPostId, array $context): array
    {
        $graphUrl = rtrim((string) config('services.facebook.graph_url', 'https://graph.facebook.com'), '/');

        $insightsResponse = $this->http->get($graphUrl.'/'.$providerPostId.'/insights', [
            'metric' => 'post_impressions,post_impressions_unique,post_clicks,post_reactions_by_type_total',
            'access_token' => $accessToken,
        ]);

        if ($insightsResponse->failed()) {
            $error = (string) Arr::get($insightsResponse->json() ?? [], 'error.message', 'Facebook insights fetch failed.');

            return ['available' => false, 'message' => $error];
        }

        $values = [];
        foreach ((array) Arr::get($insightsResponse->json(), 'data', []) as $metric) {
            $values[(string) Arr::get($metric, 'name')] = Arr::get($metric, 'values.0.value');
        }

        $reactionsByType = $values['post_reactions_by_type_total'] ?? [];
        $reactions = is_array($reactionsByType) ? array_sum($reactionsByType) : 0;

        $fieldsResponse = $this->http->get($graphUrl.'/'.$providerPostId, [
            'fields' => 'shares,comments.summary(true)',
            'access_token' => $accessToken,
        ]);

        $shares = 0;
        $comments = 0;
        if ($fieldsResponse->ok()) {
            $shares = (int) Arr::get($fieldsResponse->json(), 'shares.count', 0);
            $comments = (int) Arr::get($fieldsResponse->json(), 'comments.summary.total_count', 0);
        }

        return [
            'available' => true,
            'message' => 'Metrics fetched successfully.',
            'metrics' => [
                'impressions' => (int) ($values['post_impressions'] ?? 0),
                'reach' => (int) ($values['post_impressions_unique'] ?? 0),
                'clicks' => (int) ($values['post_clicks'] ?? 0),
                'reactions' => (int) $reactions,
                'shares' => $shares,
                'comments' => $comments,
            ],
        ];
    }
}
