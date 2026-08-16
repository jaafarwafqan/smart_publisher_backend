<?php

namespace App\Infrastructure\ExternalServices\Publishing;

use App\Infrastructure\ExternalServices\SocialOAuth\SocialOAuthManager;
use App\Models\PostMetric;
use App\Models\PostPublicationAttempt;
use Illuminate\Support\Arr;

class PostMetricsSyncService
{
    public function __construct(private readonly SocialOAuthManager $oauthManager) {}

    public function syncAttempt(PostPublicationAttempt $attempt): void
    {
        $socialAccount = $attempt->socialAccount;
        $socialPage = $attempt->socialPage;
        $providerPostId = (string) Arr::get(json_decode((string) $attempt->provider_response, true) ?? [], 'provider_post_id', '');

        if ($socialAccount === null || $providerPostId === '' || ! $socialAccount->access_token) {
            return;
        }

        // Same kind-aware substitution as PublishEngineService::callProvider()
        // and ClosedBetaPublishingGate::assertPageAllowed() — an
        // instagram_business page's parent SocialAccount is still
        // provider 'facebook', but its metrics live behind Instagram's own
        // insights API, called with the Page's own access token.
        $providerKey = $socialPage?->kind === 'instagram_business' ? 'instagram' : $socialAccount->provider;

        $result = $this->oauthManager->provider($providerKey)->fetchPostMetrics(
            (string) $socialAccount->access_token,
            $providerPostId,
            [
                'social_page_id' => $attempt->social_page_id,
                'page_access_token' => $socialPage?->access_token,
            ]
        );

        $metrics = $result['metrics'] ?? [];

        PostMetric::query()->updateOrCreate(
            ['post_id' => $attempt->post_id, 'social_page_id' => $attempt->social_page_id],
            [
                'provider' => $providerKey,
                'is_available' => $result['available'],
                'impressions' => $metrics['impressions'] ?? 0,
                'reach' => $metrics['reach'] ?? 0,
                'clicks' => $metrics['clicks'] ?? 0,
                'reactions' => $metrics['reactions'] ?? 0,
                'shares' => $metrics['shares'] ?? 0,
                'comments' => $metrics['comments'] ?? 0,
                'raw_response' => $result,
                'fetched_at' => now(),
            ]
        );
    }
}
