<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // The user's last-selected organization — a convenience default
            // only. It is never trusted as-is for authorization; every
            // request re-verifies an active OrganizationMembership exists
            // before TenantContext accepts it (see App\Support\Tenancy).
            $table->foreignId('current_organization_id')
                ->nullable()
                ->after('branch_id')
                ->constrained('organizations')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('current_organization_id');
        });
    }
};
