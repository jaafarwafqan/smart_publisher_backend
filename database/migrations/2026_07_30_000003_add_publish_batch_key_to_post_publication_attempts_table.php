<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_publication_attempts', function (Blueprint $table): void {
            $table->string('publish_batch_key')->nullable()->after('post_id');
            $table->index(
                ['post_id', 'publish_batch_key', 'status'],
                'ppa_post_batch_status_idx',
            );
        });

        // Keep historical attempts associated with the publication intent
        // already recorded on their post. SQLite needs the portable
        // correlated-subquery form rather than a MySQL-only UPDATE JOIN.
        DB::table('post_publication_attempts')
            ->whereNull('publish_batch_key')
            ->update([
                'publish_batch_key' => DB::raw(
                    '(select posts.publish_batch_key from posts where posts.id = post_publication_attempts.post_id)'
                ),
            ]);
    }

    public function down(): void
    {
        Schema::table('post_publication_attempts', function (Blueprint $table): void {
            $table->dropIndex('ppa_post_batch_status_idx');
            $table->dropColumn('publish_batch_key');
        });
    }
};
