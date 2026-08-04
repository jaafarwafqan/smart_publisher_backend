<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $organizationId = app(TenantContext::class)->get();

        $users = User::query()
            ->whereHas('memberships', function ($query) use ($organizationId): void {
                $query->where('organization_id', $organizationId)->where('status', 'active');
            })
            ->with(['branch:id,name,code', 'roles:id,name,guard_name', 'socialAccounts'])
            ->latest()
            ->get();

        return response()->json([
            'data' => UserResource::collection($users)->resolve(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')->where('guard_name', 'sanctum')],
            'organization_role' => ['nullable', Rule::enum(OrganizationRole::class)],
        ]);

        if (! empty($validated['roles']) && ! $request->user()->can('roles.assign')) {
            abort(403, 'Assigning roles requires the roles.assign permission.');
        }

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'branch_id' => $validated['branch_id'] ?? null,
            'role' => $validated['roles'][0] ?? 'user',
        ]);

        if (! empty($validated['roles'])) {
            $user->syncRoles($validated['roles']);
        }

        // A user created here is being invited by an already-authenticated
        // admin (this route sits behind auth:sanctum + tenant), so
        // User::booted()'s "give every new user their own personal org"
        // auto-provisioning deliberately skips itself (TenantContext::has()
        // is true) — attach them to the INVITING admin's own organization
        // instead, which is what "invite a teammate" actually means.
        OrganizationMembership::query()->create([
            'organization_id' => app(TenantContext::class)->get(),
            'user_id' => $user->id,
            'role' => $validated['organization_role'] ?? OrganizationRole::Viewer,
            'status' => 'active',
        ]);
        $user->forceFill(['current_organization_id' => app(TenantContext::class)->get()])->save();

        return response()->json([
            'message' => 'User created successfully.',
            'data' => (new UserResource($user->load(['branch:id,name,code', 'roles:id,name,guard_name', 'socialAccounts'])))->resolve(),
        ], 201);
    }

    public function show(User $user): JsonResponse
    {
        $this->assertBelongsToCurrentOrganization($user);

        return response()->json([
            'data' => (new UserResource($user->load(['branch:id,name,code', 'roles:id,name,guard_name', 'permissions:id,name,guard_name', 'socialAccounts'])))->resolve(),
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->assertBelongsToCurrentOrganization($user);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['sometimes', 'string', 'min:8'],
            'branch_id' => ['nullable', 'exists:branches,id'],
        ]);

        if (array_key_exists('password', $validated)) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $user->update($validated);

        return response()->json([
            'message' => 'User updated successfully.',
            'data' => (new UserResource($user->load(['branch:id,name,code', 'roles:id,name,guard_name', 'socialAccounts'])))->resolve(),
        ]);
    }

    public function destroy(User $user): JsonResponse
    {
        $this->assertBelongsToCurrentOrganization($user);

        $user->tokens()->delete();
        $user->delete();

        return response()->json([
            'message' => 'User deleted successfully.',
        ]);
    }

    public function syncRoles(Request $request, User $user): JsonResponse
    {
        $this->assertBelongsToCurrentOrganization($user);

        $validated = $request->validate([
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::exists('roles', 'name')->where('guard_name', 'sanctum')],
        ]);

        $user->syncRoles($validated['roles']);
        $user->update([
            'role' => $validated['roles'][0],
        ]);

        return response()->json([
            'message' => 'Roles synced successfully.',
            'data' => $user->load(['roles:id,name,guard_name']),
        ]);
    }

    public function permissions(User $user): JsonResponse
    {
        $this->assertBelongsToCurrentOrganization($user);

        return response()->json([
            'roles' => $user->getRoleNames()->values(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
        ]);
    }

    public function assignDirectPermissions(Request $request, User $user): JsonResponse
    {
        $this->assertBelongsToCurrentOrganization($user);

        $validated = $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')->where('guard_name', 'sanctum')],
        ]);

        $user->syncPermissions($validated['permissions']);

        return response()->json([
            'message' => 'Direct permissions synced successfully.',
            'data' => $user->getDirectPermissions()->pluck('name')->values(),
        ]);
    }

    public function tokens(User $user): JsonResponse
    {
        $this->assertBelongsToCurrentOrganization($user);

        return response()->json([
            'data' => $user->tokens()->select(['id', 'name', 'abilities', 'last_used_at', 'created_at', 'expires_at'])->get(),
        ]);
    }

    public function revokeToken(User $user, int $tokenId): JsonResponse
    {
        $this->assertBelongsToCurrentOrganization($user);

        $deleted = $user->tokens()->where('id', $tokenId)->delete();

        if (! $deleted) {
            return response()->json([
                'message' => 'Token not found.',
            ], 404);
        }

        return response()->json([
            'message' => 'Token revoked successfully.',
        ]);
    }

    public function createToken(Request $request, User $user): JsonResponse
    {
        $this->assertBelongsToCurrentOrganization($user);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // No default: a holder of tokens.revoke (a support/admin permission)
            // must explicitly opt in to minting a full-access ('*') token for
            // another user rather than getting one silently by omitting this.
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string'],
        ]);

        $token = $user->createToken(
            $validated['name'],
            $validated['abilities']
        );

        return response()->json([
            'message' => 'Token created successfully.',
            'token' => $token->plainTextToken,
        ], 201);
    }

    public function rolesAndPermissionsCatalog(): JsonResponse
    {
        return response()->json([
            'roles' => Role::query()->orderBy('name')->get(['id', 'name', 'guard_name']),
            'permissions' => Permission::query()->orderBy('name')->get(['id', 'name', 'guard_name']),
        ]);
    }

    // These endpoints are gated by global Spatie permissions (users.view,
    // users.update, tokens.revoke, ...) which say nothing about which
    // organization the caller belongs to — a users.update holder in one org
    // could otherwise read/modify/delete a user in a completely different
    // org just by guessing an id. Mirrors the isMemberOf() membership check
    // SocialAccountController::authorizeTargetUserCapability() already
    // applies to this same User model for its sub-resources.
    private function assertBelongsToCurrentOrganization(User $user): void
    {
        if (! $user->isMemberOf(app(TenantContext::class)->get())) {
            abort(404, 'The requested user is not a member of the current organization.');
        }
    }
}
