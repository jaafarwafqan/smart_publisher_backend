<?php

namespace App\Support\Auth;

use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Sprint 4 (Commercial SaaS): thin wrapper around pragmarx/google2fa so the
 * controller doesn't talk to the TOTP library directly — mirrors the same
 * "one dedicated Support class per concern" pattern already used for
 * TokenPairIssuer, PersonalOrganizationProvisioner, etc.
 */
class TwoFactorAuthenticationService
{
    public function __construct(private readonly Google2FA $engine) {}

    public function generateSecretKey(): string
    {
        return $this->engine->generateSecretKey();
    }

    /**
     * The base pragmarx/google2fa package (unlike the separate -qrcode
     * add-on this app doesn't depend on) has no built-in URL builder — this
     * is the standard otpauth:// URI format every TOTP app (Google
     * Authenticator, Authy, 1Password, …) already knows how to scan or
     * import, so the Flutter client can render its own QR code from it
     * without this backend needing an image-generation dependency.
     */
    public function otpAuthUrl(string $companyName, string $email, string $secret): string
    {
        $label = rawurlencode($companyName.':'.$email);
        $issuer = rawurlencode($companyName);

        return "otpauth://totp/{$label}?secret={$secret}&issuer={$issuer}";
    }

    /**
     * A window of 4 (±4 * 30s steps, i.e. roughly ±2 minutes) tolerates
     * modest clock drift between the server and the user's authenticator
     * app without meaningfully weakening the code's 6-digit entropy.
     */
    public function verify(string $secret, string $code): bool
    {
        return $this->engine->verifyKey($secret, $code, 4) !== false;
    }

    /**
     * @return array<int, string>
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        return collect(range(1, $count))
            ->map(fn () => Str::upper(Str::random(10).'-'.Str::random(10)))
            ->all();
    }
}
