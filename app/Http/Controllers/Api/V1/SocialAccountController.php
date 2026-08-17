<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\SocialAccount\AddSocialPageRequest;
use App\Http\Requests\SocialAccount\BeginOAuthAuthorizationRequest;
use App\Http\Requests\SocialAccount\CompleteOAuthCallbackRequest;
use App\Http\Requests\SocialAccount\ConnectTelegramBotRequest;
use App\Http\Requests\SocialAccount\NativeConnectRequest;
use App\Http\Requests\SocialAccount\SelectSocialPagesRequest;
use App\Http\Requests\SocialAccount\SetSocialAccountStatusRequest;
use App\Http\Requests\SocialAccount\UpdateSocialAccountRequest;
use App\Infrastructure\ExternalServices\Publishing\SocialPageSyncService;
use App\Infrastructure\ExternalServices\SocialOAuth\SocialOAuthManager;
use App\Infrastructure\ExternalServices\SocialOAuth\TelegramProvider;
use App\Jobs\RefreshSocialAccountTokenJob;
use App\Models\OAuthProviderSetting;
use App\Models\SocialAccount;
use App\Models\SocialPage;
use App\Models\User;
use App\Services\ContextLogger;
use App\Support\Billing\OrganizationEntitlements;
use App\Support\Platform\PlatformAuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

class SocialAccountController extends Controller
{
    public function __construct(
        private readonly SocialOAuthManager $oauthManager,
        private readonly PlatformAuditLogger $auditLogger,
    ) {}

