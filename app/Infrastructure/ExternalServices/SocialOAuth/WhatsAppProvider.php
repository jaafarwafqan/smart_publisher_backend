<?php

namespace App\Infrastructure\ExternalServices\SocialOAuth;

use App\Infrastructure\ExternalServices\Contracts\SocialOAuthProviderContract;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Arr;
use RuntimeException;

/**
 * A WhatsApp Business connection uses Facebook Login under the hood (the
 * same Graph API OAuth flow as FacebookOAuthProvider), so the OAuth methods
 * delegate there rather than duplicating real HTTP calls. What's genuinely
 * different about WhatsApp is discovery: it requires a Meta Business ID
 * (captured from the user at connect time, since it can't always be derived
 * from the token) to look up WhatsApp Business Accounts and phone numbers.
 */
class WhatsAppProvider implements SocialOAuthProviderContract
{
    public function __construct(
        private readonly FacebookOAuthProvider $facebookProvider,
        private readonly HttpFactory $http,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function buildAuthorizeUrl(array $context): string
    {
        return $this->facebookProvider->buildAuthorizeUrl($context);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function exchangeCodeForToken(string $authorizationCode, array $context): array
    {
        return $this->facebookProvider->exchangeCodeForToken($authorizationCode, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function refreshAccessToken(string $refreshToken, array $context): array
    {
        return $this->facebookProvider->refreshAccessToken($refreshToken, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function publishPost(string $accessToken, array $context): array
    {
        throw new RuntimeException('WhatsApp message sending is not implemented yet.');
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    public function listPages(string $accessToken, array $context): array
    {
        $businessId = (string) Arr::get($context, 'metadata.business_id', '');

        if ($businessId === '') {
            // No Business ID captured yet — nothing to discover until the
            // user provides one (prompted client-side after connecting).
            return [];
        }

        $graphUrl = rtrim((string) config('services.facebook.graph_url', 'https://graph.facebook.com'), '/');

        $wabaResponse = $this->http->get($graphUrl.'/'.$businessId.'/owned_whatsapp_business_accounts', [
            'access_token' => $accessToken,
        ]);

        if ($wabaResponse->failed()) {
            throw new RuntimeException('WhatsApp Business Account lookup failed: '.$wabaResponse->body());
        }

        $results = [];

        foreach ((array) Arr::get($wabaResponse->json(), 'data', []) as $waba) {
            $wabaId = (string) Arr::get($waba, 'id', '');
            if ($wabaId === '') {
                continue;
            }

            $numbersResponse = $this->http->get($graphUrl.'/'.$wabaId.'/phone_numbers', [
                'access_token' => $accessToken,
            ]);

            if ($numbersResponse->failed()) {
                continue;
            }

            foreach ((array) Arr::get($numbersResponse->json(), 'data', []) as $number) {
                $results[] = [
                    'kind' => 'whatsapp_number',
                    'page_id' => (string) Arr::get($number, 'id'),
                    'name' => Arr::get($number, 'verified_name', Arr::get($number, 'display_phone_number')),
                    'can_publish' => true,
                    'metadata' => [
                        'waba_id' => $wabaId,
                        'display_phone_number' => Arr::get($number, 'display_phone_number'),
                        'quality_rating' => Arr::get($number, 'quality_rating'),
                    ],
                ];
            }
        }

        return $results;
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
     * A WhatsApp access token is a Facebook token, so its health check is
     * genuinely the same real /me call — delegate rather than duplicate it.
     *
     * @param  array<string, mixed>  $context
     * @return array{available: bool, healthy: bool, message: string}
     */
    public function checkAccountHealth(string $accessToken, array $context): array
    {
        return $this->facebookProvider->checkAccountHealth($accessToken, $context);
    }
}
