<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationPermission;
use App\Http\Controllers\Controller;
use App\Infrastructure\ExternalServices\Publishing\SocialPageSyncService;
use App\Infrastructure\ExternalServices\SocialOAuth\SocialOAuthManager;
use App\Infrastructure\ExternalServices\SocialOAuth\TelegramProvider;
use App\Jobs\RefreshSocialAccountTokenJob;
use App\Models\OAuthProviderSetting;
use App\Models\SocialAccount;
use App\Models\SocialPage;
use App\Models\User;
use App\Services\ContextLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

class SocialAccountController extends Controller
{
    public function __construct(private readonly SocialOAuthManager $oauthManager) {}

    private const PROVIDERS = [
        'facebook',
        'telegram',
        'instagram',
        'x',
        'linkedin',
        'whatsapp',
        'youtube',
        'tiktok',
        'snapchat',
        'pinterest',
        'other',
    ];

    public function index(Request $request, User $user): JsonResponse
    {
        $this->authorizeTargetUserCapability($request, $user, OrganizationPermission::SocialAccountsView);

        $accounts = $user->socialAccounts()
            ->latest()
            ->get()
            ->map(fn (SocialAccount $account): array => $this->transform($account));

        return response()->json([
            'data' => $accounts,
        ]);
    }

    public function store(Request $request, User $user): JsonResponse
    {
        $this->authorizeTargetUserCapability($request, $user, OrganizationPermission::SocialAccountsConnect);

        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in(self::PROVIDERS)],
            'provider_account_id' => ['required', 'string', 'max:255'],
            'account_name' => ['nullable', 'string', 'max:255'],
            'account_username' => ['nullable', 'string', 'max:255'],
            'access_token' => ['nullable', 'string'],
            'refresh_token' => ['nullable', 'string'],
            'token_expires_at' => ['nullable', 'date'],
            'scopes' => ['nullable', 'array'],
            'scopes.*' => ['string'],
            'metadata' => ['nullable', 'array'],
            'status' => ['nullable', Rule::in(['connected', 'expired', 'revoked', 'failed', 'pending'])],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($response = $this->rejectUnavailableProductionProvider($validated['provider'])) {
            return $response;
        }

        $existing = SocialAccount::query()
            ->where('provider', $validated['provider'])
            ->where('provider_account_id', $validated['provider_account_id'])
            ->first();

        if ($existing && $existing->user_id !== $user->id) {
            return response()->json([
                'message' => 'This provider account is already linked to another user.',
            ], 422);
        }

