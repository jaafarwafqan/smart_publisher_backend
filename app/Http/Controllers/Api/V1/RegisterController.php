<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\DTOs\AuthContractDTO;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuthResource;
use App\Models\User;
use App\Support\Auth\TokenPairIssuer;
use App\Support\Auth\WebTokenCookies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Public self-registration creates a complete tenant workspace. The User
 * model's created hook delegates to PersonalOrganizationProvisioner, which
 * atomically establishes the personal organization, its owner membership,
 * its Free subscription and the user's active organization. A successful
 * registration must never leave a customer able to authenticate but unable
 * to use any tenant-scoped product surface.
 */
class RegisterController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => 'user',
        ]);

        // Sent synchronously (ApiVerifyEmailNotification isn't ShouldQueue),
        // so a mail-provider outage/misconfiguration must not turn an
        // already-persisted account into a 500 for the client — the account
        // exists either way; only the verification email is best-effort.
        // authEmailVerificationResend lets the user retry once mail works.
        try {
            $user->sendEmailVerificationNotification();
        } catch (Throwable $e) {
            Log::warning('Registration verification email failed to send.', [
                'user_id' => $user->id,
                'exception' => $e->getMessage(),
            ]);
        }

        $tokenPayload = app(TokenPairIssuer::class)->issue($user, $validated['device_name'] ?? 'flutter-app');

        $authDto = new AuthContractDTO(
            message: 'Registration successful.',
            accessToken: $tokenPayload['access_token'],
            refreshToken: $tokenPayload['refresh_token'],
            expiresIn: $tokenPayload['expires_in'],
            tokenType: 'Bearer',
            scope: $tokenPayload['scope'],
            roles: $user->getRoleNames()->values()->all(),
            permissions: $user->getAllPermissions()->pluck('name')->values()->all(),
        );

        $response = response()->json((new AuthResource([
            'message' => $authDto->message,
            'access_token' => $authDto->accessToken,
            'refresh_token' => $authDto->refreshToken,
            'expires_in' => $authDto->expiresIn,
            'token_type' => $authDto->tokenType,
            'scope' => $authDto->scope,
            'user' => $user->load(['branch:id,name,code', 'roles:id,name,guard_name']),
            'roles' => $authDto->roles,
            'permissions' => $authDto->permissions,
        ]))->resolve(), 201);

        return app(WebTokenCookies::class)->attach($request, $response, $tokenPayload);
    }
}
