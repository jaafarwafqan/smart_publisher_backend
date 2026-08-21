<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Support\Billing\OrganizationEntitlements;
use App\Support\Billing\QuotaGates;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function index(): JsonResponse
    {
        $this->assertBranchesFeatureEnabled();

        return response()->json([
            'data' => Branch::query()->latest()->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertBranchesFeatureEnabled();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'unique:branches,code'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $branch = Branch::query()->create($validated);

        return response()->json([
            'message' => 'Branch created successfully.',
            'data' => $branch,
        ], 201);
    }

    public function show(Branch $branch): JsonResponse
    {
        $this->assertBranchesFeatureEnabled();

        return response()->json([
            'data' => $branch,
        ]);
    }

    public function update(Request $request, Branch $branch): JsonResponse
    {
        $this->assertBranchesFeatureEnabled();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:50', 'unique:branches,code,'.$branch->id],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $branch->update($validated);

        return response()->json([
            'message' => 'Branch updated successfully.',
            'data' => $branch,
        ]);
    }

    public function destroy(Branch $branch): JsonResponse
    {
        $this->assertBranchesFeatureEnabled();

        $branch->delete();

        return response()->json([
            'message' => 'Branch deleted successfully.',
        ]);
    }

    /**
     * 2026-08 feature-gates review: branches used to be free on every plan,
     * including an organization with no subscription at all. Branch itself
     * has no organization_id of its own (a platform-wide/institutional
     * concept, not a per-tenant row) — the gate is on the CALLER's current
     * organization via TenantContext, same as every other tenant-scoped
     * capability check in this codebase.
     */
    private function assertBranchesFeatureEnabled(): void
    {
        app(OrganizationEntitlements::class)->assertFeatureEnabled(
            app(TenantContext::class)->get(),
            QuotaGates::FEATURE_BRANCHES,
            'Branches are not available on your organization\'s current plan.',
        );
    }
}
