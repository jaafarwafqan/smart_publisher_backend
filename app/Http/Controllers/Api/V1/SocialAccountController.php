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
use App\Support\Platform\PlatformAuditLogger;
use App\Support\SocialAccounts\SocialOAuthAuthorizationInitiator;
use App\Support\SocialAccounts\TelegramBotConnector;
use App\Support\Tenancy\TenantContext;
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

    // Code-quality review (2026-08-17), item A4/5.2: was 74 lines mixing
    // HTTP glue with the actual PKCE/state-handshake/cache business logic
    // — extracted into SocialOAuthAuthorizationInitiator (app/Support/SocialAccounts/),
    // same convention as TelegramBotConnector's own extraction.
    public function beginOAuthAuthorization(
        BeginOAuthAuthorizationRequest $request,
        User $user,
        SocialOAuthAuthorizationInitiator $initiator,
    ): JsonResponse {
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

        $result = $initiator->initiate($user, $validated, $organizationId);

        ContextLogger::info('oauth.authorize.generated', [
            'user_id' => $user->id,
            'provider' => $result['provider'],
        ], $request);

        return response()->json([
            'message' => 'OAuth authorization URL generated.',
            'provider' => $result['provider'],
            'state' => $result['state'],
            'state_expires_at' => $result['state_expires_at'],
            'authorize_url' => $result['authorize_url'],
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
        $pending = Cache::pull(SocialOAuthAuthorizationInitiator::stateCacheKey($validated['provider'], $validated['state'], $organizationId));

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

    // Code-quality review (2026-08-17), item A4/5.2: was 92 lines mixing
    // HTTP glue (auth/validation/response shaping) with the real
    // verify/quota/persist/webhook/audit business logic — extracted into
    // TelegramBotConnector (app/Support/SocialAccounts/), matching this
    // codebase's existing Support/{Domain}/{Name}Service convention. The
    // one thing that stays here: verifyBotToken()'s bare RuntimeException
    // (an invalid token, not a fixed 'code' the caller can key on) is still
    // caught at the HTTP boundary, since translating it to a response is
    // exactly the controller's job — everything else the connector can
    // fail with is an ApiException, already rendered automatically by
    // bootstrap/app.php's global handler.
    public function connectTelegramBot(ConnectTelegramBotRequest $request, User $user, TelegramBotConnector $connector): JsonResponse
    {
        $this->authorizeTargetUserCapability($request, $user, OrganizationPermission::SocialAccountsConnect);

        $validated = $request->validated();

        if ($response = $this->rejectDisabledProvider('telegram')) {
            return $response;
        }

        try {
            $result = $connector->connect($request, $user, $validated['bot_token']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => $result->wasUpdate
                ? 'Telegram bot updated successfully.'
                : 'Telegram bot connected successfully.',
            'data' => $this->transform($result->account),
        ], $result->wasUpdate ? 200 : 201);
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
}
