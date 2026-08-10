<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint G (role/permission remediation, 2026-08-09): platform_audit_logs
 * previously only recorded super_admin platform-administration actions
 * (organization/user create/update, membership sync, platform-role change).
 * This extends the same table — deliberately not a second table — to also
 * carry organization-scoped events (social account connect/update/test/
 * sync/disconnect/delete, member role changes, post approve/reject), so
 * `GET /organizations/{organization}/audit-logs` and `GET /admin/audit-logs`
 * both read from one source of truth. organization_id is nullable: a
 * genuinely platform-wide event (e.g. a user's platform role changing) has
 * no single owning organization.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_audit_logs', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->after('actor_user_id')
                ->constrained()->nullOnDelete();
            $table->string('request_id')->nullable()->after('correlation_id');
            $table->string('ip_address')->nullable()->after('request_id');

            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('platform_audit_logs', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'created_at']);
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn(['request_id', 'ip_address']);
        });
    }
};
