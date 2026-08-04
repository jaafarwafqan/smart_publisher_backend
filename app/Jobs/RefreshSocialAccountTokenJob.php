<?php

namespace App\Jobs;

use App\Infrastructure\ExternalServices\SocialOAuth\SocialOAuthManager;
use App\Models\SocialAccount;
use App\Services\ContextLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RefreshSocialAccountTokenJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $socialAccountId, public int $organizationId = 0) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(SocialOAuthManager $oauthManager): void
    {
        app(TenantContext::class)->run($this->organizationId, function () use ($oauthManager): void {
            $socialAccount = SocialAccount::query()->find($this->socialAccountId);

            if (! $socialAccount || ! $socialAccount->hasRefreshToken()) {
                return;
            }

            $refreshed = $oauthManager->refreshToken(
                $socialAccount->provider,
                (string) $socialAccount->refresh_token,
                [
                    'scopes' => $socialAccount->scopes ?? [],
                ]
            );

            $socialAccount->update([
                'access_token' => $refreshed['access_token'] ?? $socialAccount->access_token,
                'refresh_token' => $refreshed['refresh_token'] ?? $socialAccount->refresh_token,
                'token_expires_at' => $refreshed['expires_at'] ?? $socialAccount->token_expires_at,
                'metadata' => array_merge($socialAccount->metadata ?? [], $refreshed['metadata'] ?? []),
                'status' => 'connected',
                'is_active' => true,
                'last_synced_at' => now(),
            ]);
        });
    }

    public function failed(Throwable $exception): void
    {
        app(TenantContext::class)->run($this->organizationId, function () use ($exception): void {
            $socialAccount = SocialAccount::query()->find($this->socialAccountId);

            if (! $socialAccount) {
                return;
            }

            // Previously a refresh failure was silent: the job would just vanish
            // after retries, leaving the account looking "connected" while its
            // token quietly stopped working.
            $socialAccount->update([
                'status' => 'expired',
                'last_synced_at' => now(),
            ]);

            ContextLogger::error('social.token.refresh.failed', [
                'social_account_id' => $this->socialAccountId,
                'provider' => $socialAccount->provider,
                'error' => $exception->getMessage(),
            ]);
        });
    }
}
