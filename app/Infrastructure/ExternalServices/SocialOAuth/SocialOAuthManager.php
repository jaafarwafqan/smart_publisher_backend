<?php

namespace App\Infrastructure\ExternalServices\SocialOAuth;

use App\Infrastructure\ExternalServices\Contracts\SocialOAuthProviderContract;
use App\Models\OAuthProviderSetting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class SocialOAuthManager
{
    /**
     * 'instagram' joined 2026-08 after InstagramProvider's real Content
     * Publishing API implementation was live-verified — same graduation
     * Facebook/Telegram went through. 'x' deliberately has not: XOAuthProvider
     * is real code with full automated coverage, but posting write access
     * needs a paid X API tier that hasn't been live-verified against a real
     * account yet (see XOAuthProvider's docblock).
     *
     * @var list<string>
     */
    private const CLOSED_BETA_PROVIDERS = ['facebook', 'telegram', 'instagram'];

    /** @var list<string> */
    private const CATALOG_PROVIDERS = [
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

    /**
     * The public provider catalog is deliberately release-aware. Production
     * must not advertise internal or mocked integrations as connectable;
     * local and staging retain the complete development catalog.
     *
     * @return list<string>
     */
    public function catalogProviders(): array
    {
        return app()->environment('production')
            ? self::CLOSED_BETA_PROVIDERS
            : self::CATALOG_PROVIDERS;
    }

    public function provider(string $provider): SocialOAuthProviderContract
    {
        $provider = strtolower($provider);

        // The admin-facing is_enabled toggle (System Settings) was purely
        // cosmetic before this check — it changed what the UI displayed but
        // never actually stopped authorize/connect/refresh/publish from
        // reaching the real provider. Every one of those flows funnels
        // through this method (directly or via listPages()/testConnection()
        // on the returned instance), so gating here is the single choke
        // point that covers all of them.
        if (! OAuthProviderSetting::isEnabled($provider)) {
            throw new RuntimeException(
                "The {$provider} integration is currently disabled by an administrator."
            );
        }

        if (app()->environment('production') && ! $this->isClosedBetaProvider($provider)) {
            if ($this->isMockProvider($provider)) {
                throw new RuntimeException(
                    "The {$provider} integration is not available in production because it has no live provider implementation."
                );
            }

            throw new RuntimeException(
                "The {$provider} integration is not enabled for the current closed beta release."
            );
        }

        if ($this->isMockProvider($provider)) {
            return new GenericOAuthProvider;
        }

        return match ($provider) {
            'facebook' => new FacebookOAuthProvider(Http::getFacadeRoot()),
            'telegram' => new TelegramProvider(Http::getFacadeRoot()),
            'whatsapp' => new WhatsAppProvider(
                new FacebookOAuthProvider(Http::getFacadeRoot()),
                Http::getFacadeRoot()
            ),
            'instagram' => new InstagramProvider(
                new FacebookOAuthProvider(Http::getFacadeRoot()),
                Http::getFacadeRoot()
            ),
            'x' => new XOAuthProvider(Http::getFacadeRoot()),
            default => throw new InvalidArgumentException('Unsupported social provider.'),
        };
    }

    /**
     * Single source of truth for whether a provider is really wired up to an
     * external platform or is currently served by GenericOAuthProvider's
     * hardcoded mock (CTO audit P0-5: several platforms were presented to
     * users as connected/published when no real HTTP call was ever made).
     * Clients (Flutter, the OAuth Provider Settings admin screen) must read
     * this instead of assuming capability locally.
     *
     * 'instagram' and 'x' both graduated out of this list in 2026-08 —
     * InstagramProvider and XOAuthProvider make real API calls now, the same
     * way WhatsAppProvider already did before either of them. Being real is
     * independent of being production-approved: see CLOSED_BETA_PROVIDERS
     * and isClosedBetaProvider() for that separate gate, which 'x' (unlike
     * 'instagram') deliberately has not joined yet — its write access needs
     * a paid X API tier that hasn't been live-verified.
     */
    public function isMockProvider(string $provider): bool
    {
        return in_array(strtolower($provider), [
            'linkedin',
            'youtube',
            'tiktok',
            'snapchat',
            'pinterest',
            'other',
        ], true);
    }

    public function isClosedBetaProvider(string $provider): bool
    {
        return in_array(strtolower($provider), self::CLOSED_BETA_PROVIDERS, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function providerConfig(string $provider): array
    {
        $provider = strtolower($provider);
        $config = config('social.providers.'.$provider, []);

        $override = OAuthProviderSetting::query()->where('provider', $provider)->first();
        if ($override) {
            foreach (['client_id', 'client_secret', 'authorize_url', 'token_url'] as $key) {
                if (! empty($override->{$key})) {
                    $config[$key] = $override->{$key};
                }
            }
            if (! empty($override->default_scopes)) {
                $config['default_scopes'] = $override->default_scopes;
            }
        }

        if (! is_array($config) || empty($config)) {
            throw new InvalidArgumentException('Provider configuration is missing.');
        }

        return $config;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function authorizeUrl(string $provider, array $context): string
    {
        $providerConfig = $this->providerConfig($provider);
        $context['provider_config'] = $providerConfig;

        return $this->provider($provider)->buildAuthorizeUrl($context);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function exchangeCode(string $provider, string $code, array $context): array
    {
        $providerConfig = $this->providerConfig($provider);
        $context['provider_config'] = $providerConfig;
        $context['default_ttl_minutes'] = Arr::get($context, 'default_ttl_minutes', (int) config('social.default_token_ttl_minutes', 60));

        return $this->provider($provider)->exchangeCodeForToken($code, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function refreshToken(string $provider, string $refreshToken, array $context): array
    {
        $providerConfig = $this->providerConfig($provider);
        $context['provider_config'] = $providerConfig;
        $context['default_ttl_minutes'] = Arr::get($context, 'default_ttl_minutes', (int) config('social.default_token_ttl_minutes', 60));

        return $this->provider($provider)->refreshAccessToken($refreshToken, $context);
    }

    /**
     * Android/iOS-only mobile SDK sign-in (flutter_facebook_auth) — the app
     * hands back a real Facebook access token directly instead of a ?code=
     * to exchange, so there's no generic OAuth-code flow to funnel this
     * through via provider(). Deliberately not added to
     * SocialOAuthProviderContract: this is a Facebook-specific capability
     * (2026-08-12 scope), and every other provider would need to either
     * fake an implementation or the interface would need an
     * availability-flag escape hatch for something that, unlike
     * testConnection()/checkAccountHealth()/fetchPostMetrics(), doesn't
     * have a meaningful "not available" answer to give — it either connects
     * an account or it doesn't apply.
     *
     * @return array<string, mixed>
     */
    public function verifyNativeToken(string $provider, string $accessToken): array
    {
        $provider = strtolower($provider);

        if ($provider !== 'facebook') {
            throw new InvalidArgumentException("Native SDK sign-in is not supported for {$provider}.");
        }

        if (! OAuthProviderSetting::isEnabled($provider)) {
            throw new RuntimeException("The {$provider} integration is currently disabled by an administrator.");
        }

        return (new FacebookOAuthProvider(Http::getFacadeRoot()))->verifyNativeToken($accessToken, [
            'provider_config' => $this->providerConfig($provider),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function publishPost(string $provider, string $accessToken, array $context): array
    {
        $providerConfig = $this->providerConfig($provider);
        $context['provider_config'] = $providerConfig;

        return $this->provider($provider)->publishPost($accessToken, $context);
    }
}
