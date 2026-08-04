<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for manual DLQ retries — "من قام بالإعادة ومتى" (who retried
 * and when), per the Sprint 3 acceptance criterion that manual retry from
 * the dead-letter queue must be permission-gated AND audited.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('dead_letter_jobs', function (Blueprint $table): void {
            $table->timestamp('retried_at')->nullable()->after('failed_at');
            $table->foreignId('retried_by')->nullable()->after('retried_at')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dead_letter_jobs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('retried_by');
            $table->dropColumn('retried_at');
        });
    }
};
