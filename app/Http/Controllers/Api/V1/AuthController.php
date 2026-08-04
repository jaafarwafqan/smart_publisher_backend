<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\DTOs\AuthContractDTO;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuthResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\Tenancy\TenantContextResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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

        // Login is a pre-auth route (no token exists yet to gate it behind
        // 'tenant' middleware), but the response below loads socialAccounts
        // — a tenant-scoped relation — so context must be established here
        // explicitly before touching it.
        app(TenantContextResolver::class)->resolveAndSet($user);

        $tokenPayload = $this->issueTokenPair($user, $validated['device_name'] ?? 'flutter-app');

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

        // Same reasoning as login() — refresh is also a pre-auth route.
        app(TenantContextResolver::class)->resolveAndSet($user);

        $refreshToken->delete();

        $tokenPayload = $this->issueTokenPair($user, $validated['device_name'] ?? 'flutter-app');

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
            'user' => $user->load(['branch:id,name,code', 'roles:id,name,guard_name', 'socialAccounts']),
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
            'user' => $user ? (new UserResource($user->load(['branch:id,name,code', 'roles:id,name,guard_name', 'socialAccounts'])))->resolve() : null,
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

    private function issueTokenPair(User $user, string $deviceName): array
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
