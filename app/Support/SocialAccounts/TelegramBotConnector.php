<?php

namespace App\Support\SocialAccounts;

use App\Exceptions\Api\ApiException;
use App\Infrastructure\ExternalServices\SocialOAuth\TelegramProvider;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\ContextLogger;
use App\Support\Billing\OrganizationEntitlements;
use App\Support\Platform\PlatformAuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Code-quality review (2026-08-17), item A4/5.2: extracted from
 * SocialAccountController::connectTelegramBot() (92 lines, mixing HTTP
 * glue with the actual Telegram-verification/persistence/webhook/audit
 * business logic) — following the same App\Support\{Domain}\{Name}Service
 * convention already established elsewhere (e.g.
 * Support\Organizations\OrganizationOwnershipService,
 * Support\Auth\TwoFactorAuthenticationService). No Actions/ directory
 * exists in this codebase to match instead — this mirrors the real
 * existing convention rather than introducing a new one.
 *
 * Throws App\Exceptions\Api\ApiException for the two "expected, tell the
 * caller" failure cases (quota exceeded, bot already linked to a different
 * organization) — this is the same exception class bootstrap/app.php's
 * global exception handler already renders as the exact
 * {success, message, data, meta, errors} envelope every other API error
 * uses, so the controller doesn't need its own try/catch translation; it
 * propagates and renders automatically. Both error arrays intentionally
 * repeat 'message' inside 'errors' (not just 'code') to reproduce
 * ApiEnvelopeMiddleware's pre-existing fallback shape byte-for-byte for a
 * plain response()->json(['message' => ..., 'code' => ...], 422) — the
 * exact shape PlansAndQuotasSprint4Test/SocialPageTest already assert via
 * assertJsonPath('errors.code', ...).
 *
 * verifyBotToken() still throws a bare RuntimeException on an invalid
 * token, unchanged from before this extraction — SocialAccountController
 * catches that one specifically (not ApiException) because its message is
 * Telegram's own, already-safe rejection reason with no fixed 'code' to
 * key on, matching the original inline behavior exactly.
 */
class TelegramBotConnector
{
    public function __construct(
        private readonly TelegramProvider $telegramProvider,
        private readonly OrganizationEntitlements $entitlements,
        private readonly PlatformAuditLogger $auditLogger,
    ) {}

    /**
     * @throws RuntimeException if Telegram rejects the bot token itself
     * @throws ApiException     if the organization is over its social-account
     *                          quota, or this bot is already linked to a
     *                          different organization on the platform
     */
    public function connect(Request $request, User $user, string $botToken): TelegramBotConnectionResult
    {
        $bot = $this->telegramProvider->verifyBotToken($botToken);

        $alreadyLinked = SocialAccount::query()
            ->where('provider', 'telegram')
            ->where('provider_account_id', (string) ($bot['id'] ?? ''))
            ->exists();

        if (! $alreadyLinked) {
            $this->assertUnderSocialAccountQuota();
        }

        $account = $this->persistAccount($user, $bot, $botToken);
        $wasUpdate = ! $account->wasRecentlyCreated;

        ContextLogger::info($wasUpdate ? 'social.telegram.bot.updated' : 'social.telegram.bot.connected', [
            'user_id' => $user->id,
            'social_account_id' => $account->id,
        ], $request);

        $this->registerWebhook($account, $botToken, $request);

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

        return new TelegramBotConnectionResult($account, $wasUpdate);
    }

    /**
     * Sprint 4 (Commercial SaaS): same OrganizationEntitlements pattern as
     * OrganizationMembershipController/PostController — a no-op for any
     * organization with no subscription row. Callers must only invoke this
     * for a genuinely NEW connection (an existing (provider,
     * provider_account_id) being re-synced/re-authorized never counts
     * against the quota a second time) — connect() above already only
     * calls this when $alreadyLinked is false.
     */
    private function assertUnderSocialAccountQuota(): void
    {
        $organizationId = app(TenantContext::class)->get();
        $currentCount = SocialAccount::query()->count();

        if ($this->entitlements->hasCapacityFor($organizationId, 'max_social_accounts', $currentCount)) {
            return;
        }

        throw new ApiException(
            'Your organization has reached its connected social account limit for the current plan.',
            [
                'message' => 'Your organization has reached its connected social account limit for the current plan.',
                'code' => 'social_account_quota_exceeded',
            ],
            422,
        );
    }

    /**
     * `provider`+`provider_account_id` is unique platform-wide (one bot
     * identity can only ever be linked once, across every organization),
     * but the query in connect() above (and updateOrCreate's own lookup)
     * is implicitly scoped to the caller's current organization via
     * SocialAccount's OrganizationScope — so a bot already connected to a
     * *different* organization is invisible to that lookup, and the
     * subsequent INSERT collides with the real DB constraint instead.
     * Reproduced live 2026-08-16: surfaced as an uncaught 500 with no
     * indication of the real cause. Same SQLSTATE-23000 guard pattern as
     * PostController::store()/MediaLibraryController::store()'s
     * idempotency-key race handling.
     *
     * @param  array<string, mixed>  $bot
     */
    private function persistAccount(User $user, array $bot, string $botToken): SocialAccount
    {
        try {
            return SocialAccount::query()->updateOrCreate(
                [
                    'provider' => 'telegram',
                    'provider_account_id' => (string) ($bot['id'] ?? ''),
                ],
                [
                    'user_id' => $user->id,
                    'discovery_mode' => 'manual',
                    'account_name' => $bot['username'] ?? $bot['first_name'] ?? 'Telegram Bot',
                    'account_username' => isset($bot['username']) ? '@'.$bot['username'] : null,
                    'access_token' => $botToken,
                    'status' => 'connected',
                    'is_active' => true,
                    'last_synced_at' => now(),
                ]
            );
        } catch (QueryException $e) {
            if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'provider_account_id')) {
                throw new ApiException(
                    'This Telegram bot is already connected to a different organization on this platform. Disconnect it there first, or connect a different bot.',
                    [
                        'message' => 'This Telegram bot is already connected to a different organization on this platform. Disconnect it there first, or connect a different bot.',
                        'code' => 'social_account_already_linked_elsewhere',
                    ],
                    422,
                );
            }

            throw $e;
        }
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
    private function registerWebhook(SocialAccount $account, string $botToken, Request $request): void
    {
        $appUrl = rtrim((string) config('app.url'), '/');
        if (! str_starts_with($appUrl, 'https://')) {
            return;
        }

        $secret = Str::random(48);
        $callbackUrl = $appUrl.'/api/v1/webhooks/telegram/'.$account->id;

        try {
            $registered = $this->telegramProvider->registerWebhook($botToken, $callbackUrl, $secret);
        } catch (Throwable $e) {
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
}
