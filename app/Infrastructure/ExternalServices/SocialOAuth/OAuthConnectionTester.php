<?php

namespace App\Infrastructure\ExternalServices\SocialOAuth;

use App\Models\OAuthProviderSetting;
use App\Models\OAuthProviderSettingAuditLog;
use InvalidArgumentException;
use RuntimeException;

class OAuthConnectionTester
{
    /**
     * Providers the System Settings screen (and the health-check command)
     * manage app-level credentials for. Telegram and the other social
     * platforms use per-user credentials instead and never appear here.
     */
    public const PROVIDERS = [
        'facebook',
        'instagram',
        'linkedin',
        'x',
        'whatsapp',
    ];

    public function __construct(private readonly SocialOAuthManager $oauthManager) {}

    /**
     * Run a provider's testConnection() check, persist the result, and write
     * an audit log entry. Used by both the manual "Test Connection" button
     * (with the acting admin's id) and the scheduled health check (with a
     * null id, since no admin triggered it).
     *
     * @return array{success: bool, message: string}
     */
    public function test(string $provider, ?int $userId): array
    {
        $provider = strtolower($provider);

        try {
            $config = $this->oauthManager->providerConfig($provider);
        } catch (InvalidArgumentException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        try {
            $result = $this->oauthManager->provider($provider)->testConnection($config);
        } catch (RuntimeException $e) {
            $result = ['success' => false, 'message' => $e->getMessage()];
        }

        OAuthProviderSetting::query()->updateOrCreate(
            ['provider' => $provider],
            ['last_tested_at' => now(), 'last_test_success' => $result['success']]
        );

        OAuthProviderSettingAuditLog::query()->create([
            'provider' => $provider,
            'user_id' => $userId,
            'action' => 'tested',
            'success' => $result['success'],
        ]);

        return $result;
    }

    /**
     * Whether a provider has both a Client ID and Secret set — the minimum
     * needed for testConnection() to do anything other than immediately
     * report missing credentials.
     */
    public function isConfigured(string $provider): bool
    {
        $provider = strtolower($provider);

        try {
            $config = $this->oauthManager->providerConfig($provider);
        } catch (InvalidArgumentException) {
            return false;
        }

        return ! empty($config['client_id'] ?? null) && ! empty($config['client_secret'] ?? null);
    }
}
