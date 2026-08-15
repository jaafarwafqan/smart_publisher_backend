<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\IndexPlatformOrganizationsRequest;
use App\Http\Requests\Platform\StorePlatformOrganizationRequest;
use App\Http\Requests\Platform\UpdatePlatformOrganizationRequest;
use App\Http\Requests\Platform\UpdatePlatformOrganizationStatusRequest;
use App\Http\Resources\PlatformOrganizationResource;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Plan;
use App\Models\Post;
use App\Models\Scopes\OrganizationScope;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Billing\DefaultPlans;
use App\Support\Organizations\OrganizationOwnershipService;
use App\Support\Platform\PlatformAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminOrganizationController extends Controller
{
    public function index(IndexPlatformOrganizationsRequest $request): JsonResponse
    {
        $query = $this->organizationQuery();
        $validated = $request->validated();

        if ($search = $validated['search'] ?? null) {
            $query->where(function ($organizationQuery) use ($search): void {
                $organizationQuery->where('organizations.name', 'like', "%{$search}%")
                    ->orWhereHas('memberships.user', fn ($userQuery) => $userQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"));
            });
        }

        if ($status = $validated['status'] ?? null) {
            $query->where('organizations.status', $status);
        }

        $sort = $validated['sort'] ?? 'created_at';
        $direction = $validated['direction'] ?? 'desc';
        $query->orderBy($sort === 'members_count' ? 'members_count' : "organizations.{$sort}", $direction);

        $paginator = $query->paginate($validated['per_page'] ?? 20)->withQueryString();

        return response()->json([
            'data' => PlatformOrganizationResource::collection($paginator->getCollection())->resolve($request),
            'meta' => $this->pagination($paginator),
        ]);
    }

    public function store(StorePlatformOrganizationRequest $request, PlatformAuditLogger $audit, OrganizationOwnershipService $ownership): JsonResponse
    {
        $validated = $request->validated();

        $organization = DB::transaction(function () use ($validated, $request, $audit, $ownership): Organization {
            $owner = isset($validated['owner_user_id'])
                ? User::query()->whereKey($validated['owner_user_id'])->where('is_active', true)->lockForUpdate()->firstOrFail()
                : User::withoutPersonalOrganizationProvisioning(fn () => User::query()->create([
                    'name' => $validated['new_owner']['name'],
                    'email' => $validated['new_owner']['email'],
                    'password' => Hash::make($validated['new_owner']['password']),
                    'is_active' => true,
                ]));

            $organization = Organization::query()->create([
                'name' => $validated['name'],
                'slug' => $this->uniqueSlug($validated['name']),
                'status' => $validated['status'] ?? 'active',
            ]);

            $ownerMembership = OrganizationMembership::query()->create([
                'organization_id' => $organization->id,
                'user_id' => $owner->id,
                'role' => OrganizationRole::Owner,
                'status' => 'active',
            ]);

            $ownership->assign($organization, $ownerMembership);

            // An organization created here bypasses PersonalOrganizationProvisioner
            // (the owner already exists, or is deliberately created without
            // model-event provisioning above), so it must independently
            // guarantee the same Free-plan subscription that path assigns —
            // otherwise this organization has no subscription row at all and
            // OrganizationEntitlements::hasCapacityFor() treats that as
            // unlimited, silently bypassing every quota for an org created
            // straight from the platform admin panel.
            $freePlan = Plan::query()->firstOrCreate(
                ['slug' => DefaultPlans::FREE_SLUG],
                DefaultPlans::free(),
            );
            $organization->subscription()->create([
                'plan_id' => $freePlan->id,
                'status' => 'active',
                'current_period_start' => now(),
            ]);

            $audit->record($request, $request->user(), 'organization.created', Organization::class, $organization->id, null, [
                'name' => $organization->name,
                'status' => $organization->status,
                'owner_user_id' => $owner->id,
            ]);

            return $organization;
        });

        return response()->json([
            'message' => 'Organization created.',
            'data' => (new PlatformOrganizationResource($this->organizationQuery()->findOrFail($organization->id)))->resolve($request),
        ], 201);
    }

    public function show(Organization $organization, Request $request, PlatformAuditLogger $audit): JsonResponse
    {
        $organization = $this->organizationQuery()->findOrFail($organization->id);
        $members = OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->with('user:id,name,email,is_active,is_super_admin,last_login_at')
            ->orderBy('created_at')
            ->get();
        $socialAccounts = SocialAccount::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organization->id)
            ->get(['id', 'provider', 'account_name', 'account_username', 'is_active', 'status', 'last_synced_at']);
        $postSummary = Post::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organization->id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        // Sprint G (role/permission remediation): previously only
        // super_admin WRITES to another organization's data were audited —
        // a super_admin reading a full organization's members and
        // (non-secret) social account list left no trace at all. Logged as
        // a read, not tied to any specific field change.
        $audit->record(
            $request,
            $request->user(),
            'organization.viewed',
            Organization::class,
            $organization->id,
            null,
            null,
            $organization->id,
        );

        return response()->json([
            'data' => [
                'organization' => (new PlatformOrganizationResource($organization))->resolve($request),
                'members' => $members->map(fn (OrganizationMembership $membership) => [
                    'user' => [
                        'id' => $membership->user->id,
                        'name' => $membership->user->name,
                        'email' => $membership->user->email,
                        'is_active' => $membership->user->is_active,
                        'is_super_admin' => $membership->user->isSuperAdmin(),
                    ],
                    'role' => $membership->role->value,
                    'status' => $membership->status,
                ])->values(),
                'social_accounts' => $socialAccounts,
                'posts_summary' => $postSummary,
            ],
        ]);
    }

    public function update(UpdatePlatformOrganizationRequest $request, Organization $organization, PlatformAuditLogger $audit): JsonResponse
    {
        $oldValues = ['name' => $organization->name];
        $organization->update($request->validated());
        $audit->record($request, $request->user(), 'organization.updated', Organization::class, $organization->id, $oldValues, ['name' => $organization->name]);

        return response()->json([
            'message' => 'Organization updated.',
            'data' => (new PlatformOrganizationResource($this->organizationQuery()->findOrFail($organization->id)))->resolve($request),
        ]);
    }

    public function updateStatus(UpdatePlatformOrganizationStatusRequest $request, Organization $organization, PlatformAuditLogger $audit): JsonResponse
    {
        $oldValues = ['status' => $organization->status];
        $organization->update($request->validated());
        $audit->record($request, $request->user(), 'organization.status_updated', Organization::class, $organization->id, $oldValues, ['status' => $organization->status]);

        return response()->json([
            'message' => 'Organization status updated.',
            'data' => (new PlatformOrganizationResource($this->organizationQuery()->findOrFail($organization->id)))->resolve($request),
        ]);
    }

    /**
     * Fixes a "no primary owner" organization by re-deriving it from
     * current membership data (see OrganizationOwnershipService::reconcile).
     * If no eligible owner membership exists at all, primary_owner_id ends
     * up null again and the response says so explicitly — this endpoint
     * cannot invent an owner, only re-point the designation at one that
     * already qualifies; a genuinely ownerless organization still needs a
     * human to grant someone the owner role first.
     */
    public function reconcilePrimaryOwner(
        Organization $organization,
        Request $request,
        PlatformAuditLogger $audit,
        OrganizationOwnershipService $ownership,
    ): JsonResponse {
        $oldPrimaryOwnerId = $organization->primary_owner_id;

        DB::transaction(function () use ($organization, $ownership): void {
            $ownership->reconcile($organization->id);
        });

        $resolved = $this->organizationQuery()->findOrFail($organization->id);

        $audit->record(
            $request,
            $request->user(),
            'organization.primary_owner_reconciled',
            Organization::class,
            $organization->id,
            ['primary_owner_id' => $oldPrimaryOwnerId],
            ['primary_owner_id' => $resolved->primary_owner_id],
            $organization->id,
        );

        return response()->json([
            'message' => $resolved->primary_owner_id !== null
                ? 'Primary owner reassigned.'
                : 'No eligible owner membership was found — assign the owner role to a member first.',
            'data' => (new PlatformOrganizationResource($resolved))->resolve($request),
        ]);
    }

    private function organizationQuery()
    {
        return Organization::query()
            ->addSelect([
                'last_activity_at' => Post::withoutGlobalScope(OrganizationScope::class)
                    ->selectRaw('MAX(updated_at)')
                    ->whereColumn('organization_id', 'organizations.id'),
            ])
            ->with(['primaryOwner.user:id,name,email'])
            ->withCount([
                'memberships as members_count' => fn ($query) => $query
                    ->where('status', 'active')
                    ->whereHas('user', fn ($userQuery) => $userQuery->where('is_active', true)),
            ]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'organization';
        $slug = $base;
        $suffix = 2;

        while (Organization::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    private function pagination($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}
