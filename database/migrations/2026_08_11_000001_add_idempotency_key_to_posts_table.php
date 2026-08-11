<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A stable client-generated key (the local draft id, which already survives
 * every retry/outbox-replay of a create attempt) lets PostController::store
 * recognize "this exact create request already succeeded" instead of the
 * lost-response-after-commit case silently minting a duplicate draft. See
 * PostController::store()'s idempotency check for the read side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->string('idempotency_key')->nullable()->after('organization_id');
            // Nullable columns don't collide on NULL in a unique index (both
            // MySQL and SQLite treat NULL as distinct per row), so posts
            // created before this column existed, or via any path that
            // never sends a key, are unaffected.
            $table->unique(['organization_id', 'idempotency_key'], 'posts_org_idempotency_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->dropUnique('posts_org_idempotency_key_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
