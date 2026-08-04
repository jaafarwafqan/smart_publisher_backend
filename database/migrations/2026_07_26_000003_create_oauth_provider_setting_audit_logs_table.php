<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_provider_setting_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->json('changed_fields')->nullable();
            $table->boolean('success')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('provider');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_provider_setting_audit_logs');
    }
};
