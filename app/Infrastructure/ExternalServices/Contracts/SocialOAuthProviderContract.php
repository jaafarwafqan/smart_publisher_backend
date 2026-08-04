<?php

namespace App\Infrastructure\ExternalServices\Contracts;

interface SocialOAuthProviderContract
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function buildAuthorizeUrl(array $context): string;

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function exchangeCodeForToken(string $authorizationCode, array $context): array;

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function refreshAccessToken(string $refreshToken, array $context): array;

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function publishPost(string $accessToken, array $context): array;

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    public function listPages(string $accessToken, array $context): array;

    /**
     * Verify that a provider's app-level Client ID/Secret pair is genuinely
     * valid, using whatever app-only verification mechanism the provider's
     * real API offers. Providers with no real API integration yet must
     * report that verification isn't available rather than fabricate a
     * pass/fail result.
     *
     * @param  array<string, mixed>  $providerConfig
     * @return array{success: bool, message: string}
     */
    public function testConnection(array $providerConfig): array;

    /**
     * Fetch real engagement metrics for a published post, using whatever
     * insights API the provider's real integration offers. Providers with no
     * real API integration yet must report that metrics aren't available
     * rather than fabricate numbers.
     *
     * @param  array<string, mixed>  $context
     * @return array{available: bool, message: string, metrics?: array{impressions:int,reach:int,clicks:int,reactions:int,shares:int,comments:int}}
     */
    public function fetchPostMetrics(string $accessToken, string $providerPostId, array $context): array;

    /**
     * Verify that a specific user's stored access token still works, using
     * a cheap real API call. This checks a *user connection's* token —
     * distinct from testConnection(), which checks the app-level Client
     * ID/Secret. Providers with no real API integration yet must report
     * that verification isn't available rather than fabricate a result.
     *
     * @param  array<string, mixed>  $context
     * @return array{available: bool, healthy: bool, message: string}
     */
    public function checkAccountHealth(string $accessToken, array $context): array;
}