        $socialAccount = SocialAccount::query()->updateOrCreate(
            [
                'provider' => $validated['provider'],
                'provider_account_id' => $validated['provider_account_id'],
            ],
            [
                'user_id' => $user->id,
                'account_name' => $validated['account_name'] ?? null,
                'account_username' => $validated['account_username'] ?? null,
                'access_token' => $validated['access_token'] ?? null,
                'refresh_token' => $validated['refresh_token'] ?? null,
                'token_expires_at' => $validated['token_expires_at'] ?? null,
                'scopes' => $validated['scopes'] ?? [],
                'metadata' => $validated['metadata'] ?? [],
                'status' => $validated['status'] ?? 'connected',
                'is_active' => $validated['is_active'] ?? true,
                'last_synced_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'Social account linked successfully.',
            'data' => $this->transform($socialAccount),
        ], 201);
    }

    public function show(User $user, SocialAccount $socialAccount): JsonResponse
    {
        $this->authorize('view', $socialAccount);

        return response()->json([
            'data' => $this->transform($socialAccount),
        ]);
    }

    public function update(Request $request, User $user, SocialAccount $socialAccount): JsonResponse
    {
        $this->authorize('update', $socialAccount);

        $validated = $request->validate([
            'account_name' => ['nullable', 'string', 'max:255'],
            'account_username' => ['nullable', 'string', 'max:255'],
            'access_token' => ['nullable', 'string'],
            'refresh_token' => ['nullable', 'string'],
            'token_expires_at' => ['nullable', 'date'],
            'scopes' => ['nullable', 'array'],
            'scopes.*' => ['string'],
            'metadata' => ['nullable', 'array'],
            'status' => ['nullable', Rule::in(['connected', 'expired', 'revoked', 'failed', 'pending'])],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $validated['last_synced_at'] = now();

        $socialAccount->update($validated);

        return response()->json([
            'message' => 'Social account updated successfully.',
            'data' => $this->transform($socialAccount->fresh()),
        ]);
    }

    public function destroy(User $user, SocialAccount $socialAccount): JsonResponse
    {
        $this->authorize('delete', $socialAccount);

        $socialAccount->delete();

        return response()->json([
            'message' => 'Social account removed successfully.',
        ]);
    }

    public function refreshToken(User $user, SocialAccount $socialAccount): JsonResponse
    {
        $this->authorize('refreshToken', $socialAccount);

        if (! $socialAccount->hasRefreshToken()) {
            return response()->json([
                'message' => 'Refresh token is not available for this account.',
            ], 422);
        }

        RefreshSocialAccountTokenJob::dispatch($socialAccount->id, (int) $socialAccount->organization_id);

        ContextLogger::info('social.token.refresh.dispatched', [
            'user_id' => $user->id,
            'social_account_id' => $socialAccount->id,
            'provider' => $socialAccount->provider,
        ], request());

        $socialAccount->update([
            'status' => 'pending',
            'last_synced_at' => now(),
        ]);

        return response()->json([
            'message' => 'Refresh token job dispatched successfully.',
            'data' => $this->transform($socialAccount->fresh()),
        ]);
    }

    public function testConnection(User $user, SocialAccount $socialAccount): JsonResponse
    {
        $this->authorize('testConnection', $socialAccount);

        if (! $socialAccount->access_token) {
            return response()->json([
                'data' => ['available' => false, 'healthy' => false, 'message' => 'No access token stored for this connection.'],
            ], 422);
        }

        $result = $this->oauthManager->provider($socialAccount->provider)->checkAccountHealth(
            (string) $socialAccount->access_token,
            []
        );

        // A confirmed-healthy check self-heals a stale expired/failed status;
        // a confirmed-unhealthy one marks it expired so the UI can offer
        // re-authentication instead of a silently-broken "Connected" pill.
        // "Not available" (still-mocked provider) must never be treated as
        // "confirmed broken" — the status is left untouched in that case.
        $updates = ['last_synced_at' => now()];
        if ($result['available']) {
            if ($result['healthy'] && in_array($socialAccount->status, ['expired', 'failed'], true)) {
                $updates['status'] = 'connected';
            } elseif (! $result['healthy']) {
                $updates['status'] = 'expired';
            }
        }
        $socialAccount->update($updates);

        ContextLogger::info('social.account.test_connection', [
            'user_id' => $user->id,
            'social_account_id' => $socialAccount->id,
            'available' => $result['available'],
            'healthy' => $result['healthy'],
        ], request());

        return response()->json([
            'data' => [
                'available' => $result['available'],
                'healthy' => $result['healthy'],
                'message' => $result['message'],
            ],
        ]);
    }

    public function beginOAuthAuthorization(Request $request, User $user): JsonResponse
    {
        $organizationId = $this->authorizeTargetUserCapability(
            $request,
            $user,
            OrganizationPermission::SocialAccountsConnect,
        );

        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in(self::PROVIDERS)],
            'redirect_uri' => ['required', 'string', Rule::in((array) config('social.allowed_redirect_uris', []))],
            'scopes' => ['nullable', 'array'],
            'scopes.*' => ['string'],
        ]);

        if ($response = $this->rejectUnavailableProductionProvider($validated['provider'])) {
            return $response;
        }

        if ($response = $this->rejectDisabledProvider($validated['provider'])) {
            return $response;
        }

        $state = Str::random(48);
        $ttlMinutes = (int) config('social.oauth_state_ttl_minutes', 15);
        $stateExpiresAt = now()->addMinutes($ttlMinutes);

        $authorizeUrl = $this->oauthManager->authorizeUrl($validated['provider'], [
            'redirect_uri' => $validated['redirect_uri'],
            'state' => $state,
            'scopes' => $validated['scopes'] ?? [],
        ]);

        // A Cache entry, not a placeholder SocialAccount row: this never
        // represents a real connection, only a CSRF-state handshake in
        // progress, so it shouldn't need its own business-table row (with
        // an awkward 'pending_<state>' provider_account_id) at all.
        // Cache::pull() in callback() below consumes it atomically —
        // single-use by construction — and the TTL here is the only expiry
        // check needed, no separate timestamp column to compare.
        Cache::put($this->oauthStateCacheKey($validated['provider'], $state, $organizationId), [
            'user_id' => $user->id,
            'redirect_uri' => $validated['redirect_uri'],
        ], $stateExpiresAt);

        ContextLogger::info('oauth.authorize.generated', [
            'user_id' => $user->id,
            'provider' => $validated['provider'],
        ], $request);

        return response()->json([
            'message' => 'OAuth authorization URL generated.',
            'provider' => $validated['provider'],
            'state' => $state,
            'state_expires_at' => $stateExpiresAt,
            'authorize_url' => $authorizeUrl,
        ]);
    }

    public function callback(Request $request, User $user): JsonResponse
    {
        $organizationId = $this->authorizeTargetUserCapability(
            $request,
            $user,
            OrganizationPermission::SocialAccountsConnect,
        );

        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in(self::PROVIDERS)],
            'code' => ['required', 'string'],
            'state' => ['required', 'string'],
            'scopes' => ['nullable', 'array'],
            'scopes.*' => ['string'],
        ]);

        if ($response = $this->rejectUnavailableProductionProvider($validated['provider'])) {
            return $response;
        }

        if ($response = $this->rejectDisabledProvider($validated['provider'])) {
            return $response;
        }

        // Cache::pull() is an atomic get-then-forget — even two requests
        // racing on the exact same state value can't both get a non-null
        // result, so this is single-use with no separate delete step and no
        // window for replay. A missing/expired entry (TTL elapsed, wrong
        // provider, or already consumed) all collapse to the same "invalid
        // state" response — nothing here reveals which case it was.
        $pending = Cache::pull($this->oauthStateCacheKey($validated['provider'], $validated['state'], $organizationId));

        if (! $pending || (int) $pending['user_id'] !== $user->id) {
            return response()->json([
                'message' => 'Invalid or expired OAuth state.',
            ], 422);
        }

        $tokenPayload = $this->oauthManager->exchangeCode($validated['provider'], $validated['code'], [
            'redirect_uri' => $pending['redirect_uri'],
            'scopes' => $validated['scopes'] ?? [],
        ]);

        $linked = SocialAccount::query()->updateOrCreate(
            [
                'provider' => $validated['provider'],
                'provider_account_id' => (string) ($tokenPayload['provider_account_id'] ?? 'acc_'.Str::random(12)),
            ],
            [
                'user_id' => $user->id,
                // WhatsApp is 'auto' too: once a Business ID is set (see
                // SocialAccountController::update()), listPages() discovers
                // real phone numbers the same way Facebook discovers Pages —
                // the one manual step is a one-time input, not an ongoing
                // per-page workflow like Telegram's addPage()/verifyChat().
                'discovery_mode' => in_array($validated['provider'], ['facebook', 'instagram', 'whatsapp'], true) ? 'auto' : 'manual',
                'account_name' => $tokenPayload['account_name'] ?? null,
                'account_username' => $tokenPayload['account_username'] ?? null,
                'access_token' => $tokenPayload['access_token'] ?? null,
                'refresh_token' => $tokenPayload['refresh_token'] ?? null,
                'token_expires_at' => $tokenPayload['expires_at'] ?? null,
                'scopes' => $tokenPayload['scopes'] ?? ($validated['scopes'] ?? []),
                'metadata' => $tokenPayload['metadata'] ?? [],
                'status' => 'connected',
                'is_active' => true,
                'last_synced_at' => now(),
            ]
        );

        ContextLogger::info('oauth.callback.connected', [
            'user_id' => $user->id,
            'provider' => $validated['provider'],
            'social_account_id' => $linked->id,
        ], $request);

        return response()->json([
            'message' => 'Social account connected successfully via OAuth.',
            'data' => $this->transform($linked),
        ]);
    }

    public function setStatus(Request $request, User $user, SocialAccount $socialAccount): JsonResponse
    {
        $this->authorize('changeStatus', $socialAccount);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['connected', 'expired', 'revoked', 'failed', 'pending'])],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $socialAccount->update([
            'status' => $validated['status'],
            'is_active' => $validated['is_active'] ?? $socialAccount->is_active,
            'last_synced_at' => now(),
        ]);

        return response()->json([
            'message' => 'Social account status updated successfully.',
            'data' => $this->transform($socialAccount->fresh()),
        ]);
    }

    public function providers(Request $request): JsonResponse
    {
        $this->authorizeOrganizationCapability($request, OrganizationPermission::SocialAccountsView);

        return response()->json([
            'providers' => $this->oauthManager->catalogProviders(),
        ]);
    }

    public function connectTelegramBot(Request $request, User $user): JsonResponse
    {
        $this->authorizeTargetUserCapability($request, $user, OrganizationPermission::SocialAccountsConnect);

        $validated = $request->validate([
            'bot_token' => ['required', 'string'],
        ]);

        if ($response = $this->rejectDisabledProvider('telegram')) {
            return $response;
        }

        try {
            $bot = app(TelegramProvider::class)->verifyBotToken($validated['bot_token']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $account = SocialAccount::query()->updateOrCreate(
            [
                'provider' => 'telegram',
                'provider_account_id' => (string) ($bot['id'] ?? ''),
            ],
            [
                'user_id' => $user->id,
                'discovery_mode' => 'manual',
                'account_name' => $bot['username'] ?? $bot['first_name'] ?? 'Telegram Bot',
                'account_username' => isset($bot['username']) ? '@'.$bot['username'] : null,
                'access_token' => $validated['bot_token'],
                'status' => 'connected',
                'is_active' => true,
                'last_synced_at' => now(),
            ]
        );

        ContextLogger::info('social.telegram.bot.connected', [
            'user_id' => $user->id,
            'social_account_id' => $account->id,
        ], $request);

        return response()->json([
            'message' => 'Telegram bot connected successfully.',
            'data' => $this->transform($account),
        ], 201);
    }

    public function listPages(User $user, SocialAccount $socialAccount): JsonResponse
    {
        $this->authorize('viewPages', $socialAccount);

        $pages = $socialAccount->pages()
            ->orderByDesc('is_selected')
            ->orderBy('name')
            ->get()
            ->map(fn (SocialPage $page): array => $this->transformPage($page));

        return response()->json(['data' => $pages]);
    }

    public function addPage(Request $request, User $user, SocialAccount $socialAccount): JsonResponse
    {
        $this->authorize('managePages', $socialAccount);

        $validated = $request->validate([
            'identifier' => ['required', 'string'],
        ]);

        if ($response = $this->rejectDisabledProvider($socialAccount->provider)) {
            return $response;
        }

        try {
            $verified = match ($socialAccount->provider) {
                'telegram' => app(TelegramProvider::class)->verifyChat((string) $socialAccount->access_token, $validated['identifier']),
                default => throw new RuntimeException('Manually adding a page is not supported for this provider yet.'),
            };
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $page = SocialPage::query()->updateOrCreate(
            ['social_account_id' => $socialAccount->id, 'page_id' => $verified['page_id']],
            [
                'kind' => 'channel',
                'name' => $verified['name'],
                'username' => $verified['username'] ?? null,
                'can_publish' => $verified['can_publish'],
                'status' => $verified['can_publish'] ? 'valid' : 'needs_reauth',
                'discovery_source' => 'manual',
                'metadata' => $verified['metadata'] ?? [],
                'last_synced_at' => now(),
                'last_verified_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'Channel verified and added.',
            'data' => $this->transformPage($page),
        ], 201);
    }

    public function syncPages(User $user, SocialAccount $socialAccount, SocialPageSyncService $syncService): JsonResponse
    {
        $this->authorize('managePages', $socialAccount);

        if ($response = $this->rejectDisabledProvider($socialAccount->provider)) {
            return $response;
        }

        if ($socialAccount->isAutoDiscovery()) {
            $result = $syncService->syncAuto($socialAccount);

            return response()->json([
                'message' => 'Pages synced.',
                'result' => $result,
                'data' => $socialAccount->pages()->get()->map(fn (SocialPage $page): array => $this->transformPage($page)),
            ]);
        }

        $result = $syncService->syncManual($socialAccount);

        return response()->json([
            'message' => 'Channels re-verified.',
            'result' => $result,
            'data' => $socialAccount->pages()->get()->map(fn (SocialPage $page): array => $this->transformPage($page)),
        ]);
    }

    public function destroyPage(User $user, SocialAccount $socialAccount, SocialPage $socialPage): JsonResponse
    {
        $this->authorize('managePages', $socialAccount);

        if ($socialPage->social_account_id !== $socialAccount->id) {
            return response()->json(['message' => 'Page does not belong to this account.'], 404);
        }

        $socialPage->delete();

        return response()->json([
            'message' => 'Page removed successfully.',
        ]);
    }

    public function selectPages(Request $request, User $user, SocialAccount $socialAccount): JsonResponse
    {
        $this->authorize('managePages', $socialAccount);

        $validated = $request->validate([
            'page_ids' => ['required', 'array'],
            'page_ids.*' => ['integer', 'exists:social_pages,id'],
        ]);

        $socialAccount->pages()->update(['is_selected' => false]);
        $socialAccount->pages()->whereIn('id', $validated['page_ids'])->update(['is_selected' => true]);

        return response()->json([
            'message' => 'Selected pages updated.',
            'data' => $socialAccount->pages()->get()->map(fn (SocialPage $page): array => $this->transformPage($page)),
        ]);
    }

    private function transform(SocialAccount $socialAccount): array
    {
        return [
            'id' => $socialAccount->id,
            'user_id' => $socialAccount->user_id,
            'provider' => $socialAccount->provider,
            'discovery_mode' => $socialAccount->discovery_mode,
            'provider_account_id' => $socialAccount->provider_account_id,
            'account_name' => $socialAccount->account_name,
            'account_username' => $socialAccount->account_username,
            'token_expires_at' => $socialAccount->token_expires_at,
            'is_token_expired' => $socialAccount->isTokenExpired(),
            'has_refresh_token' => $socialAccount->hasRefreshToken(),
            'scopes' => $socialAccount->scopes,
            'metadata' => $socialAccount->metadata,
            'is_active' => $socialAccount->is_active,
            'status' => $socialAccount->status,
            'last_synced_at' => $socialAccount->last_synced_at,
            'last_published_at' => $socialAccount->last_published_at,
            'created_at' => $socialAccount->created_at,
            'updated_at' => $socialAccount->updated_at,
        ];
    }

    private function transformPage(SocialPage $page): array
    {
        return [
            'id' => $page->id,
            'social_account_id' => $page->social_account_id,
            'page_id' => $page->page_id,
            'kind' => $page->kind,
            'name' => $page->name,
            'username' => $page->username,
            'picture_url' => $page->picture_url,
            'can_publish' => $page->can_publish,
            'is_selected' => $page->is_selected,
            'status' => $page->status,
            'discovery_source' => $page->discovery_source,
            'metadata' => $page->metadata,
            'last_synced_at' => $page->last_synced_at,
            'last_verified_at' => $page->last_verified_at,
        ];
    }

    private function rejectUnavailableProductionProvider(string $provider): ?JsonResponse
    {
        if (! app()->environment('production') || $this->oauthManager->isClosedBetaProvider($provider)) {
            return null;
        }

        $message = $this->oauthManager->isMockProvider($provider)
            ? ucfirst($provider).' is not available in production yet. It has no live publishing integration.'
            : ucfirst($provider).' is not enabled for the current closed beta release.';

        return response()->json([
            'message' => $message,
            'code' => 'provider_not_available_in_production',
        ], 422);
    }

    // The is_enabled toggle in System Settings used to only affect what the
    // admin screen displayed — every real OAuth entry point still worked
    // regardless. SocialOAuthManager::provider() now throws for a disabled
    // provider too (covering every path, including jobs/sync commands), but
    // that surfaces as an uncaught 500 to an HTTP caller unless checked
    // here first — mirrors rejectUnavailableProductionProvider's pattern of
    // failing cleanly with a 422 before any external call is attempted.
    private function rejectDisabledProvider(string $provider): ?JsonResponse
    {
        if (OAuthProviderSetting::isEnabled($provider)) {
            return null;
        }

        return response()->json([
            'message' => ucfirst($provider).' is currently disabled by an administrator.',
            'code' => 'provider_disabled',
        ], 422);
    }

    private function authorizeTargetUserCapability(
        Request $request,
        User $targetUser,
        OrganizationPermission $permission,
    ): int {
        $organizationId = $this->authorizeOrganizationCapability($request, $permission);

        if (! $targetUser->isMemberOf($organizationId)) {
            abort(404, 'The requested user is not a member of the current organization.');
        }

        return $organizationId;
    }

    private function authorizeOrganizationCapability(Request $request, OrganizationPermission $permission): int
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            abort(401, 'Unauthenticated.');
        }

        $organizationId = app(TenantContext::class)->get();
        if (! $actor->hasOrganizationPermission($organizationId, $permission)) {
            abort(403, 'You do not have permission to manage social accounts in this organization.');
        }

        return $organizationId;
    }

    private function oauthStateCacheKey(string $provider, string $state, int $organizationId): string
    {
        return 'oauth_state:'.$organizationId.':'.$provider.':'.$state;
    }
}
