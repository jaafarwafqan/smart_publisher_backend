<?php

namespace App\Support\SocialAccounts;

use App\Infrastructure\ExternalServices\SocialOAuth\SocialOAuthManager;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Code-quality review (2026-08-17), item A4/5.2: extracted from
 * SocialAccountController::beginOAuthAuthorization() (74 lines, mixing
 * HTTP glue with the actual PKCE/state-handshake/cache business logic) —
 * same App\Support\{Domain}\{Name}Service convention as
 * TelegramBotConnector in this same directory (see that class's own
 * docblock for why this convention, not a new Actions/ pattern, was
 * chosen).
 *
 * stateCacheKey() stays public+static and is also used directly by
 * SocialAccountController::callback() (Cache::pull(), consuming what
 * initiate() below writes) — callback() itself was not one of the two
 * methods this batch item named for extraction, so it keeps its own
 * (already-thin) HTTP-glue shape unchanged; only the cache key format
 * needs to be shared, not the whole request/response flow.
 */
class SocialOAuthAuthorizationInitiator
{
    public function __construct(private readonly SocialOAuthManager $oauthManager) {}

    /**
     * @param  array<string, mixed>  $validated  the validated payload from
     *         BeginOAuthAuthorizationRequest — guaranteed at runtime by its
     *         rules() to contain 'provider' (string) and 'redirect_uri'
     *         (string), with an optional 'scopes' (list<string>)
     * @return array{provider: string, state: string, state_expires_at: \Illuminate\Support\Carbon, authorize_url: string}
     */
    public function initiate(User $user, array $validated, int $organizationId): array
    {
        $state = Str::random(48);
        $ttlMinutes = (int) config('social.oauth_state_ttl_minutes', 15);
        $stateExpiresAt = now()->addMinutes($ttlMinutes);

        // X's OAuth 2.0 authorization-code flow requires PKCE — the
        // verifier is a secret that must never appear in the authorize URL
        // (only its SHA-256 challenge does) and must be produced back at
        // token-exchange time, so it's generated here (server-side, not
        // client-side — Flutter never sees it) and cached alongside
        // redirect_uri below, keyed by the same single-use state. See
        // XOAuthProvider's own docblock for why this lives here rather
        // than the provider class.
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
        // SocialAccountController::callback()'s Cache::pull() consumes it
        // atomically — single-use by construction — and the TTL here is
        // the only expiry check needed, no separate timestamp column to
        // compare.
        Cache::put(self::stateCacheKey($validated['provider'], $state, $organizationId), [
            'user_id' => $user->id,
            'redirect_uri' => $validated['redirect_uri'],
            'code_verifier' => $codeVerifier,
        ], $stateExpiresAt);

        return [
            'provider' => $validated['provider'],
            'state' => $state,
            'state_expires_at' => $stateExpiresAt,
            'authorize_url' => $authorizeUrl,
        ];
    }

    public static function stateCacheKey(string $provider, string $state, int $organizationId): string
    {
        return 'oauth_state:'.$organizationId.':'.$provider.':'.$state;
    }
}
