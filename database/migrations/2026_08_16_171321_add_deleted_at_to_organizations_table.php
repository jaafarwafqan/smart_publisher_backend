<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Platform-admin organization deletion (2026-08) is deliberately a soft
     * delete, not a hard one: an organization's posts/media/social accounts/
     * memberships stay in the database (data recoverable, matches the
     * operator's own stated preference for this feature), and the row is
     * simply excluded from every normal query going forward via Eloquent's
     * SoftDeletes global scope. Gated by a real precondition
     * (AdminOrganizationController::destroy() requires status 'inactive'
     * first) rather than being reachable from an active organization.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
