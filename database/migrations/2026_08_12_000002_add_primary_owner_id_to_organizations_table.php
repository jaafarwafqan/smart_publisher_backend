<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes "who is the primary owner of this organization" an explicit,
 * persisted fact instead of something inferred on every read via
 * Organization::activeOwner() (role=owner AND status=active AND
 * user.is_active — three conditions that can silently all stop matching,
 * e.g. if the owner's user account is deactivated through a code path that
 * doesn't also re-derive ownership, leaving the platform admin panel
 * reporting "no active owner" while an Owner membership row still exists).
 *
 * primary_owner_id points at the OrganizationMembership row that holds
 * primary ownership. It is kept correct going forward by
 * App\Support\Organizations\OrganizationOwnershipService, called from every
 * code path that can affect who owns an organization (creation, membership
 * role changes/removal, membership sync, user activation toggles) — see
 * that class for the invariant it maintains. This migration only adds the
 * column and backfills existing rows from today's data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->foreignId('primary_owner_id')
                ->nullable()
                ->after('status')
                ->constrained('organization_memberships')
                ->nullOnDelete();
        });

        $this->backfillPrimaryOwners();
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('primary_owner_id');
        });
    }

    /**
     * One-time convergence for rows that predate this column. Picks the
     * earliest-created eligible (role=owner, status=active, user active)
     * membership per organization — the "founding owner" — as a stable,
     * deterministic default. Organizations with no eligible owner are left
     * null; the platform UI now surfaces that explicitly instead of
     * papering over it, and a super admin can fix it from there.
     */
    private function backfillPrimaryOwners(): void
    {
        DB::table('organizations')
            ->select('id')
            ->orderBy('id')
            ->chunkById(200, function ($organizations): void {
                foreach ($organizations as $organization) {
                    $membership = DB::table('organization_memberships')
                        ->join('users', 'users.id', '=', 'organization_memberships.user_id')
                        ->where('organization_memberships.organization_id', $organization->id)
                        ->where('organization_memberships.role', 'owner')
                        ->where('organization_memberships.status', 'active')
                        ->where('users.is_active', true)
                        ->orderBy('organization_memberships.created_at')
                        ->orderBy('organization_memberships.id')
                        ->select('organization_memberships.id')
                        ->first();

                    if ($membership !== null) {
                        DB::table('organizations')
                            ->where('id', $organization->id)
                            ->update(['primary_owner_id' => $membership->id]);
                    }
                }
            });
    }
};
