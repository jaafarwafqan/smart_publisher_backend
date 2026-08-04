<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Infrastructure\ExternalServices\SocialOAuth\OAuthConnectionTester;
use App\Infrastructure\ExternalServices\SocialOAuth\SocialOAuthManager;
use App\Models\OAuthProviderSetting;
use App\Models\OAuthProviderSettingAuditLog;
use App\Rules\SafeOutboundUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class SystemSettingsController extends Controller
{
    public function __construct(
        private readonly SocialOAuthManager $oauthManager,
        private readonly OAuthConnectionTester $connectionTester,
    ) {}

    public function index(): JsonResponse
    {
        $providers = array_map(
            fn (string $provider): array => $this->resolveProviderState($provider),
            OAuthConnectionTester::PROVIDERS
        );

        return response()->json(['data' => $providers]);
    }

    public function update(Request $request, string $provider): JsonResponse
    {
        $provider = strtolower($provider);

        if (! in_array($provider, OAuthConnectionTester::PROVIDERS, true)) {
            return response()->json(['message' => 'Unsupported provider.'], 422);
        }

        $validated = $request->validate([
            'client_id' => ['nullable', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'max:1000'],
            'authorize_url' => ['nullable', 'url', 'max:2048', new SafeOutboundUrl],
            'token_url' => ['nullable', 'url', 'max:2048', new SafeOutboundUrl],
            'default_scopes' => ['nullable', 'array'],
            'default_scopes.*' => ['string'],
            'is_enabled' => ['nullable', 'boolean'],
        ]);

        $payload = Arr::only($validated, ['client_id', 'authorize_url', 'token_url', 'default_scopes', 'is_enabled']);
        // A blank/omitted client_secret must never overwrite an already-stored
        // one — the edit form never shows the real secret back, so leaving
        // the field empty means "keep what's there", not "clear it".
        if (! empty($validated['client_secret'])) {
            $payload['client_secret'] = $validated['client_secret'];
        }
        $payload['updated_by'] = $request->user()->id;

        OAuthProviderSetting::query()->updateOrCreate(['provider' => $provider], $payload);

        // Audit trail records which fields changed, never their values — the
        // secret itself must never be persisted here.
        OAuthProviderSettingAuditLog::query()->create([
            'provider' => $provider,
            'user_id' => $request->user()->id,
            'action' => 'updated',
            'changed_fields' => array_keys(Arr::except($payload, ['updated_by'])),
        ]);

        return response()->json([
            'message' => 'Provider settings updated successfully.',
            'data' => $this->resolveProviderState($provider),
        ]);
    }

    public function testConnection(Request $request, string $provider): JsonResponse
    {
        $provider = strtolower($provider);

        if (! in_array($provider, OAuthConnectionTester::PROVIDERS, true)) {
            return response()->json(['message' => 'Unsupported provider.'], 422);
        }

        $result = $this->connectionTester->test($provider, $request->user()->id);

        return response()->json([
            'data' => [
                'success' => $result['success'],
                'message' => $result['message'],
                'tested_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function auditLog(string $provider): JsonResponse
    {
        $provider = strtolower($provider);

        if (! in_array($provider, OAuthConnectionTester::PROVIDERS, true)) {
            return response()->json(['message' => 'Unsupported provider.'], 422);
        }

        $entries = OAuthProviderSettingAuditLog::query()
            ->where('provider', $provider)
            ->with('user:id,name')
            ->latest('created_at')
            ->limit(20)
            ->get()
            ->map(fn (OAuthProviderSettingAuditLog $entry): array => [
                'action' => $entry->action,
                'changed_fields' => $entry->changed_fields ?? [],
                'success' => $entry->success,
                'user_name' => $entry->user?->name,
                'created_at' => $entry->created_at,
            ]);

        return response()->json(['data' => $entries]);
    }

    private function resolveProviderState(string $provider): array
    {
        try {
            $config = $this->oauthManager->providerConfig($provider);
        } catch (\InvalidArgumentException) {
            $config = [];
        }

        $setting = OAuthProviderSetting::query()->where('provider', $provider)->first();

        return [
            'provider' => $provider,
            'client_id' => $config['client_id'] ?? null,
            'has_client_secret' => ! empty($config['client_secret'] ?? null),
            'authorize_url' => $config['authorize_url'] ?? null,
            'token_url' => $config['token_url'] ?? null,
            'default_scopes' => $config['default_scopes'] ?? [],
            'is_mock_integration' => $this->oauthManager->isMockProvider($provider),
            'is_enabled' => $setting === null ? true : $setting->is_enabled,
            'updated_at' => $setting?->updated_at,
            'updated_by_name' => $setting?->updatedByUser?->name,
            'last_tested_at' => $setting?->last_tested_at,
            'last_test_success' => $setting?->last_test_success,
        ];
    }
}