    // Code-quality review (2026-08-17), item A1/5.1: public (was private)
    // so the SocialAccount Form Request classes (app/Http/Requests/SocialAccount/)
    // can validate against the same single source of truth instead of
    // duplicating this list.
    public const PROVIDERS = [
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

    // 2026-08-12: only Facebook has an official mobile SDK wired up
    // (flutter_facebook_auth, Android/iOS only) — every other provider,
    // including WhatsApp (which also authenticates via Facebook Login
    // under the hood), still connects through authorize()/callback() above.
    public const NATIVE_SDK_PROVIDERS = ['facebook'];

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

    public function show(User $user, SocialAccount $socialAccount): JsonResponse
    {
        $this->authorize('view', $socialAccount);

        return response()->json([
            'data' => $this->transform($socialAccount),
        ]);
    }

    public function update(UpdateSocialAccountRequest $request, User $user, SocialAccount $socialAccount): JsonResponse
    {
        $this->authorize('update', $socialAccount);

        $validated = $request->validated();

        // Audit trail records only non-secret field names/values — never
        // access_token/refresh_token, matching SystemSettingsController's
        // own precedent for secrets.
        $auditableFields = ['account_name', 'account_username', 'status', 'is_active'];
        $oldValues = $socialAccount->only($auditableFields);

        $validated['last_synced_at'] = now();

        $socialAccount->update($validated);

        $this->auditLogger->record(
            $request,
            $user,
            'social_account.updated',
            SocialAccount::class,
            $socialAccount->id,
            $oldValues,
            $socialAccount->fresh()->only($auditableFields),
            (int) $socialAccount->organization_id,
        );

        return response()->json([
            'message' => 'Social account updated successfully.',
            'data' => $this->transform($socialAccount->fresh()),
        ]);
    }

    public function destroy(Request $request, User $user, SocialAccount $socialAccount): JsonResponse
    {
        $this->authorize('delete', $socialAccount);

        $auditPayload = [
            'provider' => $socialAccount->provider,
            'account_name' => $socialAccount->account_name,
        ];
        $organizationId = (int) $socialAccount->organization_id;
        $socialAccountId = $socialAccount->id;

        $socialAccount->delete();

        $this->auditLogger->record(
            $request,
            $user,
            'social_account.deleted',
            SocialAccount::class,
            $socialAccountId,
            $auditPayload,
            null,
            $organizationId,
        );

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

    public function testConnection(Request $request, User $user, SocialAccount $socialAccount): JsonResponse
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

        $this->auditLogger->record(
            $request,
            $user,
            'social_account.tested',
            SocialAccount::class,
            $socialAccount->id,
            null,
            ['available' => $result['available'], 'healthy' => $result['healthy']],
            (int) $socialAccount->organization_id,
        );

        return response()->json([
            'data' => [
                'available' => $result['available'],
                'healthy' => $result['healthy'],
                'message' => $result['message'],
            ],
        ]);
    }

    public function beginOAuthAuthorization(BeginOAuthAuthorizationRequest $request, User $user): JsonResponse
    {
        $organizationId = $this->authorizeTargetUserCapability(
            $request,
            $user,
            OrganizationPermission::SocialAccountsConnect,
        );

        $validated = $request->validated();

        if ($response = $this->rejectMockProvider($validated['provider'])) {
            return $response;
        }

        if ($response = $this->rejectDisabledProvider($validated['provider'])) {
            return $response;
        }

        $state = Str::random(48);
        $ttlMinutes = (int) config('social.oauth_state_ttl_minutes', 15);
        $stateExpiresAt = now()->addMinutes($ttlMinutes);

        // X's OAuth 2.0 authorization-code flow requires PKCE — the
        // verifier is a secret that must never appear in the authorize URL
        // (only its SHA-256 challenge does) and must be produced back at
        // token-exchange time, so it's generated here (server-side, not
        // client-side — Flutter never sees it) and cached alongside
        // redirect_uri below, keyed by the same single-use state. See
        // XOAuthProvider's own docblock for why this lives in the
        // controller rather than the provider class.
        $codeVerifier = null;
        $codeChallenge = null;
        if ($validated['provider'] === 'x') {
            $codeVerifier = Str::random(64);
            $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        }

        $authorizeUrl = $this->oauthManager->authorizeUrl($validated['provider'], [
            'redirect_uri' => $validated['redirect_uri'],
            'state' => $state,
            'scopes' => $validated['scopes'] ?? [],
            'code_challenge' => $codeChallenge,
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
            'code_verifier' => $codeVerifier,
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

    public function callback(CompleteOAuthCallbackRequest $request, User $user): JsonResponse
    {
        $organizationId = $this->authorizeTargetUserCapability(
            $request,
            $user,
            OrganizationPermission::SocialAccountsConnect,
        );

        $validated = $request->validated();

        if ($response = $this->rejectMockProvider($validated['provider'])) {
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
            'code_verifier' => $pending['code_verifier'] ?? null,
        ]);

        return $this->persistOAuthConnection(
            $request,
            $user,
            $validated['provider'],
            $tokenPayload,
            $organizationId,
            'oauth.callback.connected',
        );
    }

    /**
     * Android/iOS only: the mobile app's flutter_facebook_auth flow already
     * completed a real native Facebook Login and hands this endpoint an
     * access token directly — there's no ?code= to exchange, and unlike
     * callback() above there's no CSRF-state handshake to check either,
     * since nothing here ever redirected through our own authorize() URL.
     * What replaces both of those protections: SocialOAuthManager::
     * verifyNativeToken() independently re-verifies the token with Meta's
     * own /debug_token endpoint server-side — confirming it's genuinely
     * valid *and* was minted for this exact app — before anything is
     * trusted or persisted. Never accept a client-asserted token at face
     * value.
     */
    public function nativeConnect(NativeConnectRequest $request, User $user): JsonResponse
    {
        $organizationId = $this->authorizeTargetUserCapability(
            $request,
            $user,
            OrganizationPermission::SocialAccountsConnect,
        );

        $validated = $request->validated();

        if ($response = $this->rejectDisabledProvider($validated['provider'])) {
            return $response;
        }

        try {
            $tokenPayload = $this->oauthManager->verifyNativeToken($validated['provider'], $validated['access_token']);
        } catch (RuntimeException $e) {
            ContextLogger::warning('oauth.native_connect.rejected', [
                'user_id' => $user->id,
                'provider' => $validated['provider'],
                'reason' => $e->getMessage(),
            ], $request);

            return response()->json([
                'message' => 'Facebook rejected this sign-in: '.$e->getMessage(),
                'code' => 'native_token_invalid',
            ], 422);
        }

        return $this->persistOAuthConnection(
            $request,
            $user,
            $validated['provider'],
            $tokenPayload,
            $organizationId,
            'oauth.native_connect.connected',
        );
    }

    /**
     * Shared by callback() (web/desktop authorization-code flow) and
     * nativeConnect() (mobile SDK flow) — both end up with an identical
     * token payload shape (see FacebookOAuthProvider::exchangeCodeForToken /
     * ::verifyNativeToken) and must persist/log/audit it identically; only
     * the log event name differs, so the caller can tell the two paths
     * apart later.
     *
     * @param  array<string, mixed>  $tokenPayload
     */
    private function persistOAuthConnection(
        Request $request,
        User $user,
        string $provider,
        array $tokenPayload,
        int $organizationId,
        string $logEvent,
    ): JsonResponse {
        $linked = SocialAccount::query()->updateOrCreate(
            [
                'provider' => $provider,
                'provider_account_id' => (string) ($tokenPayload['provider_account_id'] ?? 'acc_'.Str::random(12)),
            ],
            [
                'user_id' => $user->id,
                // WhatsApp is 'auto' too: once a Business ID is set (see
                // SocialAccountController::update()), listPages() discovers
                // real phone numbers the same way Facebook discovers Pages —
                // the one manual step is a one-time input, not an ongoing
                // per-page workflow like Telegram's addPage()/verifyChat().
                // X is 'auto' as well: XOAuthProvider::listPages() always
                // returns exactly one synthetic "profile" target with
                // nothing further to configure, unlike Telegram's channel
                // add/verify flow.
                'discovery_mode' => in_array($provider, ['facebook', 'instagram', 'whatsapp', 'x'], true) ? 'auto' : 'manual',
                'account_name' => $tokenPayload['account_name'] ?? null,
                'account_username' => $tokenPayload['account_username'] ?? null,
                'access_token' => $tokenPayload['access_token'] ?? null,
                'refresh_token' => $tokenPayload['refresh_token'] ?? null,
                'token_expires_at' => $tokenPayload['expires_at'] ?? null,
                'scopes' => $tokenPayload['scopes'] ?? [],
                'metadata' => $tokenPayload['metadata'] ?? [],
                'status' => 'connected',
                'is_active' => true,
                'last_synced_at' => now(),
            ]
        );

        ContextLogger::info($logEvent, [
            'user_id' => $user->id,
            'provider' => $provider,
            'social_account_id' => $linked->id,
        ], $request);

        $this->auditLogger->record(
            $request,
            $user,
            'social_account.connected',
            SocialAccount::class,
            $linked->id,
            null,
            ['provider' => $linked->provider, 'account_name' => $linked->account_name],
            $organizationId,
        );

        return response()->json([
            'message' => 'Social account connected successfully via OAuth.',
            'data' => $this->transform($linked),
        ]);
    }

    public function setStatus(SetSocialAccountStatusRequest $request, User $user, SocialAccount $socialAccount): JsonResponse
    {
        $this->authorize('changeStatus', $socialAccount);

        $validated = $request->validated();

        $oldStatus = $socialAccount->status;

        $socialAccount->update([
            'status' => $validated['status'],
            'is_active' => $validated['is_active'] ?? $socialAccount->is_active,
            'last_synced_at' => now(),
        ]);

        // "Disconnect" in this system is a status change (e.g. -> revoked),
        // not a row deletion — see SocialAccountPolicy::changeStatus()'s
        // docblock. Logged as such rather than a generic "updated" so the
        // audit trail distinguishes it from an account_name/username edit.
        $this->auditLogger->record(
            $request,
            $user,
            'social_account.status_changed',
            SocialAccount::class,
            $socialAccount->id,
            ['status' => $oldStatus],
            ['status' => $validated['status']],
            (int) $socialAccount->organization_id,
        );

        return response()->json([
            'message' => 'Social account status updated successfully.',
            'data' => $this->transform($socialAccount->fresh()),
        ]);
    }

    public function providers(Request $request): JsonResponse
    {
        $this->authorizeOrganizationCapability($request, OrganizationPermission::SocialAccountsView);

        // 'providers' (list of ids) kept for existing callers. 'catalog'
        // (Sprint C) is the honest-capability version Flutter should
        // actually branch its UI on — is_mock_integration/is_beta_available/
        // is_enabled read straight from SocialOAuthManager/OAuthProviderSetting
        // instead of a hand-maintained list duplicated client-side (the exact
        // drift risk platform_label.dart's own docblock flagged).
        return response()->json([
            'providers' => $this->oauthManager->catalogProviders(),
            'catalog' => array_map(
                fn (string $provider): array => [
                    'provider' => $provider,
                    'is_mock_integration' => $this->oauthManager->isMockProvider($provider),
                    'is_beta_available' => $this->oauthManager->isClosedBetaProvider($provider),
                    'is_enabled' => OAuthProviderSetting::isEnabled($provider),
                ],
                self::PROVIDERS,
            ),
        ]);
    }

    public function connectTelegramBot(ConnectTelegramBotRequest $request, User $user): JsonResponse
    {
        $this->authorizeTargetUserCapability($request, $user, OrganizationPermission::SocialAccountsConnect);

        $validated = $request->validated();

        if ($response = $this->rejectDisabledProvider('telegram')) {
            return $response;
        }

        try {
            $bot = app(TelegramProvider::class)->verifyBotToken($validated['bot_token']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $alreadyLinked = SocialAccount::query()
            ->where('provider', 'telegram')
            ->where('provider_account_id', (string) ($bot['id'] ?? ''))
            ->exists();

        if (! $alreadyLinked && ($response = $this->rejectOverSocialAccountQuota())) {
            return $response;
        }

        // `provider`+`provider_account_id` is unique platform-wide (one bot
        // identity can only ever be linked once, across every organization),
        // but the query above (and updateOrCreate's own lookup) is
        // implicitly scoped to the caller's current organization via
        // SocialAccount's OrganizationScope — so a bot already connected to
        // a *different* organization is invisible to that lookup, and the
        // subsequent INSERT collides with the real DB constraint instead.
        // Reproduced live 2026-08-16: surfaced as an uncaught 500 with no
        // indication of the real cause. Same SQLSTATE-23000 guard pattern
        // as PostController::store()/MediaLibraryController::store()'s
        // idempotency-key race handling.
        try {
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
        } catch (QueryException $e) {
            if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'provider_account_id')) {
                return response()->json([
                    'message' => 'This Telegram bot is already connected to a different organization on this platform. Disconnect it there first, or connect a different bot.',
                    'code' => 'social_account_already_linked_elsewhere',
                ], 422);
            }

            throw $e;
        }

        $wasUpdate = ! $account->wasRecentlyCreated;

        ContextLogger::info($wasUpdate ? 'social.telegram.bot.updated' : 'social.telegram.bot.connected', [
            'user_id' => $user->id,
            'social_account_id' => $account->id,
        ], $request);

        $this->registerTelegramWebhook($account, $validated['bot_token'], $request);

        $this->auditLogger->record(
            $request,
            $user,
            $wasUpdate ? 'social_account.updated' : 'social_account.connected',
            SocialAccount::class,
            $account->id,
            null,
            ['provider' => 'telegram', 'account_name' => $account->account_name],
            (int) $account->organization_id,
        );

        return response()->json([
            'message' => $wasUpdate
                ? 'Telegram bot updated successfully.'
                : 'Telegram bot connected successfully.',
            'data' => $this->transform($account),
        ], $wasUpdate ? 200 : 201);
    }

    /**
     * Phase 3 (webhook receiver, 2026-08-16): best-effort by design. Telegram
     * refuses setWebhook against anything but a public HTTPS URL, which
     * local/dev APP_URLs never are — that must never fail the bot connect
     * this is called from; the periodic `oauth-providers:health-check`
     * poll and the synchronous sendMessage result remain the source of
     * truth either way, this is purely an earlier-signal enhancement. A
     * fresh secret is (re)issued on every connect/reconnect, matching the
     * OAuth scope-change precedent noted in config/social.php: rotating it
     * here is strictly safer than reusing whatever was set previously.
     */
    private function registerTelegramWebhook(SocialAccount $account, string $botToken, Request $request): void
    {
        $appUrl = rtrim((string) config('app.url'), '/');
        if (! str_starts_with($appUrl, 'https://')) {
            return;
        }

        $secret = Str::random(48);
        $callbackUrl = $appUrl.'/api/v1/webhooks/telegram/'.$account->id;

        try {
            $registered = app(TelegramProvider::class)->registerWebhook($botToken, $callbackUrl, $secret);
        } catch (\Throwable $e) {
            ContextLogger::warning('social.telegram.webhook.register_failed', [
                'social_account_id' => $account->id,
                'error' => $e->getMessage(),
            ], $request);

            return;
        }

        if (! $registered) {
            ContextLogger::warning('social.telegram.webhook.register_failed', [
                'social_account_id' => $account->id,
            ], $request);

            return;
        }

        $account->update(['webhook_secret' => $secret]);
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

    public function addPage(AddSocialPageRequest $request, User $user, SocialAccount $socialAccount): JsonResponse
    {
        $this->authorize('syncPages', $socialAccount);

        $validated = $request->validated();

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

    public function syncPages(Request $request, User $user, SocialAccount $socialAccount, SocialPageSyncService $syncService): JsonResponse
    {
        $this->authorize('syncPages', $socialAccount);

        if ($response = $this->rejectDisabledProvider($socialAccount->provider)) {
            return $response;
        }

        $result = $socialAccount->isAutoDiscovery()
            ? $syncService->syncAuto($socialAccount)
            : $syncService->syncManual($socialAccount);

        $this->auditLogger->record(
            $request,
            $user,
            'social_account.pages_synced',
            SocialAccount::class,
            $socialAccount->id,
            null,
            $result,
            (int) $socialAccount->organization_id,
        );

        return response()->json([
            'message' => $socialAccount->isAutoDiscovery() ? 'Pages synced.' : 'Channels re-verified.',
            'result' => $result,
            'data' => $socialAccount->pages()->get()->map(fn (SocialPage $page): array => $this->transformPage($page)),
        ]);
    }

    public function destroyPage(User $user, SocialAccount $socialAccount, SocialPage $socialPage): JsonResponse
    {
        $this->authorize('syncPages', $socialAccount);

        if ($socialPage->social_account_id !== $socialAccount->id) {
            return response()->json(['message' => 'Page does not belong to this account.'], 404);
        }

        $socialPage->delete();

        return response()->json([
            'message' => 'Page removed successfully.',
        ]);
    }

    public function selectPages(SelectSocialPagesRequest $request, User $user, SocialAccount $socialAccount): JsonResponse
    {
        $this->authorize('selectPages', $socialAccount);

        $validated = $request->validated();

        $socialAccount->pages()->update(['is_selected' => false]);
        $socialAccount->pages()->whereIn('id', $validated['page_ids'])->update(['is_selected' => true]);

        $this->auditLogger->record(
            $request,
            $user,
            'social_pages.selected',
            SocialAccount::class,
            $socialAccount->id,
            null,
            ['page_ids' => $validated['page_ids']],
            (int) $socialAccount->organization_id,
        );

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

    /**
     * Sprint C (role/permission remediation, 2026-08-09): previously this
     * only rejected a mock/not-yet-closed-beta provider in `production`,
     * relying on Flutter's client-side isMockBackedPlatform() list to hide
     * the option everywhere else — meaning any direct API caller in dev/
     * staging/testing could "connect" a fake instagram/x/linkedin/etc.
     * account with a self-supplied token. Rejects unconditionally now, in
     * every environment. WhatsApp, Instagram, and X are deliberately NOT
     * rejected here: each has a real, working connector (WhatsAppProvider,
     * InstagramProvider, XOAuthProvider) — WhatsApp's remaining gap
     * (publishPost() unimplemented) and X's (not yet production-approved)
     * are both enforced elsewhere (ClosedBetaPublishingGate at publish
     * time, isClosedBetaProvider() in production), not at connect time.
     */
    private function rejectMockProvider(string $provider): ?JsonResponse
    {
        if (! $this->oauthManager->isMockProvider($provider)) {
            return null;
        }

        return response()->json([
            'message' => ucfirst($provider).' is not available yet. It has no live publishing integration.',
            'code' => 'provider_not_available',
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

    /**
     * Sprint 4 (Commercial SaaS): same OrganizationEntitlements pattern as
     * OrganizationMembershipController/PostController — a no-op for any
     * organization with no subscription row. Callers must only invoke this
     * for a genuinely NEW connection (an existing (provider,
     * provider_account_id) being re-synced/re-authorized never counts
     * against the quota a second time).
     */
    private function rejectOverSocialAccountQuota(): ?JsonResponse
    {
        $organizationId = app(TenantContext::class)->get();
        $currentCount = SocialAccount::query()->count();

        if (app(OrganizationEntitlements::class)->hasCapacityFor($organizationId, 'max_social_accounts', $currentCount)) {
            return null;
        }

        return response()->json([
            'message' => 'Your organization has reached its connected social account limit for the current plan.',
            'code' => 'social_account_quota_exceeded',
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
