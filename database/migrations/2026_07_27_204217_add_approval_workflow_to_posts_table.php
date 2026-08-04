<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 2 product decision: an editor's schedule/publish request doesn't
 * execute directly — it's held pending manager/admin/owner approval (see
 * PostController::canPublishDirectly()). approval_status is null for posts
 * that never needed approval (created by a role that can publish directly).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->string('approval_status')->nullable()->after('status'); // null|pending|approved|rejected
            $table->string('approval_requested_action')->nullable()->after('approval_status'); // schedule|publish_now
            $table->timestamp('approval_requested_scheduled_at')->nullable()->after('approval_requested_action');
            $table->foreignId('approved_by')->nullable()->after('approval_requested_scheduled_at')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->text('approval_note')->nullable()->after('approved_at');

            $table->index(['organization_id', 'approval_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'approval_status']);
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn([
                'approval_status',
                'approval_requested_action',
                'approval_requested_scheduled_at',
                'approved_at',
                'approval_note',
            ]);
        });
    }
};
