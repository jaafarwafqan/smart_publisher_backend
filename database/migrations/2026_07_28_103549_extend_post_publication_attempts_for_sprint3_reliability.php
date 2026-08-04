<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 3 (Publishing Core) reliability fields:
 *  - organization_id: was missing entirely — a carried-forward Sprint 1/2
 *    backlog item, addressed here as the user explicitly suggested folding
 *    it into this sprint. Backfilled from the linked post, then locked NOT
 *    NULL, matching the same nullable->backfill->NOT NULL pattern used for
 *    every other tenant table.
 *  - claimed_at/claimed_by: the atomic-claim mechanism (see
 *    AttemptStateMachine::claim()) — two workers racing to process the same
 *    attempt row must have only one succeed.
 *  - next_attempt_at: when a retry-scheduled attempt becomes due; the
 *    scheduled sweeper (RetryDuePublishAttemptsJob) queries on this.
 *  - error_classification: retryable|non_retryable|unknown, set by
 *    PublishErrorClassifier — drives whether a failure gets another attempt
 *    or goes straight to dead_letter.
 *  - provider_request_id: the provider's own post/message id when the call
 *    actually reached them (useful for audit even on a later failure, e.g.
 *    a timeout after the platform had already accepted the request).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('post_publication_attempts', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->after('post_id')->constrained()->cascadeOnDelete();
            $table->timestamp('claimed_at')->nullable()->after('status');
            $table->string('claimed_by')->nullable()->after('claimed_at');
            $table->timestamp('next_attempt_at')->nullable()->after('claimed_by');
            $table->string('error_classification')->nullable()->after('error_message');
            $table->string('provider_request_id')->nullable()->after('provider_response');
        });

        // A correlated subquery (not a JOIN...UPDATE) — SQLite has no
        // UPDATE...JOIN syntax, unlike MySQL, so this must stay portable.
        DB::table('post_publication_attempts')
            ->whereNull('organization_id')
            ->update([
                'organization_id' => DB::raw(
                    '(select posts.organization_id from posts where posts.id = post_publication_attempts.post_id)'
                ),
            ]);

        Schema::table('post_publication_attempts', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id')->nullable(false)->change();
            $table->index(['organization_id', 'status', 'next_attempt_at'], 'ppa_org_status_next_attempt_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('post_publication_attempts', function (Blueprint $table): void {
            $table->dropIndex('ppa_org_status_next_attempt_idx');
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn(['claimed_at', 'claimed_by', 'next_attempt_at', 'error_classification', 'provider_request_id']);
        });
    }
};
