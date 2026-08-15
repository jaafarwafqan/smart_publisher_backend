<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlatformOrganizationResource;
use App\Http\Resources\PlatformUserResource;
use App\Models\Organization;
use App\Models\Post;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform-only aggregate endpoint. This is deliberately outside the tenant
 * route group; the two explicit scope bypasses below are audited read-only
 * aggregates and cannot be reached by organization users.
 */
class AdminDashboardController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $ownerlessOrganizations = Organization::query()
            ->whereDoesntHave('memberships', fn ($query) => $query
                ->where('role', 'owner')
                ->where('status', 'active')
                ->whereHas('user', fn ($userQuery) => $userQuery->where('is_active', true)))
            ->count();

        $latestOrganizations = $this->organizationQuery()
            ->latest('organizations.created_at')
            ->limit(8)
            ->get();

        $latestUsers = User::query()
            ->with('memberships.organization')
            ->latest()
            ->limit(8)
            ->get();

        return response()->json([
            'data' => [
                'statistics' => [
                    'organizations_total' => Organization::query()->count(),
                    'organizations_active' => Organization::query()->where('status', 'active')->count(),
                    'organizations_inactive' => Organization::query()->where('status', 'inactive')->count(),
                    'users_total' => User::query()->count(),
                    'users_last_30_days' => User::query()->where('created_at', '>=', now()->subDays(30))->count(),
                    'organizations_without_active_owner' => $ownerlessOrganizations,
                ],
                'latest_organizations' => PlatformOrganizationResource::collection($latestOrganizations)->resolve($request),
                'latest_users' => PlatformUserResource::collection($latestUsers)->resolve($request),
            ],
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
}
