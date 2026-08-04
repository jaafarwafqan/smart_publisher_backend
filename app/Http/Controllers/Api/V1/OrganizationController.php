<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lets a multi-membership user see which organizations they belong to and
 * switch their active one. Deliberately does NOT let the client set
 * organization_id directly on anything else — this is the one sanctioned
 * place a request can influence TenantContext, and only after verifying a
 * real, active membership exists (see OrganizationMembership + User::isMemberOf()).
 */
class OrganizationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $memberships = $user->memberships()
            ->with('organization:id,name,slug')
            ->where('status', 'active')
            ->get();

        return response()->json([
            'data' => $memberships->map(fn ($membership) => [
                'id' => $membership->organization->id,
                'name' => $membership->organization->name,
                'slug' => $membership->organization->slug,
                'role' => $membership->role->value,
                'is_current' => $membership->organization_id === $user->current_organization_id,
            ])->values(),
        ]);
    }

    public function switch(Request $request, Organization $organization): JsonResponse
    {
        $user = $request->user();

        if (! $user->isMemberOf($organization->id)) {
            return response()->json([
                'message' => 'You are not a member of this organization.',
            ], 403);
        }

        $user->forceFill(['current_organization_id' => $organization->id])->save();

        return response()->json([
            'message' => 'Active organization switched.',
            'data' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'role' => $user->roleIn($organization->id)->value,
            ],
        ]);
    }
}
