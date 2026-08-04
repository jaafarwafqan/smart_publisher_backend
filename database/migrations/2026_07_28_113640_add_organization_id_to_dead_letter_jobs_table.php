<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * dead_letter_jobs had no organization_id at all — meaning any user holding
 * the (global, non-tenant) publishing.monitor/manage Spatie permission
 * could see and retry every organization's dead letters through
 * PublishingController::deadLetters()/retryDeadLetter(). Directly violates
 * the Sprint 3 acceptance criterion that switching organizations must never
 * reveal another organization's attempts or errors. Backfilled from the
 * referenced post_publication_attempt (the common case) or post (the rare
 * PublishPostJob::failed() safety-net case), then locked NOT NULL.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('dead_letter_jobs', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->after('job_class')->constrained()->cascadeOnDelete();
        });

        DB::table('dead_letter_jobs')
            ->where('reference_type', 'post_publication_attempt')
            ->update([
                'organization_id' => DB::raw(
                    '(select post_publication_attempts.organization_id from post_publication_attempts where post_publication_attempts.id = dead_letter_jobs.reference_id)'
                ),
            ]);

        DB::table('dead_letter_jobs')
            ->where('reference_type', 'post')
            ->update([
                'organization_id' => DB::raw(
                    '(select posts.organization_id from posts where posts.id = dead_letter_jobs.reference_id)'
                ),
            ]);

        // Any row that still couldn't be resolved (unrecognized
        // reference_type, or the referenced row was itself already
        // deleted) has nothing meaningful left to scope it by — safe to
        // drop rather than leave orphaned and unreachable through any
        // organization-scoped query.
        DB::table('dead_letter_jobs')->whereNull('organization_id')->delete();

        Schema::table('dead_letter_jobs', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id')->nullable(false)->change();
            $table->index(['organization_id', 'failed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dead_letter_jobs', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'failed_at']);
            $table->dropConstrainedForeignId('organization_id');
        });
    }
};
