<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\DTOs\AuthContractDTO;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuthResource;
use App\Models\User;
use App\Support\Auth\TokenPairIssuer;
use App\Support\Tenancy\TenantContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Sprint 4 (Commercial SaaS): public self-registration, per the user's
 * explicit Sprint 4 decision ("فعّل التسجيل الذاتي العام" — enable full
 * public self-registration, not invite-only). Creating the User with no
 * active TenantContext runs the exact same path every other fresh-account
 * creation already goes through — User::booted() calls
 * PersonalOrganizationProvisioner, which provisions a personal
 * organization AND subscribes it to the Free plan when one exists (see
 * PlansAndQuotasSprint4Test) — nothing registration-specific to duplicate
 * here. Auto-logs the new account in (same token pair shape as
 * AuthController::login()) so the app doesn't need a second round trip,
 * and sends the email verification link immediately.
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

        // A pre-auth route, same reasoning as AuthController::login().
        app(TenantContextResolver::class)->resolveAndSet($user);

        $user->sendEmailVerificationNotification();

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
        ]))->resolve(), 201);
    }
}
