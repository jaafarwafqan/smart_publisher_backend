<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Same idempotency mechanism as posts (see the posts migration's docblock) —
 * the client's stable local media id, sent as the Idempotency-Key header,
 * lets MediaLibraryController::store recognize an already-completed upload
 * instead of storing the file a second time when a retry/outbox-replay
 * follows a response that never made it back to the client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_attachments', function (Blueprint $table): void {
            $table->string('idempotency_key')->nullable()->after('organization_id');
            $table->unique(['organization_id', 'idempotency_key'], 'media_attachments_org_idempotency_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('media_attachments', function (Blueprint $table): void {
            $table->dropUnique('media_attachments_org_idempotency_key_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
