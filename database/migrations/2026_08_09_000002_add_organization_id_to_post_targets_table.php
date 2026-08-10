<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint H (role/permission remediation, 2026-08-09): post_targets was the
 * last tenant-owned table with no materialized organization_id at all —
 * every other one already carries it (post_publication_attempts got the
 * same nullable->backfill->NOT NULL->index treatment in
 * 2026_07_28_103549_extend_post_publication_attempts_for_sprint3_reliability,
 * repeated here verbatim). Defense in depth only: PostController's
 * ownedPageIds() already prevents cross-org page selection via SocialPage's
 * own OrganizationScope, and this migration does not change that
 * authorization path — it closes the gap where a future query joining/
 * filtering post_targets directly (without going through posts) would have
 * had no organization column to scope on. See PostTarget::class (now a
 * genuine Eloquent Pivot via Post::socialPages()->using(...)) for how new
 * rows get this column stamped automatically going forward.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('post_targets', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->after('social_page_id')->constrained()->cascadeOnDelete();
        });

        // A correlated subquery (not a JOIN...UPDATE) — SQLite has no
        // UPDATE...JOIN syntax, unlike MySQL, so this must stay portable
        // (same reasoning as the post_publication_attempts backfill above).
        DB::table('post_targets')
            ->whereNull('organization_id')
            ->update([
                'organization_id' => DB::raw(
                    '(select posts.organization_id from posts where posts.id = post_targets.post_id)'
                ),
            ]);

        Schema::table('post_targets', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id')->nullable(false)->change();
            $table->index(['organization_id', 'post_id'], 'post_targets_org_post_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('post_targets', function (Blueprint $table): void {
            $table->dropIndex('post_targets_org_post_idx');
            $table->dropConstrainedForeignId('organization_id');
        });
    }
};
