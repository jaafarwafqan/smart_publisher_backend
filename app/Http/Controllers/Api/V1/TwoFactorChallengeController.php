<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\DTOs\AuthContractDTO;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuthResource;
use App\Models\User;
use App\Support\Auth\TokenPairIssuer;
use App\Support\Auth\TwoFactorAuthenticationService;
use App\Support\Tenancy\TenantContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Sprint 4 (Commercial SaaS): completes the login started by
 * AuthController::login() when the account has 2FA enabled — that method
 * stops short of issuing real tokens and instead hands back a short-lived
 * challenge_token identifying the pending login. Deliberately not behind
 * auth:sanctum: the user isn't fully authenticated until this step
 * succeeds, so the challenge_token itself (opaque, single-use, 5-minute
 * TTL) is what stands in for a session here.
 */
class TwoFactorChallengeController extends Controller
{
    private const CACHE_PREFIX = '2fa-challenge:';

    public function __construct(private readonly TwoFactorAuthenticationService $twoFactor) {}

    public static function cacheKey(string $challengeToken): string
    {
        return self::CACHE_PREFIX.$challengeToken;
    }

    public function challenge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge_token' => ['required', 'string'],
            'code' => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        if (empty($validated['code']) && empty($validated['recovery_code'])) {
            return response()->json(['message' => 'Either a code or a recovery_code is required.'], 422);
        }

        $userId = Cache::get(self::cacheKey($validated['challenge_token']));

        if ($userId === null) {
            return response()->json(['message' => 'Invalid or expired two-factor challenge.'], 401);
        }

        $user = User::query()->find($userId);

        if (! $user instanceof User || ! $user->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'Invalid or expired two-factor challenge.'], 401);
        }

        if (! $this->verifyCodeOrRecoveryCode($user, $validated)) {
            return response()->json(['message' => 'Invalid authentication code.'], 401);
        }

        // Single use: a challenge_token (and any recovery code it was
        // redeemed with) must not work a second time.
        Cache::forget(self::cacheKey($validated['challenge_token']));

        app(TenantContextResolver::class)->resolveAndSet($user);

        $tokenPayload = app(TokenPairIssuer::class)->issue($user, $validated['device_name'] ?? 'flutter-app');

        $authDto = new AuthContractDTO(
            message: 'Login successful.',
            accessToken: $tokenPayload['access_token'],
            refreshToken: $tokenPayload['refresh_token'],
            expiresIn: $tokenPayload['expires_in'],
            tokenType: 'Bearer',
            scope: $tokenPayload['scope'],
            roles: $user->getRoleNames()->values()->all(),
            permissions: $user->getAllPermissions()->pluck('name')->values()->all(),
        );

        return response()->json((new AuthResource([
            'message' => $authDto->message,
            'access_token' => $authDto->accessToken,
            'refresh_token' => $authDto->refreshToken,
            'expires_in' => $authDto->expiresIn,
            'token_type' => $authDto->tokenType,
            'scope' => $authDto->scope,
            'user' => $user->load(['branch:id,name,code', 'roles:id,name,guard_name', 'socialAccounts']),
            'roles' => $authDto->roles,
            'permissions' => $authDto->permissions,
        ]))->resolve());
    }

    /**
     * @param  array{code?: string|null, recovery_code?: string|null}  $validated
     */
    private function verifyCodeOrRecoveryCode(User $user, array $validated): bool
    {
        if (! empty($validated['code'])) {
            return $this->twoFactor->verify((string) $user->two_factor_secret, $validated['code']);
        }

        $recoveryCodes = $user->two_factor_recovery_codes ?? [];
        $submitted = mb_strtoupper(trim((string) $validated['recovery_code']));
        $index = array_search($submitted, $recoveryCodes, true);

        if ($index === false) {
            return false;
        }

        // One-time use: consumed recovery codes are removed immediately so
        // the same leaked code can't be replayed.
        unset($recoveryCodes[$index]);
        $user->forceFill(['two_factor_recovery_codes' => array_values($recoveryCodes)])->save();

        return true;
    }
}
