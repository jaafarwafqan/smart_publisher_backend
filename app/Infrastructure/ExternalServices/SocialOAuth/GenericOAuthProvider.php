<?php

namespace App\Infrastructure\ExternalServices\SocialOAuth;

use App\Infrastructure\ExternalServices\Contracts\SocialOAuthProviderContract;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class GenericOAuthProvider implements SocialOAuthProviderContract
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function buildAuthorizeUrl(array $context): string
    {
        $authorizeUrl = (string) Arr::get($context, 'provider_config.authorize_url', '');
        $clientId = (string) Arr::get($context, 'provider_config.client_id', '');
        $redirectUri = (string) Arr::get($context, 'redirect_uri', '');
        $state = (string) Arr::get($context, 'state', '');
        $scopes = Arr::get($context, 'scopes', Arr::get($context, 'provider_config.default_scopes', []));

        $query = [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'response_type' => 'code',
            'scope' => is_array($scopes) ? implode(' ', $scopes) : (string) $scopes,
        ];

        return $authorizeUrl.'?'.http_build_query($query);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function exchangeCodeForToken(string $authorizationCode, array $context): array
    {
        $now = Carbon::now();
        $expiresIn = (int) Arr::get($context, 'default_ttl_minutes', 60);

        // Placeholder implementation; switch to provider-specific HTTP call in production.
        return [
            'provider_account_id' => (string) Arr::get($context, 'provider_account_id', 'acc_'.substr(hash('sha256', $authorizationCode), 0, 12)),
            'account_name' => (string) Arr::get($context, 'account_name', 'Connected Account'),
            'account_username' => (string) Arr::get($context, 'account_username', null),
            'access_token' => 'mock_access_'.hash('sha256', $authorizationCode.$now->timestamp),
            'refresh_token' => 'mock_refresh_'.hash('sha256', 'refresh-'.$authorizationCode.$now->timestamp),
            'expires_at' => $now->copy()->addMinutes($expiresIn),
            'scopes' => Arr::get($context, 'scopes', []),
            'metadata' => [
                'mock' => true,
                'exchanged_at' => $now->toIso8601String(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function refreshAccessToken(string $refreshToken, array $context): array
    {
        $now = Carbon::now();
        $expiresIn = (int) Arr::get($context, 'default_ttl_minutes', 60);

        // Placeholder implementation; switch to provider-specific HTTP call in production.
        return [
            'access_token' => 'mock_access_'.hash('sha256', $refreshToken.$now->timestamp),
            'refresh_token' => 'mock_refresh_'.hash('sha256', 'refresh-'.$refreshToken.$now->timestamp),
            'expires_at' => $now->copy()->addMinutes($expiresIn),
            'metadata' => [
                'mock' => true,
                'refreshed_at' => $now->toIso8601String(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function publishPost(string $accessToken, array $context): array
    {
        return [
            'provider_post_id' => 'mock_post_'.substr(hash('sha256', $accessToken.(string) Arr::get($context, 'title', '')), 0, 16),
            'status' => 'published',
            'mock' => true,
            'published_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    public function listPages(string $accessToken, array $context): array
    {
        $seed = substr(hash('sha256', $accessToken), 0, 8);

        return [
            [
                'page_id' => 'mock_page_1_'.$seed,
                'name' => 'Mock Page One',
                'picture_url' => null,
                'can_publish' => true,
                'metadata' => ['mock' => true],
            ],
            [
                'page_id' => 'mock_page_2_'.$seed,
                'name' => 'Mock Page Two',
                'picture_url' => null,
                'can_publish' => true,
                'metadata' => ['mock' => true],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $providerConfig
     * @return array{success: bool, message: string}
     */
    public function testConnection(array $providerConfig): array
    {
        return [
            'success' => false,
            'message' => 'Live verification is not available for this provider yet.',
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{available: bool, message: string}
     */
    public function fetchPostMetrics(string $accessToken, string $providerPostId, array $context): array
    {
        return [
            'available' => false,
            'message' => 'Metrics are not available for this provider yet.',
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{available: bool, healthy: bool, message: string}
     */
    public function checkAccountHealth(string $accessToken, array $context): array
    {
        return [
            'available' => false,
            'healthy' => false,
            'message' => 'Live verification is not available for this provider yet.',
        ];
    }
}
