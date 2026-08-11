<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\DTOs\AuthContractDTO;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuthResource;
use App\Models\User;
use App\Support\Auth\TokenPairIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Public self-registration creates a User account ONLY — no organization,
 * no membership, no role, no OrganizationPermission. This was reversed
 * from the original Sprint 4 (Commercial SaaS) behavior, which auto-
 * provisioned a personal organization with the new user as its Owner: per
 * the 2026-08-08 role/permission remediation decision, a self-registered
 * account must start with zero organizational access and can only gain any
 * until an existing owner/admin invites it to an organization (as
 * `viewer` by default — see OrganizationMembershipController::store()) or
 * a super_admin creates an organization for it (see
 * AdminOrganizationController::store()). withoutPersonalOrganizationProvisioning()
 * is the same guard AdminUserController::store()/AdminOrganizationController::store()
 * already use to create a user without triggering User::booted()'s
 * auto-provisioning.
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

        $user = User::withoutPersonalOrganizationProvisioning(fn (): User => User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => 'user',
        ]));

        // No TenantContext to resolve here — this account has no membership
        // yet by design (see the class docblock). TokenPairIssuer/AuthResource
        // below don't need one; authUserPayload-equivalent loading below
        // deliberately skips the tenant-scoped socialAccounts relation,
        // mirroring AuthController::hasActiveOrganization()'s guard.
        //
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

        return response()->json((new AuthResource([
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
    }
}
