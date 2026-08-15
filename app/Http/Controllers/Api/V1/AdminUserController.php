<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\IndexPlatformUsersRequest;
use App\Http\Requests\Platform\StorePlatformUserRequest;
use App\Http\Requests\Platform\SyncPlatformUserMembershipsRequest;
use App\Http\Requests\Platform\UpdatePlatformRoleRequest;
use App\Http\Requests\Platform\UpdatePlatformUserRequest;
use App\Http\Requests\Platform\UpdatePlatformUserStatusRequest;
use App\Http\Resources\PlatformUserResource;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Support\Organizations\OrganizationOwnershipService;
use App\Support\Platform\PlatformAdministrationGuard;
use App\Support\Platform\PlatformAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminUserController extends Controller
{
    public function index(IndexPlatformUsersRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $query = User::query()->with('memberships.organization');

        if ($search = $validated['search'] ?? null) {
            $query->where(fn ($userQuery) => $userQuery
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->has('is_super_admin')) {
            $query->where('is_super_admin', $request->boolean('is_super_admin'));
        }

        if ($organizationId = $validated['organization_id'] ?? null) {
            $query->whereHas('memberships', fn ($membershipQuery) => $membershipQuery->where('organization_id', $organizationId));
        }

        if ($role = $validated['membership_role'] ?? null) {
            $query->whereHas('memberships', fn ($membershipQuery) => $membershipQuery->where('role', $role));
        }

        $paginator = $query->latest()->paginate($validated['per_page'] ?? 20)->withQueryString();

        return response()->json([
            'data' => PlatformUserResource::collection($paginator->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(StorePlatformUserRequest $request, PlatformAuditLogger $audit): JsonResponse
    {
        $validated = $request->validated();

        $user = DB::transaction(function () use ($validated, $request, $audit): User {
            $user = User::withoutPersonalOrganizationProvisioning(fn () => User::query()->create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'is_active' => true,
            ]));

            if (isset($validated['organization_id'])) {
                OrganizationMembership::query()->create([
                    'organization_id' => $validated['organization_id'],
                    'user_id' => $user->id,
                    'role' => OrganizationRole::from($validated['membership_role']),
                    'status' => 'active',
                ]);
            }

            $audit->record($request, $request->user(), 'user.created', User::class, $user->id, null, [
                'name' => $user->name,
                'email' => $user->email,
                'organization_id' => $validated['organization_id'] ?? null,
                'membership_role' => $validated['membership_role'] ?? null,
            ]);

            return $user;
        });

        return response()->json([
            'message' => 'User created.',
            'data' => (new PlatformUserResource($this->userQuery()->findOrFail($user->id)))->resolve($request),
        ], 201);
    }

    public function show(User $user, Request $request): JsonResponse
    {
        return response()->json([
            'data' => (new PlatformUserResource($this->userQuery()->findOrFail($user->id)))->resolve($request),
        ]);
    }

    public function update(UpdatePlatformUserRequest $request, User $user, PlatformAuditLogger $audit): JsonResponse
    {
        $oldValues = $user->only(['name', 'email']);
        $user->update($request->validated());
        $audit->record($request, $request->user(), 'user.updated', User::class, $user->id, $oldValues, $user->only(['name', 'email']));

        return response()->json([
            'message' => 'User updated.',
            'data' => (new PlatformUserResource($this->userQuery()->findOrFail($user->id)))->resolve($request),
        ]);
    }

    public function syncMemberships(
        SyncPlatformUserMembershipsRequest $request,
        User $user,
        PlatformAdministrationGuard $guard,
        PlatformAuditLogger $audit,
    ): JsonResponse {
        $requested = collect($request->validated('memberships'))
            ->keyBy(fn (array $membership) => (int) $membership['organization_id']);

        DB::transaction(function () use ($requested, $user, $guard, $audit, $request): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $existing = OrganizationMembership::query()
                ->where('user_id', $lockedUser->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('organization_id');
            $affectedOrganizationIds = $existing->keys()->merge($requested->keys())->map(fn ($id) => (int) $id)->all();
            $oldValues = $this->membershipAuditPayload($existing->values());

            foreach ($existing as $organizationId => $membership) {
                if (! $requested->has((int) $organizationId)) {
                    $membership->delete();

                    continue;
                }

                $input = $requested->get((int) $organizationId);
                $membership->update([
                    'role' => OrganizationRole::from($input['role']),
                    'status' => $input['status'] ?? 'active',
                ]);
            }

            foreach ($requested as $organizationId => $input) {
                if ($existing->has((int) $organizationId)) {
                    continue;
                }

                OrganizationMembership::query()->create([
                    'organization_id' => (int) $organizationId,
                    'user_id' => $lockedUser->id,
                    'role' => OrganizationRole::from($input['role']),
                    'status' => $input['status'] ?? 'active',
                ]);
            }

            // Evaluate after all inserts/removals/demotions under the same
            // transaction locks so parallel edits cannot leave an ownerless org.
            $guard->assertOrganizationsRetainActiveOwner($affectedOrganizationIds);

            $ownership = app(OrganizationOwnershipService::class);
            foreach ($affectedOrganizationIds as $affectedOrganizationId) {
                $ownership->reconcile($affectedOrganizationId);
            }

            $current = OrganizationMembership::query()->where('user_id', $lockedUser->id)->get();
            $audit->record($request, $request->user(), 'user.memberships_synced', User::class, $lockedUser->id, $oldValues, [
                'memberships' => $this->membershipAuditPayload($current),
            ]);
        });

        return response()->json([
            'message' => 'User memberships updated.',
            'data' => (new PlatformUserResource($this->userQuery()->findOrFail($user->id)))->resolve($request),
        ]);
    }

    public function updatePlatformRole(
        UpdatePlatformRoleRequest $request,
        User $user,
        PlatformAdministrationGuard $guard,
        PlatformAuditLogger $audit,
    ): JsonResponse {
        DB::transaction(function () use ($request, $user, $guard, $audit): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $oldValue = $lockedUser->isSuperAdmin();
            $lockedUser->update(['is_super_admin' => $request->boolean('is_super_admin')]);
            $guard->assertAtLeastOneActiveSuperAdmin();
            $audit->record($request, $request->user(), 'user.platform_role_updated', User::class, $lockedUser->id, ['is_super_admin' => $oldValue], ['is_super_admin' => $lockedUser->isSuperAdmin()]);
        });

        return response()->json([
            'message' => 'Platform role updated.',
            'data' => (new PlatformUserResource($this->userQuery()->findOrFail($user->id)))->resolve($request),
        ]);
    }

    public function updateStatus(
        UpdatePlatformUserStatusRequest $request,
        User $user,
        PlatformAdministrationGuard $guard,
        PlatformAuditLogger $audit,
    ): JsonResponse {
        DB::transaction(function () use ($request, $user, $guard, $audit): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $ownerOrganizationIds = OrganizationMembership::query()
                ->where('user_id', $lockedUser->id)
                ->where('role', OrganizationRole::Owner)
                ->where('status', 'active')
                ->pluck('organization_id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $oldValue = (bool) $lockedUser->is_active;
            $lockedUser->update(['is_active' => $request->boolean('is_active')]);

            if (! $lockedUser->is_active) {
                $lockedUser->tokens()->delete();
            }

            $guard->assertAtLeastOneActiveSuperAdmin();
            $guard->assertOrganizationsRetainActiveOwner($ownerOrganizationIds);

            $ownership = app(OrganizationOwnershipService::class);
            foreach ($ownerOrganizationIds as $ownerOrganizationId) {
                $ownership->reconcile($ownerOrganizationId);
            }

            $audit->record($request, $request->user(), 'user.status_updated', User::class, $lockedUser->id, ['is_active' => $oldValue], ['is_active' => (bool) $lockedUser->is_active]);
        });

        return response()->json([
            'message' => 'User status updated.',
            'data' => (new PlatformUserResource($this->userQuery()->findOrFail($user->id)))->resolve($request),
        ]);
    }

    private function userQuery()
    {
        return User::query()->with('memberships.organization');
    }

    /** @param iterable<OrganizationMembership> $memberships */
    private function membershipAuditPayload(iterable $memberships): array
    {
        $values = [];
        foreach ($memberships as $membership) {
            $values[] = [
                'organization_id' => (int) $membership->organization_id,
                'role' => $membership->role->value,
                'status' => (string) $membership->status,
            ];
        }

        return ['memberships' => $values];
    }
}
