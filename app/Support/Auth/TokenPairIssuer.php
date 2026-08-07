<?php

namespace App\Support\Auth;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Sprint 4 (Commercial SaaS): extracted from AuthController so the new
 * self-registration endpoint (RegisterController) can issue the exact same
 * access/refresh token pair a normal login does — auto-login right after
 * signup — without duplicating the token-issuing logic a second time.
 */
class TokenPairIssuer
{
    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int|float, scope: string}
     */
    public function issue(User $user, string $deviceName): array
    {
        $scope = ['*'];
        $accessTtlMinutes = (int) (config('sanctum.expiration') ?? 60);
        $accessExpiresAt = CarbonImmutable::now()->addMinutes(max($accessTtlMinutes, 1));
        $refreshExpiresAt = CarbonImmutable::now()->addDays((int) config('auth.refresh_token_days', 30));

        $accessToken = $user->createToken(
            'access-token:'.$deviceName,
            $scope,
            $accessExpiresAt
        );

        $refreshToken = $user->createToken(
            'refresh-token:'.$deviceName,
            $scope,
            $refreshExpiresAt
        );

        return [
            'access_token' => $accessToken->plainTextToken,
            'refresh_token' => $refreshToken->plainTextToken,
            'expires_in' => $accessExpiresAt->diffInSeconds(now(), true),
            'scope' => implode(' ', $scope),
        ];
    }
}
