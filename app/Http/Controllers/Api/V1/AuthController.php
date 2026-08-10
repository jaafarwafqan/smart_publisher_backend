<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\DTOs\AuthContractDTO;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuthResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\Auth\TokenPairIssuer;
use App\Support\Tenancy\TenantContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['nullable', 'email'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $loginIdentifier = $validated['email'] ?? $validated['username'] ?? null;

        if (! $loginIdentifier) {
            return response()->json([
                'message' => 'The email or username field is required.',
            ], 422);
        }

        $userQuery = User::query();

        if (filter_var($loginIdentifier, FILTER_VALIDATE_EMAIL)) {
            $userQuery->where('email', $loginIdentifier);
        } else {
            $userQuery->where('name', $loginIdentifier)->orWhere('email', $loginIdentifier);
        }

        $user = $userQuery->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'This account is disabled.'], 403);
        }

        // Sprint 4 (Commercial SaaS): a password match alone is not enough
        // once 2FA is enabled — stop short of issuing real tokens or
        // resolving tenant context (the account isn't authenticated yet)
        // and hand back an opaque, single-use challenge_token instead.
        // TwoFactorChallengeController::challenge() completes the login.
        if ($user->hasTwoFactorEnabled()) {
            $challengeToken = Str::random(64);
            Cache::put(TwoFactorChallengeController::cacheKey($challengeToken), $user->id, now()->addMinutes(5));

            return response()->json([
                'message' => 'Two-factor authentication code required.',
                'two_factor_required' => true,
                'challenge_token' => $challengeToken,
            ]);
        }

        // Login is a pre-auth route (no token exists yet to gate it behind
        // 'tenant' middleware), but the response below loads socialAccounts
        // — a tenant-scoped relation — so context must be established here
        // explicitly before touching it.
        $this->prepareAuthContext($user);
        $user->forceFill(['last_login_at' => now()])->save();

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
            'user' => $this->authUserPayload($user),
            'roles' => $authDto->roles,
            'permissions' => $authDto->permissions,
        ]))->resolve());
    }

    public function refresh(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'refresh_token' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $refreshToken = PersonalAccessToken::findToken($validated['refresh_token']);

        if (! $refreshToken || ! str_starts_with($refreshToken->name, 'refresh-token:')) {
            return response()->json([
                'message' => 'Invalid refresh token.',
            ], 401);
        }

        if ($refreshToken->expires_at && $refreshToken->expires_at->isPast()) {
            $refreshToken->delete();

            return response()->json([
                'message' => 'Refresh token expired.',
            ], 401);
        }

        $user = $refreshToken->tokenable;

        if (! $user instanceof User) {
            return response()->json([
                'message' => 'Invalid refresh token.',
            ], 401);
        }

        if (! $user->is_active) {
            $refreshToken->delete();

            return response()->json(['message' => 'This account is disabled.'], 403);
        }

        // Same reasoning as login() — refresh is also a pre-auth route.
        $this->prepareAuthContext($user);
        $user->forceFill(['last_login_at' => now()])->save();

        $refreshToken->delete();

        $tokenPayload = app(TokenPairIssuer::class)->issue($user, $validated['device_name'] ?? 'flutter-app');

        $authDto = new AuthContractDTO(
            message: 'Token refreshed successfully.',
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
            'user' => $this->authUserPayload($user),
            'roles' => $authDto->roles,
            'permissions' => $authDto->permissions,
        ]))->resolve());
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        $currentToken = $request->user()?->currentAccessToken();

        if (! $currentToken) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        return response()->json([
            'user' => $user ? (new UserResource($this->authUserPayload($user)))->resolve() : null,
            'roles' => $user?->getRoleNames()->values() ?? [],
            'permissions' => $user?->getAllPermissions()->pluck('name')->values() ?? [],
            'access_token' => $request->bearerToken(),
            'refresh_token' => null,
            'expires_in' => $currentToken->expires_at?->diffInSeconds(now(), true),
            'scope' => $currentToken->abilities ? implode(' ', $currentToken->abilities) : null,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentToken = $user?->currentAccessToken();

        if (! $currentToken) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Access and refresh tokens for a session are named "access-token:{device}"
        // and "refresh-token:{device}"; revoke both so logout actually ends the
        // session on the server instead of leaving the refresh token usable.
        $deviceName = preg_replace('/^(access|refresh)-token:/', '', (string) $currentToken->name);

        $user->tokens()
            ->where(function ($query) use ($deviceName): void {
                $query->where('name', 'access-token:'.$deviceName)
                    ->orWhere('name', 'refresh-token:'.$deviceName);
            })
            ->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * A platform administrator may deliberately have no organization. Do not
     * resolve a tenant for that account and do not touch tenant-scoped social
     * relations while building its auth response.
     */
    private function prepareAuthContext(User $user): void
    {
        if ($this->hasActiveOrganization($user)) {
            app(TenantContextResolver::class)->resolveAndSet($user);
        }
    }

    private function authUserPayload(User $user): User
    {
        $relations = ['branch:id,name,code', 'roles:id,name,guard_name'];

        if ($this->hasActiveOrganization($user)) {
            $relations[] = 'socialAccounts';
        }

        return $user->load($relations);
    }

    /**
     * Sprint A (role/permission remediation): registration no longer
     * auto-provisions a personal organization (see RegisterController), so
     * a freshly registered account genuinely has zero memberships until an
     * owner/admin or super_admin adds it to one. Treat that exactly like the
     * pre-existing super-admin case — no tenant to resolve, no tenant-scoped
     * relation to load — rather than letting TenantContextResolver throw
     * NoOrganizationMembershipException on every login/me call.
     */
    private function hasActiveOrganization(User $user): bool
    {
        return ! $user->isSuperAdmin() && $user->memberships()->where('status', 'active')->exists();
    }
}
