<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds organization_id (nullable for now — backfilled in the very next
 * migration, then locked to NOT NULL in enforce_organization_id_constraints)
 * to every tenant-owned resource named in the CTO audit's Sprint 1 scope:
 * posts, social_accounts, social_pages, media_attachments. "Schedules" and
 * "analytics" are covered by posts/post_metrics — there is no separate
 * schedules table (scheduling is posts.status/scheduled_at) and analytics is
 * computed from posts + post_metrics, not stored independently. Notifications
 * has no table yet at all (still a 100% facade per the Round 2 audit finding
 * — pre-existing, unrelated to this migration, tracked separately in
 * docs/audit/REMEDIATION_TRACKER.md). OAuth provider settings are
 * deliberately NOT made org-scoped: they are the platform's own single App
 * ID/Secret registered once with each external provider (Facebook, Telegram,
 * etc.) and shared across every tenant that connects an account — making
 * them per-organization would require every tenant to register their own
 * OAuth app with every platform, which is not this product's model.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
        });

        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
        });

        Schema::table('social_pages', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->after('social_account_id')->constrained()->cascadeOnDelete();
        });

        Schema::table('media_attachments', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
        });

        Schema::table('post_metrics', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->after('post_id')->constrained()->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['posts', 'social_accounts', 'social_pages', 'media_attachments', 'post_metrics'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('organization_id');
            });
        }
    }
};
