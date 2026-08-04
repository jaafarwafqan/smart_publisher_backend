<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_attachments', function (Blueprint $table) {
            $table->json('tags')->nullable();
            $table->string('content_hash')->nullable();

            $table->index(['user_id', 'content_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('media_attachments', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'content_hash']);
            $table->dropColumn(['tags', 'content_hash']);
        });
    }
};
